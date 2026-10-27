<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingSeries;
use App\Models\MemberPackage;
use App\Models\Notification;
use App\Models\Owner;
use App\Support\Money;
use Illuminate\Support\Facades\Schema;

class NotificationService
{
    /**
     * Create a notification for an owner. When a reference is given the alert is
     * idempotent — it is created once per (owner, reference) and never duplicated
     * or re-marked unread on subsequent runs.
     */
    public function notify(Owner $owner, array $data): Notification
    {
        $attributes = [
            'type' => $data['type'] ?? 'general',
            'level' => $data['level'] ?? 'info',
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'action_url' => $data['action_url'] ?? null,
        ];

        if (! empty($data['reference'])) {
            return Notification::firstOrCreate(
                ['owner_id' => $owner->id, 'reference' => $data['reference']],
                $attributes,
            );
        }

        return Notification::create($attributes + [
            'owner_id' => $owner->id,
            'reference' => null,
        ]);
    }

    /**
     * Recompute all system-generated alerts for a single owner. Safe to call
     * repeatedly — every alert here is keyed by an idempotent reference.
     */
    public function refreshForOwner(Owner $owner): void
    {
        $this->checkSubscription($owner);
        $this->checkPlanUsage($owner);

        if ($owner->hasFeature('booking')) {
            $this->checkBookings($owner);
            $this->checkPackages($owner);
            $this->checkSeries($owner);
        }
    }

    /**
     * Repeating bookings, one summary per series (never one per date):
     *  - unpaid dates in the next 7 days — re-notified only when the
     *    earliest unpaid date changes; an unpaid date stays a valid booking;
     *  - upcoming dates hit by a later change (room / use switched off, or
     *    now outside working hours) — re-notified only when that set changes.
     * Nothing is cancelled or moved here.
     */
    protected function checkSeries(Owner $owner): void
    {
        // Runs from every owner page (layout composer): until the
        // booking_series migration is applied, simply do nothing.
        if (! Schema::hasTable('booking_series')) {
            return;
        }

        $series = BookingSeries::where('owner_id', $owner->id)
            ->where('status', BookingSeries::ACTIVE)
            ->with(['room', 'hotspotUser'])
            ->get();
        if ($series->isEmpty()) {
            return;
        }

        $upcoming = Booking::whereIn('booking_series_id', $series->pluck('id'))
            ->where('owner_id', $owner->id)
            ->whereDate('booking_date', '>=', today())
            ->whereIn('status', ['pending', 'confirmed'])
            ->with('room')
            ->orderBy('booking_date')->orderBy('start_time')
            ->get()
            ->groupBy('booking_series_id');
        $hours = app(BusinessHoursService::class);

        foreach ($series as $s) {
            $dates = $upcoming[$s->id] ?? collect();
            $room = $s->room?->name ?? '—';
            $customer = $s->hotspotUser?->name ?? '—';

            // Paid upfront: one alert while the series owes anything, again
            // only when the amount due changes (a payment, a cancellation).
            if ($s->isUpfront()) {
                $due = round($dates->reject(fn ($b) => $b->isPackageCovered())->sum(fn ($b) => $b->balanceDue()), 2);
                if ($due > 0) {
                    $this->notify($owner, [
                        'type' => 'series_upfront_unpaid',
                        'level' => 'warning',
                        'reference' => "series_upfront_unpaid:{$s->id}:".number_format($due, 2, '.', ''),
                        'title' => __('app.series.notif.upfront_title', ['room' => $room]),
                        'body' => __('app.series.notif.upfront_body', ['customer' => $customer, 'amount' => Money::format($due)]),
                        'action_url' => "/bookings/series/{$s->id}",
                    ]);
                }
            }

            $unpaid = $s->isUpfront() ? collect() : $dates->filter(fn ($b) => $b->payment_status === Booking::PAYMENT_UNPAID && $b->booking_date->lte(today()->addDays(7)));
            if ($unpaid->isNotEmpty()) {
                $first = $unpaid->first()->booking_date;
                $this->notify($owner, [
                    'type' => 'series_unpaid',
                    'level' => 'warning',
                    'reference' => "series_unpaid:{$s->id}:{$first->toDateString()}",
                    'title' => __('app.series.notif.unpaid_title', ['room' => $room]),
                    'body' => trans_choice('app.series.notif.unpaid_body', $unpaid->count(), ['count' => $unpaid->count(), 'customer' => $customer, 'date' => $first->format('M j')]),
                    'action_url' => "/bookings/series/{$s->id}",
                ]);
            }

            // Working hours depend only on the weekday: one lookup per weekday + time.
            $withinHours = [];
            $affected = $dates->filter(function ($b) use ($hours, $owner, &$withinHours) {
                if (! $b->room?->is_available) {
                    return true;
                }
                $start = substr((string) $b->start_time, 0, 5);
                $end = substr((string) $b->end_time, 0, 5);
                $key = $b->booking_date->dayOfWeek.'|'.$start.'|'.$end;

                return ! ($withinHours[$key] ??= $hours->isWithinWorkingHours($owner, $b->booking_date->toDateString(), $start, $end));
            });
            if ($affected->isNotEmpty()) {
                $this->notify($owner, [
                    'type' => 'series_attention',
                    'level' => 'danger',
                    'reference' => "series_attention:{$s->id}:".md5($affected->pluck('id')->implode(',')),
                    'title' => __('app.series.notif.attention_title', ['room' => $room]),
                    'body' => trans_choice('app.series.notif.attention_body', $affected->count(), ['count' => $affected->count(), 'customer' => $customer]),
                    'action_url' => "/bookings/series/{$s->id}",
                ]);
            }
        }
    }

