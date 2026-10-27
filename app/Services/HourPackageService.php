<?php

namespace App\Services;

use App\Exceptions\InsufficientPackageBalanceException;
use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\MemberPackage;
use App\Models\PackageTemplate;
use App\Models\PackageUsage;
use App\Models\Room;
use App\Models\SharedSession;
use App\Support\Duration;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The only place a member package's balance changes, and every change leaves
 * an immutable PackageUsage row (balance before → after) so the remaining
 * hours are always explainable.
 *
 * Concurrency: consumption is a single guarded UPDATE
 *   … SET used_minutes = used_minutes + m WHERE id = ? AND used_minutes + m <= total_minutes
 * — the same atomic-claim pattern as InventoryService, safe on SQLite (which
 * ignores lockForUpdate) and MySQL. Two bookings racing for the last hours can
 * never both succeed and the balance can never go negative.
 *
 * Revenue is recognised when hours are used: each usage row carries the value
 * of the minutes it moved, computed by telescoping
 *   round(price × usedAfter / total, 2) − round(price × usedBefore / total, 2)
 * so a fully-used package's values always sum to exactly price_paid. A covered
 * booking's total_price = amount_paid = the sum of its own usage values, which
 * is how RevenueAnalyticsService picks it up with no query changes.
 */
class HourPackageService
{
    public function __construct(private NotificationService $notifications) {}

    /**
     * Why $pkg can't cover $minutes in $room on $date (a reason key under
     * app.packages.reasons), or null when it can. $ignore is the booking
     * being edited — the minutes it already holds on this package count as available.
     */
    public function eligibility(MemberPackage $pkg, Room $room, string|Carbon $date, int $minutes, ?int $partySize = null, ?Booking $ignore = null): ?string
    {
        $day = Carbon::parse($date)->startOfDay();

        if ($pkg->cancelled_at) {
            return 'cancelled';
        }
        if ($pkg->starts_on->gt($day)) {
            return 'not_started';
        }
        if ($pkg->expires_on->lt($day)) {
            return 'expired';
        }
        if (! $pkg->allowsRoom($room)) {
            return 'room';
        }
        if ($room->type === 'shared' && $partySize !== null && $partySize > 1) {
            return 'party';
        }
        if ($this->available($pkg, $ignore) < $minutes) {
            return 'insufficient';
        }

        return null;
    }

    /** Remaining minutes, plus whatever $ignore already holds on this package. */
    public function available(MemberPackage $pkg, ?Booking $ignore = null): int
    {
        $held = $ignore?->exists ? $this->heldMinutes($ignore, $pkg->id) : 0;

        return $pkg->remainingMinutes() + $held;
    }

    /** Net minutes $booking currently draws from package $packageId. */
    public function heldMinutes(Booking $booking, int $packageId): int
    {
        return (int) PackageUsage::where('booking_id', $booking->id)
            ->where('member_package_id', $packageId)
            ->sum('minutes');
    }

    /** Revenue recognised for $booking on package $packageId (sum of its usage values). */
    public function bookingValue(Booking $booking, int $packageId): float
    {
        return round((float) PackageUsage::where('booking_id', $booking->id)
            ->where('member_package_id', $packageId)
            ->sum('value'), 2);
    }

    /** Draw $minutes. Throws InsufficientPackageBalanceException — the caller's transaction rolls back. */
    public function consume(MemberPackage $pkg, int $minutes, string $action, ?Booking $booking = null, ?SharedSession $session = null, ?string $note = null): PackageUsage
    {
        $affected = MemberPackage::whereKey($pkg->id)
            ->where('owner_id', $pkg->owner_id)
            ->whereNull('cancelled_at')
            ->whereRaw('used_minutes + ? <= total_minutes', [$minutes])
            ->increment('used_minutes', $minutes);

        $pkg->refresh();
        if ($affected === 0) {
            throw new InsufficientPackageBalanceException($pkg->remainingMinutes());
        }

        $usage = $this->record($pkg, $action, $minutes, $booking, $session, $note);

        if ($pkg->remainingMinutes() === 0) {
            $this->notifyExhausted($pkg);
        }

        return $usage;
    }

    /** Give $minutes back (edit shortened the booking, package switched, booking cancelled). */
    public function release(MemberPackage $pkg, int $minutes, string $action, ?Booking $booking = null, ?string $note = null): PackageUsage
    {
        MemberPackage::whereKey($pkg->id)
            ->where('owner_id', $pkg->owner_id)
            ->where('used_minutes', '>=', $minutes)
            ->decrement('used_minutes', $minutes);

        $pkg->refresh();

        return $this->record($pkg, $action, -$minutes, $booking, null, $note);
    }

