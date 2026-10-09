<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesPeriod;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Owner;
use App\Models\Room;
use App\Models\Workspace;
use App\Services\AvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super Admin → every room on the platform, with its business and location.
 * Period figures (bookings, earnings) are aggregate subselects using the
 * shared Booking money scopes.
 */
class RoomController extends Controller
{
    use ResolvesPeriod;

    public const TYPES = ['meeting', 'training', 'shared', 'office'];

    public const SORTS = ['name' => 'name', 'capacity' => 'capacity', 'price' => 'price_per_hour', 'bookings' => 'period_bookings', 'earnings' => 'period_earnings', 'created' => 'created_at'];

    public function index(Request $request): Response
    {
        [$period, $range] = $this->resolvePeriod($request);
        $search = trim((string) $request->query('q', ''));
        $ownerId = Owner::whereKey($request->integer('owner'))->value('id');
        // A location filter only counts when it belongs to the chosen business (or any, when none is chosen).
        $locationId = Workspace::whereKey($request->integer('location'))->when($ownerId, fn ($q) => $q->where('owner_id', $ownerId))->value('id');
        $type = in_array($request->query('type'), self::TYPES, true) ? $request->query('type') : null;
        $available = in_array($request->query('available'), ['1', '0'], true) ? $request->query('available') : null;
        $idle = $request->boolean('idle');
        $sort = array_key_exists($request->query('sort'), self::SORTS) ? $request->query('sort') : 'bookings';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $inPeriod = fn ($q) => $q->whereDate('booking_date', '>=', $period->startDate())->whereDate('booking_date', '<=', $period->endDate());

        $rooms = Room::with(['owner:id,business_name,name', 'workspace:id,name'])
            ->withCount(['bookings as period_bookings' => fn ($q) => $inPeriod($q)->countsTowardGbv()])
            ->withSum(['bookings as period_earnings' => fn ($q) => $inPeriod($q)->revenueRecognised()], 'amount_paid')
            ->when($search !== '', fn ($q) => $q->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%'))
            ->when($ownerId, fn ($q) => $q->where('owner_id', $ownerId))
            ->when($locationId, fn ($q) => $q->where('workspace_id', $locationId))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($available !== null, fn ($q) => $q->where('is_available', $available === '1'))
            ->when($idle, fn ($q) => $q->where('is_available', true)
                ->whereIn('owner_id', Owner::where('is_active', true)->where('subscription_expires_at', '>', now())->select('id'))
                ->whereDoesntHave('bookings', $inPeriod))
            ->orderBy(self::SORTS[$sort], $dir)->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Rooms/Index', [
            'rooms' => $rooms->through(fn (Room $room) => [
                'id' => $room->id,
                'name' => $room->name,
                'owner_id' => $room->owner_id,
                'business' => $room->owner?->business_name,
                'location_id' => $room->workspace ? $room->workspace_id : null,
                'location' => $room->workspace?->name,
                'type' => $room->typeLabel(),
                'capacity' => $room->capacity,
                'pricing' => $room->pricingSummary(),
                'period_bookings' => (int) $room->period_bookings,
                'period_earnings' => (float) $room->period_earnings,
                'is_available' => (bool) $room->is_available,
            ]),
            'owners' => Owner::has('rooms')->orderBy('business_name')->get(['id', 'business_name', 'name'])
                ->map(fn ($o) => ['id' => $o->id, 'name' => $o->business_name ?: $o->name]),
            'locations' => $ownerId ? Workspace::where('owner_id', $ownerId)->orderBy('name')->get(['id', 'name']) : [],
            'filters' => ['q' => $search, 'owner' => $ownerId, 'location' => $locationId, 'type' => $type, 'available' => $available, 'idle' => $idle],
            'sort' => $sort,
            'dir' => $dir,
            'range' => $range,
            'types' => self::TYPES,
        ]);
    }

    public function show(Request $request, int $room, AvailabilityService $availability): Response
    {
        [$period, $range] = $this->resolvePeriod($request);
        $room = Room::with(['owner', 'workspace', 'plans' => fn ($q) => $q->orderBy('sort_order'), 'pricingProfiles' => fn ($q) => $q->orderBy('sort_order')])->findOrFail($room);

        $base = fn () => Booking::where('owner_id', $room->owner_id)->where('room_id', $room->id);
        $inPeriod = fn () => $base()->whereDate('booking_date', '>=', $period->startDate())->whereDate('booking_date', '<=', $period->endDate());
        $byStatus = $inPeriod()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $total = (int) $byStatus->sum();
        $cancelled = (int) ($byStatus['cancelled'] ?? 0) + (int) ($byStatus['no_show'] ?? 0);

        $stats = [
            'bookings' => $total,
            'completed' => (int) ($byStatus['completed'] ?? 0),
            'cancel_rate' => $total ? round($cancelled / $total * 100, 1) : 0,
            'earnings' => (float) $inPeriod()->revenueRecognised()->sum('amount_paid'),
            'gbv' => (float) $inPeriod()->countsTowardGbv()->sum(DB::raw(Booking::GBV_SQL)),
            'outstanding' => (float) $base()->outstanding()->sum(DB::raw(Booking::OUTSTANDING_SQL)),
            'hours' => round((float) $inPeriod()->where('status', 'completed')->sum('total_hours'), 1),
            'all_time' => $base()->count(),
        ];

        $recent = $base()->with('hotspotUser:id,name,phone')->latest('booking_date')->latest('start_time')->take(10)->get();
        $shared = $room->isShared();

        return Inertia::render('Admin/Rooms/Show', [
            'room' => [
                'id' => $room->id,
                'name' => $room->name,
                'is_available' => (bool) $room->is_available,
                'owner_id' => $room->owner_id,
                'business' => $room->owner?->business_name,
                'location_id' => $room->workspace ? $room->workspace_id : null,
                'location' => $room->workspace?->name,
                'type' => $room->typeLabel(),
                'capacity' => $room->capacity,
                'pricing_model' => $room->pricing_model ?: 'hourly',
                'pricing' => $room->pricingSummary(),
                'is_shared' => $shared,
                'billing_unit' => $shared ? $room->billingUnitLabel() : null,
                'buffer_minutes' => $room->billing_buffer_minutes,
                'description' => $room->description,
                'profiles' => $room->pricingProfiles->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'is_active' => (bool) $p->is_active, 'price_per_hour' => (float) $p->price_per_hour])->values(),
                'plans' => $room->plans->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'price' => (float) $p->price])->values(),
            ],
            'stats' => $stats,
            'range' => $range,
            'today' => collect($availability->freeBusyForDay($room, today()->toDateString()))->map(fn ($seg) => [
                'start' => Carbon::parse($seg['start'])->format('g:i A'),
                'end' => Carbon::parse($seg['end'])->format('g:i A'),
                'state' => $seg['closed'] ? 'closed' : ($seg['available'] <= 0 ? 'full' : ($seg['used'] > 0 ? 'partial' : 'free')),
                'closed' => (bool) $seg['closed'],
                'available' => $seg['available'],
                'capacity' => $seg['capacity'],
            ])->values(),
            // Hidden table columns (business, room) come from this room: no extra queries.
            'recent' => $recent->map(fn (Booking $b) => BookingController::tableRow(
                $b->setRelation('owner', $room->owner)->setRelation('room', $room)
            ))->values(),
        ]);
    }
}
