<?php

namespace App\Providers;

use App\Models\Notification;
use App\Services\NotificationService;
use App\Services\OnboardingService;
use App\Support\ActiveSessionsQuery;
use App\Support\TenantContext;
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
        // One instance per request so the setup-task queries run at most once.
        $this->app->scoped(OnboardingService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('*', function ($view) {
            $owner = TenantContext::user();
            if ($owner) {
                $view->with('owner', $owner);
            }
        });

        // Onboarding on the dashboard: the owner only (never staff). Tasks are
        // computed here, once, only when this partial renders.
        View::composer('onboarding._dashboard', function ($view) {
            $owner = Auth::guard('staff')->check() ? null : Auth::guard('owner')->user();
            if (! $owner || ! app(OnboardingService::class)->isAvailable()) {
                $view->with('onboardingOwner', null);

                return;
            }
            $service = app(OnboardingService::class);
            $view->with([
                'onboardingOwner' => $owner,
                'onboardingTasks' => $service->tasks($owner),
                'onboardingSummary' => $service->summary($owner),
                'showWelcome' => $service->shouldShowWelcome($owner),
                'checklistHidden' => $owner->onboarding_checklist_hidden_at !== null,
            ]);
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
