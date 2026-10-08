<?php

namespace App\Http\Controllers;

use App\Models\HotspotUser;
use App\Models\Notification;
use App\Models\SpeedProfile;
use App\Services\AnalyticsPeriod;
use App\Services\BookingAnalyticsService;
use App\Services\BusinessHoursService;
use App\Services\CustomerAnalyticsService;
use App\Services\HotspotSyncService;
use App\Services\OccupancyAnalyticsService;
use App\Services\ProductAnalyticsService;
use App\Services\RevenueAnalyticsService;
use App\Support\TenantContext;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class DashboardController extends Controller
{
    public function __construct(
        private HotspotSyncService $sync,
        private BusinessHoursService $businessHours,
        private RevenueAnalyticsService $revenueAnalytics,
        private OccupancyAnalyticsService $occupancyAnalytics,
        private BookingAnalyticsService $bookingAnalytics,
        private CustomerAnalyticsService $customerAnalytics,
        private ProductAnalyticsService $productAnalytics,
    ) {}

    public function index(Request $request): View
    {
        $owner = TenantContext::user();
        $ownerId = $owner->id;

        $totalUsers = HotspotUser::where('owner_id', $ownerId)->count();
        $activeUsers = HotspotUser::where('owner_id', $ownerId)->where('status', 'active')->count();
        $totalProfiles = SpeedProfile::where('owner_id', $ownerId)->count();

        $activeSessions = 0;
        $mikrotikError = null;

        try {
            $activeSessions = count($this->sync->activeUsers($owner));
        } catch (Exception $e) {
            $mikrotikError = $e->getMessage();
        }

        // Dashboard route is never permission-gated (it's the mandatory
        // post-login landing page), but individual sections on it still
        // respect the same permissions their full pages would.
        $staff = auth('staff')->user();
        $canViewRevenue = ! $staff || $staff->hasPermission('reports.view');
        $canViewWorkspaces = ! $staff || $staff->hasPermission('workspaces.view');

        [$period, $periodKey, $customStart, $customEnd] = $this->resolvePeriod($request);

        $viewData = [
            'owner' => $owner,
            'totalUsers' => $totalUsers,
            'activeUsers' => $activeUsers,
            'totalProfiles' => $totalProfiles,
            'activeSessions' => $activeSessions,
            'mikrotikError' => $mikrotikError,
            'canViewRevenue' => $canViewRevenue,
            'canViewWorkspaces' => $canViewWorkspaces,
            // The plan/feature list is billing-facing tenant info, not an
            // operational concern for staff — Owner-only, no permission to grant.
            'isStaff' => (bool) $staff,
            'periodKey' => $periodKey,
            'customStart' => $customStart,
            'customEnd' => $customEnd,
        ];

        // Working-hours "open now" badge — only meaningful once an owner has
        // actually configured hours; a badge for an unconfigured owner would
        // just be noise (BusinessHoursService treats them as unrestricted).
        if ($owner->hasFeature('workspace') || $owner->hasFeature('booking')) {
            $viewData['hasConfiguredWorkingHours'] = $this->businessHours->hasConfiguredHours($owner);
            $viewData['isOpenNow'] = $this->businessHours->isOpenNow($owner);
        }

        // Revenue combines booking + product/service sales (see
        // RevenueAnalyticsService); meaningless for an owner with neither
        // feature enabled, so the whole block is skipped rather than showing
        // an always-zero card.
        $viewData['showRevenue'] = $canViewRevenue && ($owner->hasFeature('booking') || $owner->hasFeature('sales'));
        if ($viewData['showRevenue']) {
            $viewData['revenueToday'] = $this->revenueAnalytics->totalRevenue($owner, AnalyticsPeriod::today());
            $viewData['revenueThisMonth'] = $this->revenueAnalytics->totalRevenue($owner, AnalyticsPeriod::thisMonth());
            $viewData['revenueComparison'] = $this->revenueAnalytics->revenueWithComparison($owner, $period);
            $viewData['revenueTrend'] = $this->revenueAnalytics->dailyRevenueTrend($owner, $period);
        }

        // ---- Product Analytics (Products dashboard section) ----
        // Gated on the 'sales' feature exactly like every /products* route
        // ('feature:sales' middleware) — skipped entirely, not zero-rendered,
        // for an owner without it, matching the showRevenue/showWorkspace pattern.
        $viewData['showProducts'] = $owner->hasFeature('sales');
        if ($viewData['showProducts']) {
            $viewData['productSummary'] = $this->productAnalytics->summary($owner, $period);
            $viewData['productSeries'] = $this->productAnalytics->dailySeries($owner, $period);
            $viewData['topProducts'] = $this->productAnalytics->topProducts($owner, $period);
            $viewData['lowStockProducts'] = $this->productAnalytics->lowStockProducts($owner);
            $viewData['productInsights'] = $this->productAnalytics->insights($owner, $period);
        }

        if ($owner->hasFeature('booking')) {
            $viewData['todayBookings'] = $this->bookingAnalytics->bookingsCount($owner, AnalyticsPeriod::today(), excludeCancelled: true);
            $viewData['statusBreakdown'] = $this->bookingAnalytics->statusBreakdown($owner, $period);
            $viewData['peakHours'] = $this->bookingAnalytics->peakHours($owner, $period);
            $viewData['todaysSchedule'] = $this->bookingAnalytics->todaysSchedule($owner);
        }

        $viewData['showWorkspace'] = $owner->hasFeature('workspace') && $canViewWorkspaces;
        if ($viewData['showWorkspace']) {
            $viewData['occupancy'] = $this->occupancyAnalytics->currentOccupancy($owner);
            $viewData['availableRoomsNow'] = $this->occupancyAnalytics->availableRoomsNow($owner);

            if ($owner->hasFeature('booking')) {
                // Ranked highest-utilization-first, the same ordering rule
                // BookingAnalyticsService uses internally for most/least
                // utilized room, so the view never re-derives a sort key.
                $viewData['roomUtilization'] = $this->bookingAnalytics
                    ->roomUtilization($owner, $period, $this->businessHours)
                    ->sortByDesc(fn (array $r) => $r['utilization_percent'] ?? $r['hours_booked'])
                    ->values();
            }
        }

        // "Customers" here is HotspotUser (the merged member entity) — shown
        // wherever that entity is otherwise reachable (hotspot or booking),
        // matching the existing /users nav visibility rule.
        if ($owner->hasFeature('hotspot') || $owner->hasFeature('booking')) {
            $viewData['newCustomers'] = $this->customerAnalytics->newCustomers($owner, $period);
        }

        // Reuses the existing Notification feed (generated by
        // NotificationService, refreshed by the layout's own view composer)
        // rather than recomputing alert logic here.
        $viewData['needsAttentionCount'] = Notification::forOwner($ownerId)->unread()->count();
        $viewData['needsAttentionItems'] = Notification::forOwner($ownerId)->unread()->latest()->take(10)->get();

        return view('dashboard.index', $viewData);
    }

    /**
     * Today/7d/30d/3mo/12mo/Custom — the one shared filter driving Overview,
     * Products, and Bookings together. Mirrors FinancialController's own
     * resolvePeriod(), but with this dashboard's own key vocabulary (not
     * AnalyticsPeriod::fromPreset()'s last_7/last_30/etc. keys, and not
     * Financials' today/this_week/this_month/custom keys) mapped directly
     * onto AnalyticsPeriod's existing public factories.
     *
     * @return array{0: AnalyticsPeriod, 1: string, 2: ?string, 3: ?string}
     */
    private function resolvePeriod(Request $request): array
    {
        $key = in_array($request->get('period'), ['today', '7d', '30d', '3mo', '12mo', 'custom'], true)
            ? $request->get('period')
            : 'today';

        $customStart = $request->get('start');
        $customEnd = $request->get('end');

        if ($key === 'custom') {
            $start = $this->parseDate($customStart) ?? now()->subDays(29);
            $end = $this->parseDate($customEnd) ?? now();
            if ($end->lt($start)) {
                [$start, $end] = [$end, $start];
            }

            return [AnalyticsPeriod::custom($start, $end), $key, $start->toDateString(), $end->toDateString()];
        }

        $period = match ($key) {
            '7d' => AnalyticsPeriod::lastDays(7),
            '30d' => AnalyticsPeriod::lastDays(30),
            '3mo' => AnalyticsPeriod::lastMonths(3),
            '12mo' => AnalyticsPeriod::lastMonths(12),
            default => AnalyticsPeriod::today(),
        };

        return [$period, $key, $customStart, $customEnd];
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
