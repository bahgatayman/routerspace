<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\SharedSession;
use Illuminate\Support\Collection;

/**
 * "Active Sessions" is a read-only projection, not a new domain concept —
 * one row per open SharedSession, plus one row per in-progress Booking
 * (confirmed, exclusive or shared room alike — in-progress is inferred from
 * status + the current instant falling inside the reserved window, mirroring
 * CompleteExpiredBookings' own "load today's candidates, filter via
 * Booking::endsAt()" pattern since start_time/end_time are bare H:i strings
 * with no cast), plus one row per open-status Booking (Open Session,
 * exclusive rooms only). A confirmed shared-room booking appears the instant
 * its window starts with no check-in required — it stays non-billable
 * (static, already-quoted total_price; see _card.blade.php's $needsCheckIn
 * branch) until checkIn() flips its status away from 'confirmed', at which
 * point it's replaced by the resulting open SharedSession row, never
 * duplicated. Nothing here is stored, summed, or written — it's recomputed
 * on every call, so there is no counter to ever desync.
 */
class ActiveSessionsQuery
{
    /** @return Collection<int, ActiveSessionRow> */
    public static function build(int $ownerId, ?int $roomId = null): Collection
    {
        $shared = SharedSession::where('owner_id', $ownerId)
            ->where('status', 'open')
            ->when($roomId, fn ($q) => $q->where('room_id', $roomId))
            ->with(['room.workspace', 'hotspotUser', 'sale.items'])
            ->orderBy('opened_at')
            ->get()
            ->map(fn (SharedSession $session) => ActiveSessionRow::fromSharedSession($session));

        $now = now();

        // Confirmed, currently-in-window bookings — exclusive AND shared rooms
        // alike. A shared-room reservation appears here the instant its
        // start_time arrives, with no check-in prerequisite: this row stays
        // non-billable (price fixed at booking time, no live meter) until
        // BookingController::checkIn() flips status away from 'confirmed',
        // at which point it's cleanly replaced by the open SharedSession row
        // below, never duplicated.
        $confirmed = Booking::where('owner_id', $ownerId)
            ->where('status', 'confirmed')
            ->whereDate('booking_date', $now->toDateString())
            ->when($roomId, fn ($q) => $q->where('room_id', $roomId))
            ->with(['room.workspace', 'hotspotUser', 'sale.items'])
            ->get()
            ->filter(fn (Booking $booking) => $booking->startsAt()->lte($now) && $booking->endsAt()->gt($now))
            ->map(fn (Booking $booking) => ActiveSessionRow::fromBooking($booking));

        // Open Session bookings (exclusive rooms only) — unconditionally
        // active, no time-window to check: that's the whole point of one.
        $openExclusive = Booking::where('owner_id', $ownerId)
            ->where('status', 'open')
            ->whereHas('room', fn ($q) => $q->where('type', '!=', 'shared'))
            ->when($roomId, fn ($q) => $q->where('room_id', $roomId))
            ->with(['room.workspace', 'hotspotUser', 'sale.items'])
            ->orderBy('start_time')
            ->get()
            ->map(fn (Booking $booking) => ActiveSessionRow::fromBooking($booking));

        return $shared->concat($confirmed)->concat($openExclusive)->values();
    }

    /** Live count only — used by the nav badge, never cached/stored. */
    public static function count(int $ownerId): int
    {
        return self::build($ownerId)->count();
    }
}