    /**
     * Make $booking hold exactly $minutes on $pkg (or nothing when $pkg is null):
     * same package → consume/release only the difference; package changed or
     * removed → return everything to the old one, then draw from the new one.
     * Must run inside the caller's DB transaction. Returns the booking's
     * recognised value on $pkg (0 when no package).
     */
    public function reconcileBooking(Booking $booking, ?MemberPackage $pkg, int $minutes): float
    {
        $held = $this->heldByPackage($booking);

        foreach ($held as $packageId => $heldMinutes) {
            if ($pkg && $packageId === $pkg->id) {
                continue;
            }
            if ($heldMinutes > 0 && ($old = MemberPackage::where('owner_id', $booking->owner_id)->find($packageId))) {
                $this->release($old, $heldMinutes, PackageUsage::BOOKING_ADJUSTMENT, $booking);
            }
        }

        if (! $pkg) {
            return 0.0;
        }

        $current = $held[$pkg->id] ?? 0;
        $diff = $minutes - $current;

        if ($diff > 0) {
            $this->consume($pkg, $diff, $current > 0 ? PackageUsage::BOOKING_ADJUSTMENT : PackageUsage::BOOKING_USAGE, $booking);
        } elseif ($diff < 0) {
            $this->release($pkg, -$diff, PackageUsage::BOOKING_ADJUSTMENT, $booking);
        }

        return $this->bookingValue($booking, $pkg->id);
    }

    /**
     * Draw $booking's $minutes from $pkg (or return them when null) and record
     * it as covered: total_price = amount_paid = the package value those hours
     * represent, which is how the revenue is recognised. Shared by one-time
     * bookings (BookingController) and recurring dates (RecurringBookingService).
     * Runs inside the caller's transaction; throws
     * InsufficientPackageBalanceException (nothing written) when it no longer fits.
     */
    public function coverBooking(Booking $booking, ?MemberPackage $pkg, int $minutes): void
    {
        $value = $this->reconcileBooking($booking, $pkg, $pkg ? $minutes : 0);

        if (! $pkg) {
            return;
        }

        $booking->update([
            'payment_method' => Booking::METHOD_PACKAGE,
            'member_package_id' => $pkg->id,
            'total_price' => $value,
            'amount_paid' => $value,
            'payment_status' => Booking::PAYMENT_PAID,
            'pricing_note' => __('app.packages.covered_note', ['name' => $pkg->name, 'hours' => Duration::label($minutes)]),
        ]);
    }

    /**
     * Sell a package to $member from the sale form's validated input (see
     * saleRules()): hours → minutes, a template's room restriction kept.
     * The amount is what the customer paid for the package itself — it is
     * never a payment towards any booking.
     */
    public function sell(HotspotUser $member, array $v, ?PackageTemplate $template = null): MemberPackage
    {
        return $this->assign($member, [
            'name' => $v['name'],
            'total_minutes' => (int) round((float) $v['hours'] * 60),
            'price_paid' => $v['price_paid'],
            'starts_on' => $v['starts_on'],
            'expires_on' => $v['expires_on'],
            // Room restriction is a template term; custom packages cover every room.
            'room_ids' => $template?->room_ids,
            'notes' => $v['notes'] ?? null,
        ], $template);
    }

    /** The package sale form's rules (member profile and the repeating-booking review). */
    public static function saleRules(int $ownerId): array
    {
        return [
            'package_template_id' => ['nullable', 'integer', Rule::exists('package_templates', 'id')->where('owner_id', $ownerId)],
            'name' => 'required|string|max:80',
            'hours' => 'required|numeric|min:0.25|max:10000',
            'price_paid' => 'required|numeric|min:0|max:9999999.99',
            'starts_on' => 'required|date',
            'expires_on' => 'required|date|after_or_equal:starts_on',
            'notes' => 'nullable|string|max:500',
        ];
    }

    /** Booking cancelled: every minute it holds goes back to its package(s). */
    public function releaseBooking(Booking $booking): void
    {
        foreach ($this->heldByPackage($booking) as $packageId => $heldMinutes) {
            if ($heldMinutes > 0 && ($pkg = MemberPackage::where('owner_id', $booking->owner_id)->find($packageId))) {
                $this->release($pkg, $heldMinutes, PackageUsage::BOOKING_CANCELLATION, $booking);
            }
        }
    }

