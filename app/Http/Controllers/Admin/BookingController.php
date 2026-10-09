<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesPeriod;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Owner;
use App\Models\Room;
use App\Models\StaffActivityLog;
use App\Models\Workspace;
use App\Services\AnalyticsPeriod;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super Admin → bookings across every business. Read-only. Filters are GET
 * params (linkable from dashboard charts); stats use the shared Booking
 * money scopes so they match the Financials page exactly.
 *
 * Types: booking (reserved slot) · open (open session, no end yet) ·
 * session (walk-in shared seat — the completed booking a SharedSession
 * closes into) · package (paid with prepaid hours).
 */
class BookingController extends Controller
{
    use ResolvesPeriod;

    public const STATUSES = ['pending', 'confirmed', 'checked_in', 'open', 'completed', 'cancelled', 'no_show'];

    public const PAYMENTS = ['paid', 'partial', 'unpaid', 'package', 'due'];

    public const TYPES = ['booking', 'open', 'session', 'package'];

    public const SORTS = ['date' => 'booking_date', 'created' => 'created_at', 'amount' => 'total_price', 'paid' => 'amount_paid', 'id' => 'id'];

    public function index(Request $request): Response
    {
        $hasRange = $request->filled('preset');
        [$period, $range] = $this->resolvePeriod($request, 'last_30');
        $ownerId = Owner::whereKey($request->integer('owner'))->value('id');
        $locationId = $ownerId ? Workspace::where('owner_id', $ownerId)->whereKey($request->integer('location'))->value('id') : null;
        $roomId = $ownerId ? Room::where('owner_id', $ownerId)->whereKey($request->integer('room'))->value('id') : null;
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : null;
        $payment = in_array($request->query('payment'), self::PAYMENTS, true) ? $request->query('payment') : null;
        $type = in_array($request->query('type'), self::TYPES, true) ? $request->query('type') : null;
        $search = trim((string) $request->query('q', ''));
        $sort = array_key_exists($request->query('sort'), self::SORTS) ? $request->query('sort') : 'date';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        // Without an explicit period the list shows everything (matches the old page); the
        // "due" payment filter is always all-time, like the Outstanding figure.
        $filtered = $this->filter(Booking::query(), [
            'period' => $hasRange ? $period : null, 'owner' => $ownerId, 'location' => $locationId, 'room' => $roomId,
            'payment' => $payment, 'type' => $type, 'q' => $search,
        ]);

        $byStatus = (clone $filtered)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $stats = [
            'total' => (int) $byStatus->sum(),
            'by_status' => $byStatus,
            'gbv' => (float) (clone $filtered)->countsTowardGbv()->sum(DB::raw(Booking::GBV_SQL)),
            'collected' => (float) (clone $filtered)->revenueRecognised()->sum('amount_paid'),
            'outstanding' => (float) (clone $filtered)->outstanding()->sum(DB::raw(Booking::OUTSTANDING_SQL)),
        ];

        $bookings = (clone $filtered)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with(['owner:id,business_name,name', 'room:id,name,workspace_id,type', 'room.workspace:id,name', 'hotspotUser:id,name,phone'])
            ->withExists('sharedSession')
            ->orderBy(self::SORTS[$sort], $dir)
            ->when($sort === 'date', fn ($q) => $q->orderBy('start_time', $dir))
            ->orderBy('id', 'desc')
            ->paginate(25)
            ->withQueryString();

        $fmt = fn (?string $d) => $d ? Carbon::parse($d)->translatedFormat('M j, Y') : null;

        return Inertia::render('Admin/Bookings/Index', [
            'bookings' => $bookings->through(fn (Booking $b) => self::tableRow($b)),
            'stats' => $stats,
            'owners' => Owner::has('bookings')->orderBy('business_name')->get(['id', 'business_name', 'name'])
                ->map(fn ($o) => ['id' => $o->id, 'name' => $o->business_name ?: $o->name]),
            'locations' => $ownerId ? Workspace::where('owner_id', $ownerId)->orderBy('name')->get(['id', 'name']) : [],
            'rooms' => $ownerId ? Room::where('owner_id', $ownerId)->when($locationId, fn ($q) => $q->where('workspace_id', $locationId))->orderBy('name')->get(['id', 'name']) : [],
            'filters' => compact('status', 'payment', 'type', 'search') + ['owner' => $ownerId, 'location' => $locationId, 'room' => $roomId, 'has_range' => $hasRange],
            'sort' => $sort,
            'dir' => $dir,
            'range' => $range + ['from_label' => $fmt($range['from']), 'to_label' => $fmt($range['to'])],
            'options' => ['statuses' => self::STATUSES, 'payments' => self::PAYMENTS, 'types' => self::TYPES, 'presets' => self::PRESETS],
        ]);
    }

