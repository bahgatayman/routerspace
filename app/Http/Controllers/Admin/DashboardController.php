<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesPeriod;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Booking;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionRequest;
use App\Services\Admin\PlatformAnalyticsService;
use App\Services\Admin\PlatformInsightsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super Admin → platform analytics center. Every number comes from
 * PlatformAnalyticsService (one money rule for the whole admin); the period
 * and plan filters are plain GET params so every view is linkable.
 */
class DashboardController extends Controller
{
    use ResolvesPeriod;

    public function index(Request $request, PlatformAnalyticsService $analytics, PlatformInsightsService $insights): Response
    {
        [$period, $range] = $this->resolvePeriod($request);
        $planId = Plan::whereKey($request->integer('plan'))->value('id');
        $filters = array_filter(['plan_id' => $planId]);

        $series = $analytics->series($period, $filters);
        $bucketLinks = fn (string $base) => array_map(function ($key) use ($base) {
            [$from, $to] = explode('|', $key);

            return $base.(str_contains($base, '?') ? '&' : '?').'preset=custom&from='.$from.'&to='.$to;
        }, $series['keys']);
        $rangeQuery = 'preset='.$range['preset'].'&from='.$range['from'].'&to='.$range['to'];
        $t = fn (string $k) => __('app.admin_platform.'.$k);

        $status = $analytics->statusDistribution($period, $filters);
        $statusColor = ['completed' => 'success', 'confirmed' => 'info', 'pending' => 'warning', 'checked_in' => 'c3', 'open' => 'c3', 'cancelled' => 'danger', 'no_show' => 'neutral'];
        $top = $analytics->topWorkspaces($period, $filters, 8);
        $byPlan = $analytics->revenueByPlan($period, $filters);

        $charts = [
            'earnings' => [
                'type' => 'line', 'money' => true, 'axis' => $t('chart.period'),
                'labels' => $series['labels'],
                'datasets' => [['label' => $t('kpi.earnings'), 'data' => $series['series']['earnings'], 'color' => 'c1']],
                'links' => $bucketLinks('/admin/financial'),
            ],
            'platform' => [
                'type' => 'bar', 'money' => true, 'axis' => $t('chart.period'),
                'labels' => $series['labels'],
                'datasets' => [['label' => $t('kpi.platform_revenue'), 'data' => $series['series']['platform_revenue'], 'color' => 'c2']],
                'links' => $bucketLinks('/admin/financial?type=subscription'),
            ],
            'bookings' => [
                'type' => 'bar', 'stacked' => true, 'axis' => $t('chart.period'),
                'labels' => $series['labels'],
                'datasets' => [
                    ['label' => __('app.admin_platform.status.completed'), 'data' => $series['series']['completed'], 'color' => 'success'],
                    ['label' => $t('chart.other_active'), 'data' => array_map(fn ($a, $c, $x) => max(0, $a - $c - $x), $series['series']['bookings'], $series['series']['completed'], $series['series']['cancelled']), 'color' => 'info'],
                    ['label' => $t('chart.cancelled_noshow'), 'data' => $series['series']['cancelled'], 'color' => 'danger'],
                ],
                'links' => $bucketLinks('/admin/bookings'),
            ],
            'growth' => [
                'type' => 'bar', 'axis' => $t('chart.period'),
                'labels' => $series['labels'],
                'datasets' => [
                    ['label' => $t('chart.new_workspaces'), 'data' => $series['series']['new_workspaces'], 'color' => 'c1'],
                    ['label' => $t('chart.renewals'), 'data' => $series['series']['renewals'], 'color' => 'c3'],
                    ['label' => $t('chart.expired'), 'data' => $series['series']['expired'], 'color' => 'c2'],
                ],
            ],
            'status' => [
                'type' => 'doughnut', 'axis' => __('app.common.status'),
                'labels' => array_map(fn ($s) => __('app.admin_platform.status.'.$s), array_keys($status)),
                'datasets' => [['label' => __('app.nav.bookings'), 'data' => array_values($status), 'color' => array_map(fn ($s) => $statusColor[$s] ?? 'neutral', array_keys($status))]],
                'links' => array_map(fn ($s) => '/admin/bookings?status='.$s.'&'.$rangeQuery, array_keys($status)),
            ],
            'top' => [
                'type' => 'bar', 'horizontal' => true, 'money' => true, 'axis' => $t('nav.workspaces'),
                'labels' => $top->pluck('name')->all(),
                'datasets' => [['label' => $t('kpi.earnings'), 'data' => $top->pluck('earnings')->all(), 'color' => 'c1']],
                'links' => $top->map(fn ($r) => '/admin/owners/'.$r['owner_id'])->all(),
            ],
            'plans' => [
                'type' => 'bar', 'axis' => __('app.nav.plans'),
                'labels' => $byPlan->pluck('name')->all(),
                'datasets' => [['label' => $t('chart.active_workspaces'), 'data' => $byPlan->pluck('active')->all(), 'color' => 'c1']],
                'links' => $byPlan->map(fn ($r) => '/admin/plans/'.$r['plan_id'])->all(),
            ],
        ];

        $noPlan = __('app.admin_biz.no_plan');

        return Inertia::render('Admin/Dashboard/Index', [
            'range' => $range + [
                'from_label' => Carbon::parse($range['from'])->translatedFormat('M j, Y'),
                'to_label' => Carbon::parse($range['to'])->translatedFormat('M j, Y'),
            ],
            'planId' => $planId,
            'plans' => Plan::orderBy('sort_order')->get(['id', 'name'])->map->only(['id', 'name'])->all(),
            'kpis' => $analytics->kpis($period, $filters),
            'charts' => $charts,
            'topChartHeight' => max(180, 34 * count($charts['top']['labels']) + 40),
            'byPlan' => $byPlan->all(),
            'insights' => $insights->forPeriod($period, $filters),
            'recentOwners' => $analytics->owners($filters)->with('plan:id,name')->latest()->take(6)->get()
                ->map(fn (Owner $o) => [
                    'id' => $o->id,
                    'name' => $o->business_name ?: $o->name,
                    'plan' => $o->plan?->name ?? $noPlan,
                    'at_iso' => $o->created_at?->toIso8601String(),
                    'ago' => $o->created_at?->diffForHumans(),
                ])->all(),
            'recentBookings' => Booking::with(['owner:id,business_name,name', 'room:id,name', 'hotspotUser:id,name'])
                ->when($planId, fn ($q) => $q->whereIn('owner_id', Owner::where('plan_id', $planId)->select('id')))
                ->latest()->take(6)->get()
                ->map(fn (Booking $b) => [
                    'id' => $b->id,
                    'room' => $b->room?->name ?? '—',
                    'business' => $b->owner?->business_name,
                    'customer' => $b->hotspotUser?->name ?? __('app.admin_biz.deleted_member'),
                    'net' => (float) $b->total_price - (float) $b->discount_total,
                    'status' => $b->status,
                ])->all(),
            'recentPayments' => Subscription::with(['owner:id,business_name,name', 'plan:id,name', 'admin:id,name'])
                ->when($planId, fn ($q) => $q->where('plan_id', $planId))
                ->latest()->take(6)->get()
                ->map(fn (Subscription $s) => [
                    'id' => $s->id,
                    'owner_id' => $s->owner_id,
                    'business' => $s->owner?->business_name ?? '—',
                    'plan' => $s->plan?->name,
                    'months' => (int) $s->months,
                    'amount' => (float) $s->amount_paid,
                    'ago' => $s->created_at?->diffForHumans(),
                ])->all(),
            'adminActions' => AdminAuditLog::latest('created_at')->take(6)->get()
                ->map(fn (AdminAuditLog $a) => [
                    'id' => $a->id,
                    'text' => $a->description ?: $a->action,
                    'admin' => $a->admin_name ?? 'Admin',
                    'owner_id' => $a->owner_id,
                    'owner' => $a->owner_name ?? ($a->owner_id ? '#'.$a->owner_id : null),
                    'at_iso' => $a->created_at?->toIso8601String(),
                    'ago' => $a->created_at?->diffForHumans(),
                ])->all(),
            'expiring' => $analytics->owners($filters)->with('plan:id,name')->where('is_active', true)
                ->whereBetween('subscription_expires_at', [now(), now()->addDays(14)])
                ->orderBy('subscription_expires_at')->take(6)->get()
                ->map(fn (Owner $o) => [
                    'id' => $o->id,
                    'business_name' => $o->business_name,
                    'name' => $o->business_name ?: $o->name,
                    'plan' => $o->plan?->name ?? $noPlan,
                    'days' => $o->daysUntilExpiry(),
                ])->all(),
            'pendingRenewals' => SubscriptionRequest::pending()->count(),
        ]);
    }
}
