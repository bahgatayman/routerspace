<?php

namespace App\Providers;

use App\Models\Notification;
use App\Services\NotificationService;
use App\Support\ActiveSessionsQuery;
use App\Support\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Same rows as ->whereDate($column, '>=', $from)->whereDate($column, '<=', $to), for
        // date and datetime columns alike, but as a plain range on the raw column:
        // whereDate() wraps the column in strftime()/DATE(), which stops the
        // (owner_id|room_id|status, booking_date) indexes being used, so every report
        // scanned the whole table. $from/$to: Y-m-d strings or Carbon dates.
        Builder::macro('whereDateBetween', function (string $column, $from, $to) {
            /** @var Builder $this */
            return $this->where($column, '>=', Carbon::parse($from)->toDateString())
                ->where($column, '<', Carbon::parse($to)->addDay()->toDateString());
        });

        View::composer('*', function ($view) {
            $owner = TenantContext::user();
            if ($owner) {
                $view->with('owner', $owner);
            }
        });

        // Notification bell — inject unread count + recent items into the owner layout.
        // Alerts are (re)generated at most once every 10 minutes per owner so page
        // loads stay cheap even without the scheduler running.
        View::composer('layouts.app', function ($view) {
            $owner = TenantContext::user();
            $view->with('actingStaff', Auth::guard('staff')->user());
            if (! $owner) {
                return;
            }

            if (Cache::add("notif_refresh_{$owner->id}", true, now()->addMinutes(10))) {
                try {
                    app(NotificationService::class)->refreshForOwner($owner);
                } catch (Throwable $e) {
                    // Never let alert generation break a page render.
                    report($e);
                }
            }

            $view->with('navUnreadCount', Notification::forOwner($owner->id)->unread()->count());
            $view->with('navRecentNotifications',
                Notification::forOwner($owner->id)->latest()->take(6)->get());

            // Active Sessions nav badge — a fresh count on every request, no
            // cache guard (unlike the notification refresh above): there's no
            // generation step to throttle here, only a read, so there's
            // nothing for a stale count to ever desync from.
            if ($owner->hasFeature('booking')) {
                $view->with('navActiveSessionsCount', ActiveSessionsQuery::count($owner->id));
            }
        });
    }
}
