<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Notification;
use App\Models\Owner;
use App\Models\Room;
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
use Inertia\Inertia;
use Inertia\Response;
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

    public function index(Request $request): Response
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
            'periodLabel' => $period->label,
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
            // Day x hour grid, replacing the old flat 24-hour peakHours() bar
            // — same underlying question ("when am I busy"), richer answer.
            $viewData['peakHoursGrid'] = $this->bookingAnalytics->peakHoursByDayOfWeek($owner, $period);
            $viewData['todaysSchedule'] = $this->bookingAnalytics->todaysSchedule($owner);
        }

        $viewData['showWorkspace'] = $owner->hasFeature('workspace') && $canViewWorkspaces;
        if ($viewData['showWorkspace']) {
            $viewData['occupancy'] = $this->occupancyAnalytics->currentOccupancy($owner);
            $viewData['availableRoomsNow'] = $this->occupancyAnalytics->availableRoomsNow($owner);

            if ($owner->hasFeature('booking')) {
                // Each room's current-period figures, enriched with the
                // previous period's revenue (for a vs-previous-period delta)
                // and revenue per available hour (a room can rank highest on
                // utilization% yet not be the one earning the most per open
                // hour — two different, both useful, rankings).
                $openHoursInPeriod = $this->bookingAnalytics->openHoursAcrossPeriod($owner, $period, $this->businessHours);
                $previousUtilization = $this->bookingAnalytics
                    ->roomUtilization($owner, $period->previous(), $this->businessHours)
                    ->keyBy('room_id');

                $viewData['openHoursInPeriod'] = $openHoursInPeriod;
                $viewData['roomUtilization'] = $this->bookingAnalytics
                    ->roomUtilization($owner, $period, $this->businessHours)
                    ->map(function (array $row) use ($previousUtilization, $openHoursInPeriod) {
                        $previousRevenue = (float) ($previousUtilization[$row['room_id']]['revenue'] ?? 0);
                        $row['previous_revenue'] = $previousRevenue;
                        $row['revenue_change_percent'] = $previousRevenue > 0
                            ? round((($row['revenue'] - $previousRevenue) / $previousRevenue) * 100, 1)
                            : null;
                        $row['revenue_per_open_hour'] = $openHoursInPeriod > 0
                            ? round($row['revenue'] / $openHoursInPeriod, 2)
                            : null;

                        return $row;
                    })
                    ->sortByDesc(fn (array $r) => $r['utilization_percent'] ?? $r['hours_booked'])
                    ->values();
            }
        }

        // "Customers" here is HotspotUser (the merged member entity) — shown
        // wherever that entity is otherwise reachable (hotspot or booking),
        // matching the existing /users nav visibility rule.
        if ($owner->hasFeature('hotspot') || $owner->hasFeature('booking')) {
            $viewData['newCustomers'] = $this->customerAnalytics->newCustomers($owner, $period);
            $viewData['returningCustomerRate'] = $this->customerAnalytics->returningCustomerRate($owner, $period);
            $viewData['averageSpendPerCustomer'] = $this->customerAnalytics->averageSpendPerCustomer($owner, $period);
        }

        // Reuses the existing Notification feed (generated by
        // NotificationService, refreshed by the layout's own view composer)
        // rather than recomputing alert logic here.
        $viewData['needsAttentionCount'] = Notification::forOwner($ownerId)->unread()->count();
        $viewData['needsAttentionItems'] = Notification::forOwner($ownerId)->unread()->latest()->take(10)->get();

        // Pooled from everything computed above — each entry already
        // self-gates on its own minimum-volume threshold, so nothing thin
        // or generic ever lands in this list. Shown right under the top KPI
        // row (see dashboard/index.blade.php), ahead of the detailed charts.
        $viewData['smartInsights'] = $this->buildSmartInsights($owner, $period, $viewData);

        return Inertia::render('Dashboard/Index', $this->props($owner, $viewData));
    }

    /**
     * The view data above, shaped into explicit arrays for the React page —
     * no Eloquent models, every label/format decided here. Sections stay
     * absent (null) exactly when the Blade view skipped them, and values a
     * section is not allowed to show (revenue for staff without reports.view)
     * are never sent at all.
     */
    private function props(Owner $owner, array $d): array
    {
        $canViewRevenue = $d['canViewRevenue'];
        $statusRow = function (string $status) {
            $tmp = new Booking(['status' => $status]);

            return ['label' => $tmp->statusLabel(), 'class' => $tmp->statusBadgeClass()];
        };

        $comparison = $d['revenueComparison'] ?? null;
        $revenue = $d['showRevenue'] ? [
            'today' => (float) $d['revenueToday'],
            'thisMonth' => (float) $d['revenueThisMonth'],
            'changePercent' => $comparison['changePercent'] ?? null,
            'up' => $comparison ? $comparison['change'] >= 0 : true,
            'changeSentence' => ($comparison && $comparison['changePercent'] !== null)
                ? __('app.dashboard.insight_revenue_change', [
                    'direction' => __($comparison['change'] >= 0 ? 'app.dashboard.change_up' : 'app.dashboard.change_down'),
                    'percent' => number_format(abs($comparison['changePercent']), 1),
                    'period' => $d['periodLabel'],
                ])
                : null,
            'trend' => collect($d['revenueTrend'])->map(fn ($amount, $date) => [
                'date' => $date,
                'label' => Carbon::parse($date)->format('j M'),
                'amount' => (float) $amount,
            ])->values()->all(),
        ] : null;

        $products = null;
        if ($d['showProducts']) {
            $top = $d['topProducts'];
            // Revenue-share donut: top 6 + "Other", grouped here, not in the browser.
            $sorted = collect($top)->sortByDesc('revenue')->values();
            $rest = $sorted->slice(6);
            $slices = $sorted->take(6)->map(fn ($p) => ['name' => $p['name'], 'revenue' => $p['revenue'], 'units' => $p['units']])->all();
            if ($rest->sum('revenue') > 0) {
                $slices[] = ['name' => __('app.dashboard.metric_other'), 'revenue' => round($rest->sum('revenue'), 2), 'units' => $rest->sum('units')];
            }

            $products = [
                'summary' => $d['productSummary'],
                'hasSales' => collect($d['productSeries'])->sum('units') > 0,
                'series' => collect($d['productSeries'])->map(fn ($row, $date) => [
                    'label' => Carbon::parse($date)->format('M j'),
                    'units' => $row['units'],
                    'revenue' => $row['revenue'],
                    'orders' => $row['orders'],
                ])->values()->all(),
                'top' => array_map(fn ($p) => ['name' => $p['name'], 'units' => $p['units'], 'revenue' => $p['revenue'], 'orders' => $p['orders']], $top),
                'share' => $slices,
                'lowStock' => $d['lowStockProducts']->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'stock_quantity' => $p->stock_quantity,
                    'low_stock_threshold' => $p->low_stock_threshold,
                ])->values()->all(),
                'insights' => array_values(array_map(fn ($i) => $i['text'], $d['productInsights'])),
            ];
        }

        $booking = null;
        if ($owner->hasFeature('booking')) {
            $booking = [
                'todayBookings' => $d['todayBookings'],
                'statusBreakdown' => collect($d['statusBreakdown'])
                    ->filter(fn ($count) => $count !== 0)
                    ->map(fn ($count, $status) => ['status' => $status, 'count' => $count] + $statusRow($status))
                    ->values()->all(),
                'peakHoursGrid' => $d['peakHoursGrid'],
                'dayLabels' => collect(['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'])
                    ->map(fn ($k) => __('app.day.'.$k))->all(),
                'todaysSchedule' => $d['todaysSchedule']->map(fn (Booking $b) => [
                    'id' => $b->id,
                    'customer' => $b->hotspotUser?->name ?? '—',
                    'room' => $b->room?->name,
                    'time_range' => $b->timeRange(),
                    'status_label' => $b->statusLabel(),
                    'status_class' => $b->statusBadgeClass(),
                ])->values()->all(),
            ];
        }

        $rooms = isset($d['roomUtilization']) ? $d['roomUtilization']->map(fn (array $r) => [
            'room_id' => $r['room_id'],
            'room_name' => $r['room_name'],
            'type_label' => $r['room']->typeLabel(),
            'type_color' => $r['room']->typeColor(),
            'utilization_percent' => $r['utilization_percent'],
            'hours_booked' => $r['hours_booked'],
            'bookings_count' => $r['bookings_count'],
            // Revenue figures only reach viewers allowed to see revenue.
            'revenue' => $canViewRevenue ? $r['revenue'] : null,
            'revenue_change_percent' => $canViewRevenue ? $r['revenue_change_percent'] : null,
            'revenue_per_open_hour' => $canViewRevenue ? $r['revenue_per_open_hour'] : null,
        ])->values()->all() : null;

        $customers = isset($d['newCustomers']) ? [
            'newCustomers' => $d['newCustomers'],
            'returningPercent' => $d['returningCustomerRate']['percent'] ?? null,
            'averageSpend' => $d['averageSpendPerCustomer'],
        ] : null;

        return [
            'businessName' => $owner->business_name,
            'features' => [
                'hotspot' => $owner->hasFeature('hotspot'),
                'booking' => $owner->hasFeature('booking'),
                'workspace' => $owner->hasFeature('workspace'),
                'sales' => $owner->hasFeature('sales'),
            ],
            'hotspot' => $owner->hasFeature('hotspot') ? [
                'totalUsers' => $d['totalUsers'],
                'activeUsers' => $d['activeUsers'],
                'totalProfiles' => $d['totalProfiles'],
                'activeSessions' => $d['activeSessions'],
                'mikrotikUnreachable' => $d['mikrotikError'] !== null,
            ] : null,
            'canViewRevenue' => $canViewRevenue,
            'isStaff' => $d['isStaff'],
            'periodKey' => $d['periodKey'],
            'periodLabel' => $d['periodLabel'],
            'customStart' => $d['customStart'],
            'customEnd' => $d['customEnd'],
            'workingHours' => ($d['hasConfiguredWorkingHours'] ?? false) ? ['isOpenNow' => (bool) $d['isOpenNow']] : null,
            'showRevenue' => $d['showRevenue'],
            'revenue' => $revenue,
            'showProducts' => $d['showProducts'],
            'products' => $products,
            'booking' => $booking,
            'showWorkspace' => $d['showWorkspace'],
            'occupancy' => $d['showWorkspace'] ? [
                'percent' => $d['occupancy']['percent'],
                'occupied' => $d['occupancy']['occupied'],
                'capacity' => $d['occupancy']['capacity'],
                'availableRoomsNow' => $d['availableRoomsNow'],
            ] : null,
            'roomUtilization' => $rooms,
            'customers' => $customers,
            'needsAttention' => [
                'count' => $d['needsAttentionCount'],
                'items' => $d['needsAttentionItems']->map(fn (Notification $n) => [
                    'id' => $n->id,
                    'title' => $n->title,
                    'body' => $n->body,
                    'level' => $n->levelColor(),
                    'icon_path' => $n->iconPath(),
                    'ago' => $n->created_at?->diffForHumans(),
                ])->values()->all(),
            ],
            'smartInsights' => array_values($d['smartInsights']),
            // Billing-facing tenant info: Owner only, never sent to staff.
            'ownerFeatures' => $d['isStaff'] ? null : $owner->features()->where('is_active', true)->get()
                ->map(fn ($f) => [
                    'id' => $f->id,
                    'name' => $f->name,
                    'description' => $f->description,
                    'icon' => $f->icon,
                ])->values()->all(),
        ];
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

    /**
     * Pools plain-language sentences from every analytics source computed
     * in index() above. Each source already decided for itself whether it
     * has enough data to be meaningful (returning null/empty when not) —
     * this method only orders and caps the result, never second-guesses a
     * source's own threshold.
     *
     * @return array<int, string>
     */
    private function buildSmartInsights(Owner $owner, AnalyticsPeriod $period, array $viewData): array
    {
        $insights = [];

        $comparison = $viewData['revenueComparison'] ?? null;
        if ($comparison && $comparison['changePercent'] !== null) {
            $insights[] = __('app.dashboard.insight_revenue_change', [
                'direction' => $comparison['change'] >= 0 ? __('app.dashboard.change_up') : __('app.dashboard.change_down'),
                'percent' => number_format(abs($comparison['changePercent']), 1),
                'period' => $period->label,
            ]);
        }

        if (! empty($viewData['peakHoursGrid'])) {
            $busiest = $this->busiestCell($viewData['peakHoursGrid']);
            if ($busiest) {
                $insights[] = __('app.dashboard.insight_busiest_time', [
                    'day' => __('app.day.'.$busiest['dayKey']),
                    'start' => sprintf('%02d:00', $busiest['hour']),
                    'end' => sprintf('%02d:00', ($busiest['hour'] + 1) % 24),
                ]);
            }
        }

        $rooms = $viewData['roomUtilization'] ?? null;
        if ($rooms && $rooms->isNotEmpty()) {
            $byUtilization = $rooms->sortByDesc(fn (array $r) => $r['utilization_percent'] ?? $r['hours_booked'])->first();
            $byRevenuePerHour = $rooms->filter(fn (array $r) => $r['revenue_per_open_hour'] !== null)
                ->sortByDesc('revenue_per_open_hour')
                ->first();
            if ($byUtilization && $byRevenuePerHour && $byUtilization['room_id'] !== $byRevenuePerHour['room_id']) {
                $insights[] = __('app.dashboard.insight_room_disagreement', [
                    'utilizationRoom' => $byUtilization['room_name'],
                    'revenueRoom' => $byRevenuePerHour['room_name'],
                ]);
            }
        }

        $returning = $viewData['returningCustomerRate'] ?? null;
        if ($returning) {
            $insights[] = __('app.dashboard.insight_returning_customers', ['percent' => $returning['percent']]);
        }

        if ($viewData['showProducts'] ?? false) {
            $byRoomType = $this->productAnalytics->productSpendByRoomType($owner, $period);
            if (count($byRoomType) >= 2) {
                usort($byRoomType, fn (array $a, array $b) => $b['productRevenuePerBooking'] <=> $a['productRevenuePerBooking']);
                [$top, $second] = [$byRoomType[0], $byRoomType[1]];
                if ($second['productRevenuePerBooking'] > 0 && $top['productRevenuePerBooking'] > $second['productRevenuePerBooking']) {
                    $percent = round((($top['productRevenuePerBooking'] - $second['productRevenuePerBooking']) / $second['productRevenuePerBooking']) * 100);
                    if ($percent > 0) {
                        $insights[] = __('app.dashboard.insight_room_type_product_spend', [
                            'topType' => (new Room(['type' => $top['roomType']]))->typeLabel(),
                            'otherType' => (new Room(['type' => $second['roomType']]))->typeLabel(),
                            'percent' => $percent,
                        ]);
                    }
                }
            }

            foreach ($viewData['productInsights'] ?? [] as $productInsight) {
                if (in_array($productInsight['type'], ['fastest_selling', 'declining_sales'], true)) {
                    $insights[] = $productInsight['text'];
                }
            }
        }

        return array_slice($insights, 0, 6);
    }

    /**
     * The single highest-count [day, hour] cell in a peakHoursByDayOfWeek()
     * grid, or null if even the busiest cell doesn't clear the minimum
     * volume threshold (too little data to call it "your busiest time").
     *
     * @param  array<int, array<int, int>>  $grid
     * @return array{dayKey: string, hour: int}|null
     */
    private function busiestCell(array $grid): ?array
    {
        $dayKeys = [0 => 'sunday', 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday'];

        $bestCount = 0;
        $bestDow = null;
        $bestHour = null;
        foreach ($grid as $dow => $hours) {
            foreach ($hours as $hour => $count) {
                if ($count > $bestCount) {
                    $bestCount = $count;
                    $bestDow = $dow;
                    $bestHour = $hour;
                }
            }
        }

        if ($bestDow === null || $bestCount < BookingAnalyticsService::MIN_BUSIEST_CELL_COUNT) {
            return null;
        }

        return ['dayKey' => $dayKeys[$bestDow], 'hour' => $bestHour];
    }
}
