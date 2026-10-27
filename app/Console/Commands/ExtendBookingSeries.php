<?php

namespace App\Console\Commands;

use App\Models\BookingSeries;
use App\Services\RecurringBookingService;
use Illuminate\Console\Command;

/**
 * Add the next batch of dates to active repeating bookings whose booked
 * dates run out within RecurringBookingService::HORIZON_DAYS. Each date goes
 * through the normal booking checks; dates that can't be booked are kept on
 * the series for the owner to resolve, with one summary notification.
 * Idempotent: a series only moves forward from its last handled date.
 */
class ExtendBookingSeries extends Command
{
    protected $signature = 'bookings:extend-series';

    protected $description = 'Add the next batch of dates to active repeating bookings';

    public function handle(RecurringBookingService $service): int
    {
        $created = 0;
        $failed = 0;
        $errors = 0;

        BookingSeries::where('status', BookingSeries::ACTIVE)
            ->where(fn ($q) => $q->whereNull('generated_through')
                ->orWhereDate('generated_through', '<', today()->addDays(RecurringBookingService::HORIZON_DAYS)))
            ->with(['owner', 'room', 'hotspotUser', 'pricingProfile'])
            ->chunkById(50, function ($chunk) use ($service, &$created, &$failed, &$errors) {
                foreach ($chunk as $series) {
                    // One broken series never holds up the others; it keeps its
                    // generated_through, so the next run tries it again.
                    try {
                        $result = $service->extend($series);
                    } catch (\Throwable $e) {
                        report($e);
                        $errors++;
                        $this->error("Series #{$series->id}: {$e->getMessage()}");

                        continue;
                    }
                    $created += $result['created'];
                    $failed += $result['failed'];
                }
            });

        $this->info("Repeating bookings: {$created} dates booked, {$failed} could not be booked, {$errors} series failed.");

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
