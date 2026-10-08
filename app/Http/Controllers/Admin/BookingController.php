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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

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

    public function index(Request $request): View
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

        return view('admin.bookings.index', [
            'bookings' => $bookings,
            'stats' => $stats,
            'owners' => Owner::has('bookings')->orderBy('business_name')->get(['id', 'business_name', 'name']),
            'locations' => $ownerId ? Workspace::where('owner_id', $ownerId)->orderBy('name')->get(['id', 'name']) : collect(),
            'rooms' => $ownerId ? Room::where('owner_id', $ownerId)->when($locationId, fn ($q) => $q->where('workspace_id', $locationId))->orderBy('name')->get(['id', 'name']) : collect(),
            'filters' => compact('status', 'payment', 'type', 'search') + ['owner' => $ownerId, 'location' => $locationId, 'room' => $roomId, 'has_range' => $hasRange],
            'sort' => $sort,
            'dir' => $dir,
            'range' => $range,
        ]);
    }

    public function show(int $booking): View
    {
        $booking = Booking::with([
            'owner:id,business_name,name,email', 'room.workspace', 'hotspotUser', 'coupon', 'pricingProfile',
            'plan', 'memberPackage', 'sale.items', 'sharedSession',
        ])->findOrFail($booking);

        // Owner-scoped: activity entries of this business about this booking.
        $activity = StaffActivityLog::where('owner_id', $booking->owner_id)
            ->where('subject_type', Booking::class)->where('subject_id', $booking->id)
            ->latest('created_at')->take(20)->get();

        return view('admin.bookings.show', compact('booking', 'activity'));
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
