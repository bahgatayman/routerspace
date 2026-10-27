<?php

use App\Models\IdempotencyKey;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Regenerate owner alerts hourly (the layout also refreshes on-demand, throttled).
Schedule::command('notifications:refresh')->hourly();

// Auto-complete exclusive-room bookings once their scheduled end time has
// passed. Shared rooms are excluded — see CompleteExpiredBookings' docblock.
Schedule::command('bookings:complete-expired')->everyFifteenMinutes();

// Add the next batch of dates to long repeating bookings (RecurringBookingService::extend()).
// withoutOverlapping: a slow run is never doubled (bookOccurrence() also refuses a date twice under the room lock).
Schedule::command('bookings:extend-series')->dailyAt('02:30')->withoutOverlapping();

// Retry pending/failed MikroTik suspend/reactivate/speed-change tasks once
// a router comes back online — see ReconcileMikroTikSync's own docblock for
// why this is a scheduled command rather than a queued job.
Schedule::command('mikrotik:reconcile')->everyFiveMinutes();

// Expired once-only request keys (App\Support\RequestIdempotency, 24-hour TTL).
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class]])->daily();