    /**
     * Sell a package to a member. Every term is snapshotted here (from the
     * template or custom input) — later template edits never touch it.
     *
     * @param  array{name: string, total_minutes: int, price_paid: float|string, starts_on: string, expires_on: string, room_ids?: ?array, notes?: ?string}  $data
     */
    public function assign(HotspotUser $member, array $data, ?PackageTemplate $template = null): MemberPackage
    {
        [$actorType, $actorId] = $this->actor();

        $pkg = MemberPackage::create([
            'owner_id' => $member->owner_id,
            'hotspot_user_id' => $member->id,
            'package_template_id' => $template?->id,
            'name' => $data['name'],
            'total_minutes' => (int) $data['total_minutes'],
            'used_minutes' => 0,
            'price_paid' => $data['price_paid'],
            'starts_on' => $data['starts_on'],
            'expires_on' => $data['expires_on'],
            'room_ids' => ! empty($data['room_ids']) ? array_values(array_map('intval', $data['room_ids'])) : null,
            'notes' => $data['notes'] ?? null,
            'created_by_type' => $actorType,
            'created_by_id' => $actorId,
        ]);

        PackageUsage::create([
            'owner_id' => $pkg->owner_id,
            'member_package_id' => $pkg->id,
            'hotspot_user_id' => $pkg->hotspot_user_id,
            'action' => PackageUsage::PURCHASE,
            'minutes' => 0,
            'balance_before' => 0,
            'balance_after' => $pkg->total_minutes,
            'value' => 0,
            'created_by_type' => $actorType,
            'created_by_id' => $actorId,
        ]);

        return $pkg;
    }

    /** Stop the package being used from now on. History and already-covered bookings are kept. */
    public function cancel(MemberPackage $pkg, ?string $reason = null): void
    {
        $pkg->update(['cancelled_at' => now(), 'cancelled_reason' => $reason]);
    }

    /** @return array<int, int> member_package_id => net minutes held by $booking */
    private function heldByPackage(Booking $booking): array
    {
        if (! $booking->exists) {
            return [];
        }

        /** @var Collection<int, int> $rows */
        $rows = PackageUsage::where('booking_id', $booking->id)
            ->selectRaw('member_package_id, SUM(minutes) as held')
            ->groupBy('member_package_id')
            ->pluck('held', 'member_package_id');

        return $rows->map(fn ($m) => (int) $m)->all();
    }

    private function record(MemberPackage $pkg, string $action, int $minutes, ?Booking $booking, ?SharedSession $session, ?string $note): PackageUsage
    {
        [$actorType, $actorId] = $this->actor();

        $usedAfter = $pkg->used_minutes;
        $usedBefore = $usedAfter - $minutes;
        $total = max(1, $pkg->total_minutes);
        $price = (float) $pkg->price_paid;
        $value = round($price * $usedAfter / $total, 2) - round($price * $usedBefore / $total, 2);

        return PackageUsage::create([
            'owner_id' => $pkg->owner_id,
            'member_package_id' => $pkg->id,
            'hotspot_user_id' => $pkg->hotspot_user_id,
            'booking_id' => $booking?->id,
            'shared_session_id' => $session?->id,
            'action' => $action,
            'minutes' => $minutes,
            'balance_before' => $pkg->total_minutes - $usedBefore,
            'balance_after' => $pkg->total_minutes - $usedAfter,
            'value' => round($value, 2),
            'note' => $note,
            'created_by_type' => $actorType,
            'created_by_id' => $actorId,
        ]);
    }

    private function notifyExhausted(MemberPackage $pkg): void
    {
        $pkg->loadMissing('member.owner');
        if (! $pkg->member?->owner) {
            return;
        }

        $this->notifications->notify($pkg->member->owner, [
            'type' => 'package_exhausted',
            'level' => 'warning',
            'reference' => 'pkg_exhausted:'.$pkg->id,
            'title' => __('app.packages.notify.exhausted_title'),
            'body' => __('app.packages.notify.exhausted_body', ['member' => $pkg->member->name, 'name' => $pkg->name]),
            'action_url' => '/users/'.$pkg->hotspot_user_id,
        ]);
    }

    /** @return array{0: ?string, 1: ?int} */
    private function actor(): array
    {
        if ($staff = auth('staff')->user()) {
            return ['staff', $staff->id];
        }
        if ($owner = auth('owner')->user()) {
            return ['owner', $owner->id];
        }

        return [null, null];
    }
}