    /**
     * One row of the admin bookings table (resources/js/Pages/Admin/Bookings/BookingsTable.jsx).
     * Expects owner, room.workspace, hotspotUser loaded (and optionally shared_session_exists).
     *
     * @return array<string, mixed>
     */
    public static function tableRow(Booking $b): array
    {
        return [
            'id' => $b->id,
            'date' => $b->booking_date?->translatedFormat('M j, Y'),
            'time' => $b->timeRange(),
            'customer' => $b->hotspotUser?->name,
            'phone' => $b->hotspotUser?->phone,
            'owner_id' => $b->owner_id,
            'business' => $b->owner?->business_name,
            'room' => $b->room?->name,
            'location' => $b->room?->workspace?->name,
            'status' => $b->status,
            'is_session' => (bool) ($b->shared_session_exists ?? false),
            'payment_tone' => $b->paymentStatusTone(),
            'payment_label' => $b->paymentStatusLabel(),
            'net' => $b->netRoomCharge(),
            'paid' => (float) $b->amount_paid,
        ];
    }

    public function show(int $booking): Response
    {
        $booking = Booking::with([
            'owner:id,business_name,name,email', 'room.workspace', 'hotspotUser', 'coupon', 'pricingProfile',
            'plan', 'memberPackage', 'sale.items', 'sharedSession',
        ])->findOrFail($booking);

        // Owner-scoped: activity entries of this business about this booking.
        $activity = StaffActivityLog::where('owner_id', $booking->owner_id)
            ->where('subject_type', Booking::class)->where('subject_id', $booking->id)
            ->latest('created_at')->take(20)->get();

        $b = $booking;
        $type = $b->status === 'open' ? 'open' : ($b->sharedSession ? 'session' : ($b->isPackageCovered() ? 'package' : 'booking'));

        return Inertia::render('Admin/Bookings/Show', [
            'booking' => [
                'id' => $b->id,
                'status' => $b->status,
                'type' => $type,
                'payment_tone' => $b->paymentStatusTone(),
                'payment_label' => $b->paymentStatusLabel(),
                'created' => $b->created_at?->translatedFormat('M j, Y g:i A'),
                'owner_id' => $b->owner_id,
                'business' => $b->owner?->business_name,
                'location_id' => $b->room?->workspace ? $b->room->workspace_id : null,
                'location' => $b->room?->workspace?->name,
                'room_id' => $b->room ? $b->room_id : null,
                'room' => $b->room?->name,
                'room_type' => $b->room?->typeLabel(),
                'date' => $b->booking_date?->translatedFormat('l, M j, Y'),
                'time' => $b->timeRange(),
                'hours' => $b->total_hours ? rtrim(rtrim(number_format((float) $b->total_hours, 2), '0'), '.') : null,
                'party_size' => $b->party_size ?? 1,
                'checked_in_party_size' => $b->checked_in_party_size,
                'customer' => $b->hotspotUser?->name,
                'phone' => $b->hotspotUser?->phone,
                'session' => $b->sharedSession ? [
                    'opened' => $b->sharedSession->opened_at?->format('g:i A'),
                    'closed' => $b->sharedSession->closed_at?->format('g:i A'),
                    'minutes' => (int) $b->sharedSession->total_minutes,
                ] : null,
                'notes' => $b->notes,
                'pricing_name' => $b->plan?->name ?? $b->pricing_profile_name,
                'price_per_hour' => (float) $b->price_per_hour,
                'pricing_note' => $b->pricing_note,
                'buffer_minutes' => $b->billing_buffer_minutes,
                'total_price' => (float) $b->total_price,
                'discount_total' => (float) $b->discount_total,
                'coupon_code' => $b->coupon?->code,
                'net' => $b->netRoomCharge(),
                'paid' => (float) $b->amount_paid,
                'payment_method' => $b->payment_method ? (Lang::has('app.admin_platform.methods.'.$b->payment_method) ? __('app.admin_platform.methods.'.$b->payment_method) : ucfirst($b->payment_method)) : null,
                'package' => $b->memberPackage?->name,
                'balance_due' => $b->isPackageCovered() || in_array($b->status, ['cancelled', 'no_show']) ? 0 : $b->balanceDue(),
                'sale' => $b->sale && $b->sale->items->isNotEmpty() ? [
                    'status' => $b->sale->status,
                    'total' => (float) $b->sale->total,
                    'items' => $b->sale->items->map(fn ($i) => [
                        'id' => $i->id, 'name' => $i->name, 'quantity' => $i->quantity,
                        'unit_price' => (float) $i->unit_price, 'line_total' => (float) $i->line_total,
                    ])->values(),
                ] : null,
            ],
            'activity' => $activity->map(fn ($a) => [
                'id' => $a->id,
                'iso' => $a->created_at?->toIso8601String(),
                'at' => $a->created_at?->translatedFormat('M j · g:i A'),
                'actor' => $a->actor_name,
                'text' => $a->description ?: $a->action,
            ])->values(),
        ]);
    }

