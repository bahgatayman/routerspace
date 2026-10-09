<?php

namespace App\Support;

use App\Models\Booking;
use App\Services\AnalyticsPeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Financials module's Transactions abstraction: Booking is the sole
 * query root. A closed SharedSession never independently produces a row
 * (only metadata attached to the Booking it spawned, via Booking::
 * sharedSession()), and a completed Sale is only ever reached through its
 * Booking — so this query structurally cannot emit two rows for one
 * economic event. Shared by the Transactions list controller and the Excel
 * export so both can never drift onto different row sets for the same
 * filters.
 */
class TransactionsQuery
{
    public static function build(int $ownerId, AnalyticsPeriod $period, string $status, string $source): Builder
    {
        return Booking::where('owner_id', $ownerId)
            ->whereDateBetween('booking_date', $period->startDate(), $period->endDate())
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($source === 'direct_booking', fn ($q) => $q->whereDoesntHave('sharedSession'))
            ->when($source === 'shared_session', fn ($q) => $q->whereHas('sharedSession'))
            ->when($source === 'with_products', fn ($q) => $q->whereHas('sale', fn ($sq) => $sq->where('status', 'completed')))
            ->with(['room', 'hotspotUser', 'sale.items.product', 'sharedSession']);
    }
}
