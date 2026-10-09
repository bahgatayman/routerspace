<?php

namespace App\Support;

use App\Models\Owner;
use App\Models\Staff;

/**
 * The owner sidebar — one definition used by the Blade layout
 * (layouts/app.blade.php) and the React layout (shared Inertia prop), so
 * both always apply the same feature + permission gates.
 *
 * Owner sessions have no permission grid — every item they can reach via
 * feature entitlement is visible. A staff session only sees items it has
 * also been granted the matching permission for. `spa` marks pages served by
 * Inertia (client-side navigation); the rest are still Blade pages and are
 * opened with a normal page load.
 */
final class OwnerNavigation
{
    /** Pages still rendered by Blade (not Inertia) in this phase. */
    public const BLADE_PATHS = ['/active-sessions'];

    /** @return array<int, array{label: string, items: array<int, array<string, mixed>>}> */
    public static function groups(Owner $owner, ?Staff $staff, int $activeSessions = 0): array
    {
        $can = fn (string $key) => ! $staff || $staff->hasPermission($key);
        $item = fn (bool $show, string $href, string $pattern, string $icon, string $label, int $count = 0) => [
            'show' => $show, 'href' => $href, 'active' => request()->is($pattern), 'pattern' => $pattern,
            'icon' => $icon, 'label' => $label, 'count' => $count, 'spa' => ! in_array($href, self::BLADE_PATHS, true),
        ];
        $money = $owner->hasFeature('booking') || $owner->hasFeature('sales');

        $groups = [
            ['label' => __('app.ui.nav_group.today'), 'items' => [
                $item(true, '/dashboard', 'dashboard', 'home', __('app.nav.dashboard')),
                $item($owner->hasFeature('booking') && ($can('shared_sessions.view') || $can('bookings.view')), '/active-sessions', 'active-sessions*', 'live', __('app.nav.active_sessions'), $activeSessions),
                $item($owner->hasFeature('booking') && $can('bookings.view'), '/bookings/calendar', 'bookings*', 'calendar', __('app.nav.bookings')),
            ]],
            ['label' => __('app.ui.nav_group.manage'), 'items' => [
                $item($owner->hasFeature('workspace') && $can('workspaces.view'), '/workspaces', 'workspaces*', 'building', __('app.nav.workspaces')),
                $item(($owner->hasFeature('hotspot') || $owner->hasFeature('booking')) && $can('members.view'), '/users', 'users*', 'users', __('app.nav.users')),
                $item($owner->hasFeature('booking') && $can('packages.view'), '/packages', 'packages*', 'clock', __('app.nav.packages')),
                $item($owner->hasFeature('sales') && $can('products.view'), '/products', 'products*', 'box', __('app.nav.products')),
            ]],
            ['label' => __('app.ui.nav_group.money'), 'items' => [
                $item($money && $can('financials.view'), '/financials', 'financials*', 'money', __('app.nav.financials')),
                $item($money && $can('expenses.view'), '/expenses', 'expenses*', 'receipt', __('app.nav.expenses')),
                $item($money && $can('coupons.view'), '/coupons', 'coupons*', 'tag', __('app.nav.coupons')),
            ]],
            ['label' => __('app.ui.nav_group.network'), 'items' => [
                $item($owner->hasFeature('hotspot') && $can('hotspot.view_sessions'), '/sessions', 'sessions*', 'wifi', __('app.nav.wifi_sessions')),
                $item($owner->hasFeature('hotspot') && $can('hotspot.manage_speed'), '/speed-profiles', 'speed-profiles*', 'bolt', __('app.nav.speed_profiles')),
            ]],
            ['label' => __('app.ui.nav_group.admin'), 'items' => [
                $item(! $staff, '/staff', 'staff*', 'team', __('app.nav.staff')),
                $item($can('settings.view'), '/settings', 'settings*', 'gear', __('app.nav.settings')),
            ]],
        ];

        // Only visible items, only groups that still have one.
        return array_values(array_filter(array_map(function ($g) {
            $g['items'] = array_values(array_filter($g['items'], fn ($i) => $i['show']));

            return $g;
        }, $groups), fn ($g) => $g['items'] !== []));
    }
}