    /** @param array{period: ?AnalyticsPeriod, owner: ?int, location: ?int, room: ?int, payment: ?string, type: ?string, q: string} $f */
    private function filter(Builder $q, array $f): Builder
    {
        return $q
            ->when($f['period'], fn ($w, $p) => $w->whereDate('booking_date', '>=', $p->startDate())->whereDate('booking_date', '<=', $p->endDate()))
            ->when($f['owner'], fn ($w, $id) => $w->where('owner_id', $id))
            ->when($f['location'], fn ($w, $id) => $w->whereIn('room_id', Room::where('workspace_id', $id)->select('id')))
            ->when($f['room'], fn ($w, $id) => $w->where('room_id', $id))
            ->when($f['payment'], fn ($w, $pay) => match ($pay) {
                'package' => $w->where('payment_method', Booking::METHOD_PACKAGE),
                'due' => $w->outstanding(),
                default => $w->where('payment_status', $pay)->where(fn ($m) => $m->whereNull('payment_method')->orWhere('payment_method', '!=', Booking::METHOD_PACKAGE)),
            })
            ->when($f['type'], fn ($w, $type) => match ($type) {
                'open' => $w->where('status', 'open'),
                'session' => $w->has('sharedSession'),
                'package' => $w->where('payment_method', Booking::METHOD_PACKAGE),
                default => $w->where('status', '!=', 'open')->doesntHave('sharedSession'),
            })
            ->when($f['q'] !== '', function ($w) use ($f) {
                $s = ltrim($f['q'], '#');
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%';
                $w->where(function ($x) use ($s, $like) {
                    if (ctype_digit($s)) {
                        $x->orWhere('id', (int) $s);
                    }
                    $x->orWhereHas('hotspotUser', fn ($u) => $u->where('name', 'like', $like)->orWhere('phone', 'like', $like));
                });
            });
    }
}
