<?php

namespace App\Http\Middleware;

use App\Models\Notification;
use App\Models\SubscriptionRequest;
use App\Services\NotificationService;
use App\Support\ActiveSessionsQuery;
use App\Support\OwnerNavigation;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Middleware;
use Throwable;

/**
 * Inertia shared props + root template. Only what the layouts render is
 * shared — never raw models (Owner hides passwords/MikroTik credentials, but
 * explicit arrays guarantee nothing else leaks either). Everything is
 * computed lazily so a partial reload only pays for what it asks for.
 */
class HandleInertiaRequests extends Middleware
{
    /**
     * Pages still rendered by Blade can be reached by a client-side visit (a
     * link, or a redirect after a form post). Their HTML isn't an Inertia
     * response, so answer with a 409 location instead: the browser does a
     * normal full page load of the same URL.
     */
    public function handle(Request $request, Closure $next)
    {
        $response = parent::handle($request, $next);

        if ($request->header('X-Inertia')
            && $request->isMethod('GET')
            && ! $response->headers->has('X-Inertia')
            && $response->getStatusCode() === 200
            && str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            // This render already consumed the flash (e.g. "saved" after a redirect):
            // keep it for the full page load that follows.
            if ($request->hasSession()) {
                $request->session()->reflash();
            }

            return Inertia::location($request->fullUrl());
        }

        return $response;
    }

    /** Root template: Super Admin shell, signed-out pages, or the owner shell. */
    public function rootView(Request $request): string
    {
        if ($request->is('admin', 'admin/*')) {
            return 'inertia.admin';
        }
        if (! TenantContext::user() || $request->routeIs('subscription.expired')) {
            return 'inertia.guest';
        }

        return 'inertia.owner';
    }

    public function share(Request $request): array
    {
        $isAdmin = $request->is('admin', 'admin/*');

        return [
            ...parent::share($request),
            'locale' => app()->getLocale(),
            'dir' => app()->getLocale() === 'ar' ? 'rtl' : 'ltr',
            'timezone' => config('app.timezone'),
            'auth' => fn () => $this->auth($isAdmin),
            'tenant' => fn () => $isAdmin ? null : $this->tenant(),
            'nav' => fn () => $isAdmin ? null : $this->nav(),
            'notifications' => fn () => $isAdmin ? null : $this->notifications(),
            'flash' => fn () => array_filter([
                'success' => session('success'),
                'error' => session('error'),
                'warning' => session('warning'),
                'info' => session('info'),
                'status' => session('status'),
                'permission_denied' => session('permission_denied'),
            ]),
            'pendingRenewals' => fn () => $isAdmin && Auth::guard('admin')->check()
                ? SubscriptionRequest::pending()->count() : 0,
        ];
    }

    private function auth(bool $isAdmin): ?array
    {
        if ($isAdmin) {
            $admin = Auth::guard('admin')->user();

            return $admin ? ['type' => 'admin', 'name' => $admin->name, 'email' => $admin->email] : null;
        }
        if ($staff = Auth::guard('staff')->user()) {
            return ['type' => 'staff', 'name' => $staff->name, 'email' => $staff->email, 'permissions' => $staff->permissions()->where('is_active', true)->pluck('key')->values()->all()];
        }
        if ($owner = Auth::guard('owner')->user()) {
            return ['type' => 'owner', 'name' => $owner->name, 'email' => $owner->email];
        }

        return null;
    }

    private function tenant(): ?array
    {
        $owner = TenantContext::user();
        if (! $owner) {
            return null;
        }

        return [
            'id' => $owner->id,
            'business_name' => $owner->business_name,
            'logo_url' => $owner->logoUrl(),
            'initials' => $owner->initials(),
            'features' => $owner->activeFeatureKeys(),
            'subscription_status' => $owner->subscriptionStatus(),
            'days_until_expiry' => $owner->daysUntilExpiry(),
        ];
    }

    private function nav(): ?array
    {
        $owner = TenantContext::user();
        if (! $owner) {
            return null;
        }
        $count = $owner->hasFeature('booking') ? ActiveSessionsQuery::count($owner->id) : 0;

        return OwnerNavigation::groups($owner, Auth::guard('staff')->user(), $count);
    }

    /** Notification bell — same throttled refresh as the Blade layout composer. */
    private function notifications(): ?array
    {
        $owner = TenantContext::user();
        if (! $owner) {
            return null;
        }
        if (Cache::add("notif_refresh_{$owner->id}", true, now()->addMinutes(10))) {
            try {
                app(NotificationService::class)->refreshForOwner($owner);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return [
            'unread' => Notification::forOwner($owner->id)->unread()->count(),
            'recent' => Notification::forOwner($owner->id)->latest()->take(6)->get()->map(fn (Notification $n) => [
                'id' => $n->id,
                'title' => $n->title,
                'body' => $n->body ? Str::limit($n->body, 110) : null,
                'icon_path' => $n->iconPath(),
                'url' => route('notifications.open', $n->id),
                'level' => $n->levelColor(),
                'read' => $n->isRead(),
                'ago' => $n->created_at?->diffForHumans(),
            ])->all(),
        ];
    }
}