    /**
     * Hour packages still holding hours that expire within the next few days.
     * Keyed by package + expiry date: once per package (again only if its
     * expiry date is changed). "Exhausted" is event-driven in HourPackageService.
     */
    protected function checkPackages(Owner $owner): void
    {
        $days = (int) config('packages.expiring_soon_days', 3);

        $expiring = MemberPackage::where('owner_id', $owner->id)
            ->whereNull('cancelled_at')
            ->whereColumn('used_minutes', '<', 'total_minutes')
            ->whereDate('starts_on', '<=', today())
            ->whereDate('expires_on', '>=', today())
            ->whereDate('expires_on', '<=', today()->addDays($days))
            ->with('member')
            ->get();

        foreach ($expiring as $pkg) {
            $this->notify($owner, [
                'type' => 'package_expiring',
                'level' => 'warning',
                'reference' => "pkg_expiring:{$pkg->id}:{$pkg->expires_on->toDateString()}",
                'title' => __('app.packages.notify.expiring_title'),
                'body' => __('app.packages.notify.expiring_body', [
                    'member' => $pkg->member?->name ?? '—',
                    'name' => $pkg->name,
                    'remaining' => $pkg->remainingLabel(),
                    'date' => $pkg->expires_on->translatedFormat('M j'),
                ]),
                'action_url' => "/users/{$pkg->hotspot_user_id}",
            ]);
        }
    }

    protected function checkSubscription(Owner $owner): void
    {
        $status = $owner->subscriptionStatus();
        $date = $owner->subscription_expires_at?->toDateString() ?? 'none';

        if ($status === 'expiring_soon') {
            $this->notify($owner, [
                'type' => 'subscription_expiring',
                'level' => 'warning',
                'reference' => "subscription_expiring:{$date}",
                'title' => __('app.notif.gen.sub_expiring_title'),
                'body' => __('app.notif.gen.sub_expiring_body', ['days' => $owner->daysUntilExpiry()]),
                'action_url' => '/profile',
            ]);
        }

        if ($status === 'expired') {
            $this->notify($owner, [
                'type' => 'subscription_expired',
                'level' => 'danger',
                'reference' => "subscription_expired:{$date}",
                'title' => __('app.notif.gen.sub_expired_title'),
                'body' => __('app.notif.gen.sub_expired_body'),
                'action_url' => '/subscription/expired',
            ]);
        }
    }

    protected function checkPlanUsage(Owner $owner): void
    {
        if (! $owner->plan) {
            return;
        }

        if ($owner->remainingUserSlots() === 0 && $owner->plan->max_members > 0) {
            $this->notify($owner, [
                'type' => 'plan_limit',
                'level' => 'warning',
                'reference' => "plan_limit:{$owner->plan_id}:{$owner->plan->max_members}",
                'title' => __('app.notif.gen.plan_limit_title'),
                'body' => __('app.notif.gen.plan_limit_body', ['max' => $owner->plan->max_members]),
            ]);
        }
    }

    protected function checkBookings(Owner $owner): void
    {
        // Bookings scheduled for today that are still upcoming/confirmed.
        $todays = Booking::where('owner_id', $owner->id)
            ->whereDate('booking_date', today())
            ->whereIn('status', ['pending', 'confirmed'])
            ->with('room')
            ->get();

        foreach ($todays as $booking) {
            $this->notify($owner, [
                'type' => 'booking_today',
                'level' => 'info',
                'reference' => "booking_today:{$booking->id}:".today()->toDateString(),
                'title' => __('app.notif.gen.booking_today_title'),
                'body' => __('app.notif.gen.booking_today_body', [
                    'room' => $booking->room?->name ?? '—',
                    'time' => $booking->timeRange(),
                ]),
                'action_url' => "/bookings/{$booking->id}",
            ]);
        }

        // Pending bookings on a future date still need confirmation.
        $pending = Booking::where('owner_id', $owner->id)
            ->where('status', 'pending')
            ->whereDate('booking_date', '>', today())
            ->with('room')
            ->get();

        foreach ($pending as $booking) {
            $this->notify($owner, [
                'type' => 'booking_pending',
                'level' => 'warning',
                'reference' => "booking_pending:{$booking->id}",
                'title' => __('app.notif.gen.booking_pending_title'),
                'body' => __('app.notif.gen.booking_pending_body', [
                    'room' => $booking->room?->name ?? '—',
                    'date' => $booking->booking_date->format('M j'),
                ]),
                'action_url' => "/bookings/{$booking->id}",
            ]);
        }
    }
}
