<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Owner;
use App\Models\Room;
use App\Models\SharedSession;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * "Right now" capacity across every room an owner has — applies the exact
 * same rule as AvailabilityService::usedCapacityNow() (today's
 * pending/confirmed bookings whose time window contains this instant, minus
 * any confirmed booking in a shared room that's already past its no-show
 * grace period, plus every open SharedSession's party_size), via the shared
 * AvailabilityService::bookingsOverlappingNow()/rejectPastGrace() primitives,
 * so the two can never drift from each other again. Still batched for the
 * whole owner in one query each rather than calling usedCapacityNow() in a
 * per-room loop (built for a single-room call site — the booking form,
 * check-in — which would fan out to 2N queries here).
 */
class OccupancyAnalyticsService
{
    public function __construct(private AvailabilityService $availability) {}

    /**
     * @return array{
     *     capacity: int, occupied: int, available: int, percent: float,
     *     rooms: array<int, array{room_id:int, room_name:string, is_available:bool, capacity:int, occupied:int, available:int}>
     * }
     */
    public function currentOccupancy(Owner $owner): array
    {
        $now = Carbon::now();
        $rooms = Room::where('owner_id', $owner->id)->get(['id', 'name', 'type', 'capacity', 'is_available']);

        if ($rooms->isEmpty()) {
            return ['capacity' => 0, 'occupied' => 0, 'available' => 0, 'percent' => 0.0, 'rooms' => []];
        }

        $roomIds = $rooms->pluck('id')->all();

        $overlappingNow = $this->availability->bookingsOverlappingNow($owner->id, ['pending', 'confirmed'], $now, $roomIds);

        $bookingUsageByRoom = $overlappingNow
            ->groupBy('room_id')
            ->map(function (Collection $bookings, int $roomId) use ($rooms) {
                $room = $rooms->firstWhere('id', $roomId);
                $isShared = $room !== null && $room->type === 'shared';

                return (int) $this->availability
                    ->rejectPastGrace($bookings, fn (Booking $b) => $isShared)
                    ->sum('party_size');
            });

        $sessionUsageByRoom = SharedSession::where('owner_id', $owner->id)
            ->whereIn('room_id', $roomIds)
            ->where('status', 'open')
            ->selectRaw('room_id, SUM(party_size) as used')
            ->groupBy('room_id')
            ->pluck('used', 'room_id');

        $rows = $rooms->map(function (Room $room) use ($bookingUsageByRoom, $sessionUsageByRoom) {
            $capacity = $room->effectiveCapacity();
            $occupied = (int) ($bookingUsageByRoom[$room->id] ?? 0) + (int) ($sessionUsageByRoom[$room->id] ?? 0);

            return [
                'room_id' => $room->id,
                'room_name' => $room->name,
                'is_available' => (bool) $room->is_available,
                'capacity' => $capacity,
                'occupied' => $occupied,
                'available' => max(0, $capacity - $occupied),
            ];
        })->values();

        $totalCapacity = (int) $rows->sum('capacity');
        $totalOccupied = (int) $rows->sum('occupied');

        return [
            'capacity' => $totalCapacity,
            'occupied' => $totalOccupied,
            'available' => max(0, $totalCapacity - $totalOccupied),
            'percent' => $totalCapacity > 0 ? round(($totalOccupied / $totalCapacity) * 100, 1) : 0.0,
            'rooms' => $rows->all(),
        ];
    }

    /**
     * Rooms marked available (is_available=true) that also have free
     * capacity right now — an upgrade over the existing dashboard's plain
     * is_available count, per the approved design. Reuses currentOccupancy()
     * rather than re-querying, so this stays within the same fixed query
     * budget regardless of room count.
     */
    public function availableRoomsNow(Owner $owner): int
    {
        return collect($this->currentOccupancy($owner)['rooms'])
            ->filter(fn (array $r) => $r['is_available'] && $r['available'] > 0)
            ->count();
    }
}
