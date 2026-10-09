<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesPeriod;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Owner;
use App\Models\Plan;
use App\Services\Admin\PlatformAnalyticsService;
use App\Services\AnalyticsPeriod;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super Admin → Financials. Two kinds of money, never mixed:
 *  - Platform revenue: what businesses paid Link Space (subscriptions.amount_paid).
 *  - Workspace earnings: what businesses collected (completed bookings' paid
 *    amount + completed product sales) — the owners' own Financials rule.
 * The transactions table is a UNION of the three real money records the
 * system keeps: subscription payments, booking payments, product sales.
 * There is no payments ledger, so refunds / failed payments / fees /
 * providers are reported as not tracked, never estimated.
 */
class FinancialController extends Controller
{
    use ResolvesPeriod;

    public const TYPES = ['subscription', 'booking', 'sale'];

    public const PAYMENTS = ['paid', 'partial', 'unpaid'];

    public function index(Request $request, PlatformAnalyticsService $analytics): Response
    {
        [$period, $range] = $this->resolvePeriod($request);
        $ownerId = Owner::whereKey($request->integer('owner'))->value('id');
        $planId = Plan::whereKey($request->integer('plan'))->value('id');
        $type = in_array($request->query('type'), self::TYPES, true) ? $request->query('type') : null;
        $payment = in_array($request->query('payment'), self::PAYMENTS, true) ? $request->query('payment') : null;
        $sort = in_array($request->query('sort'), ['date', 'amount'], true) ? $request->query('sort') : 'date';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $filters = array_filter(['owner_id' => $ownerId, 'plan_id' => $planId]);

        $prev = $period->previous();
        $cards = [
            'platform_revenue' => [$analytics->platformRevenue($period, $filters), $analytics->platformRevenue($prev, $filters)],
            'earnings' => [$analytics->earnings($period, $filters), $analytics->earnings($prev, $filters)],
            'booking_earnings' => [$analytics->bookingEarnings($period, $filters), $analytics->bookingEarnings($prev, $filters)],
            'sales' => [$analytics->salesEarnings($period, $filters), $analytics->salesEarnings($prev, $filters)],
            'gbv' => [$analytics->gbv($period, $filters), $analytics->gbv($prev, $filters)],
            'discounts' => [$analytics->discounts($period, $filters), $analytics->discounts($prev, $filters)],
            'package_value' => [$analytics->packageValue($period, $filters), $analytics->packageValue($prev, $filters)],
        ];
        $cards = array_map(fn ($p) => ['value' => $p[0], 'change' => $analytics->change($p[0], $p[1])], $cards);
        $cards['outstanding'] = ['value' => $analytics->outstanding($filters), 'change' => null];
        $cards['mrr'] = ['value' => $analytics->mrr($filters), 'change' => null];

        $series = $analytics->series($period, $filters);
        $links = array_map(fn ($k) => '/admin/financial?'.http_build_query(array_filter([
            'preset' => 'custom', 'from' => explode('|', $k)[0], 'to' => explode('|', $k)[1], 'owner' => $ownerId, 'plan' => $planId, 'type' => $type,
        ])).'#transactions', $series['keys']);
        $payments = $analytics->paymentStatusDistribution($period, $filters);
        $paymentColor = ['paid' => 'success', 'partial' => 'warning', 'unpaid' => 'neutral', 'package' => 'info'];
        $byPlan = $analytics->revenueByPlan($period, $filters);
        $top = $analytics->topWorkspaces($period, $filters, 8);
        $t = fn (string $k) => __('app.admin_platform.'.$k);

        $charts = [
            'earnings' => [
                'type' => 'bar', 'stacked' => true, 'money' => true, 'axis' => $t('chart.period'), 'labels' => $series['labels'], 'links' => $links,
                'datasets' => [
                    ['label' => $t('kpi.booking_earnings'), 'data' => $series['series']['booking_earnings'], 'color' => 'c1'],
                    ['label' => $t('kpi.sales'), 'data' => $series['series']['sales'], 'color' => 'c2'],
                ],
            ],
            'platform' => [
                'type' => 'line', 'money' => true, 'axis' => $t('chart.period'), 'labels' => $series['labels'], 'links' => $links,
                'datasets' => [['label' => $t('kpi.platform_revenue'), 'data' => $series['series']['platform_revenue'], 'color' => 'c3']],
            ],
            'payments' => [
                'type' => 'doughnut', 'axis' => $t('col.payment'),
                'labels' => array_map(fn ($k) => $t('payment.'.$k), array_keys($payments)),
                'datasets' => [['label' => __('app.nav.bookings'), 'data' => array_values($payments), 'color' => array_map(fn ($k) => $paymentColor[$k], array_keys($payments))]],
                'links' => array_map(fn ($k) => '/admin/bookings?'.http_build_query(array_filter(['payment' => $k, 'owner' => $ownerId, 'preset' => $range['preset'], 'from' => $range['from'], 'to' => $range['to']])), array_keys($payments)),
            ],
            'plans' => [
                'type' => 'bar', 'money' => true, 'axis' => __('app.nav.plans'),
                'labels' => $byPlan->pluck('name')->all(),
                'datasets' => [['label' => $t('kpi.platform_revenue'), 'data' => $byPlan->pluck('revenue')->all(), 'color' => 'c3']],
                'links' => $byPlan->map(fn ($r) => '/admin/plans/'.$r['plan_id'])->all(),
            ],
            'top' => [
                'type' => 'bar', 'horizontal' => true, 'money' => true, 'axis' => $t('nav.workspaces'),
                'labels' => $top->pluck('name')->all(),
                'datasets' => [['label' => $t('kpi.earnings'), 'data' => $top->pluck('earnings')->all(), 'color' => 'c1']],
                'links' => $top->map(fn ($r) => '/admin/owners/'.$r['owner_id'])->all(),
            ],
        ];

        $fmt = fn (?string $d) => $d ? Carbon::parse($d)->translatedFormat('M j, Y') : null;

        return Inertia::render('Admin/Financial/Index', [
            'range' => $range + ['from_label' => $fmt($range['from']), 'to_label' => $fmt($range['to'])],
            'cards' => $cards,
            'charts' => $charts,
            'transactions' => $this->transactions($period, $ownerId, $planId, $type, $payment, $sort, $dir)
                ->through(fn ($tx) => [
                    'key' => $tx->type.'-'.$tx->id,
                    'type' => $tx->type,
                    'id' => $tx->id,
                    'owner_id' => $tx->owner_id,
                    'workspace' => $tx->workspace,
                    'payer' => $tx->payer,
                    'ref' => $tx->ref,
                    'amount' => (float) $tx->amount,
                    'status' => $tx->status,
                    'counted' => (bool) $tx->counted,
                    'date' => Carbon::parse($tx->at)->translatedFormat('M j, Y'),
                    'url' => match ($tx->type) {
                        'subscription' => '/admin/owners/'.$tx->owner_id.'/subscription', 'booking' => '/admin/bookings/'.$tx->id, default => $tx->ref ? '/admin/bookings/'.$tx->ref : null
                    },
                ]),
            'owners' => Owner::orderBy('business_name')->get(['id', 'business_name', 'name'])
                ->map(fn ($o) => ['id' => $o->id, 'name' => $o->business_name ?: $o->name]),
            'plans' => Plan::orderBy('sort_order')->get(['id', 'name']),
            'filters' => ['owner' => $ownerId, 'plan' => $planId, 'type' => $type, 'payment' => $payment],
            'sort' => $sort,
            'dir' => $dir,
            'options' => ['types' => self::TYPES, 'payments' => self::PAYMENTS],
        ]);
    }

    /**
     * One row per real money record in the period. Columns: type, id,
     * owner_id, workspace, payer, ref (plan / room / item count), amount,
     * status, counted (does it count toward earnings / platform revenue), at.
     */
    private function transactions(AnalyticsPeriod $p, ?int $ownerId, ?int $planId, ?string $type, ?string $payment, string $sort, string $dir): LengthAwarePaginator
    {
        $byOwner = fn ($q, string $col) => $q
            ->when($ownerId, fn ($w) => $w->where($col, $ownerId))
            ->when($planId, fn ($w) => $w->whereIn($col, Owner::where('plan_id', $planId)->select('id')));

        $subs = $byOwner(DB::table('subscriptions as s')
            ->leftJoin('owners as o', 'o.id', '=', 's.owner_id')
            ->leftJoin('plans as pl', 'pl.id', '=', 's.plan_id')
            ->whereBetween('s.created_at', [$p->start, $p->end])
            ->selectRaw("'subscription' as type, s.id as id, s.owner_id as owner_id, o.business_name as workspace, o.name as payer, pl.name as ref, s.amount_paid as amount, 'recorded' as status, 1 as counted, s.created_at as at"), 's.owner_id');

        $bookings = $byOwner(DB::table('bookings as b')
            ->leftJoin('owners as o', 'o.id', '=', 'b.owner_id')
            ->leftJoin('hotspot_users as u', 'u.id', '=', 'b.hotspot_user_id')
            ->leftJoin('rooms as r', 'r.id', '=', 'b.room_id')
            ->where('b.amount_paid', '>', 0)
            ->where(fn ($w) => $w->whereNull('b.payment_method')->orWhere('b.payment_method', '!=', Booking::METHOD_PACKAGE))
            ->whereDateBetween('b.booking_date', $p->startDate(), $p->endDate())
            ->when($payment, fn ($w) => $w->where('b.payment_status', $payment))
            ->selectRaw("'booking' as type, b.id as id, b.owner_id as owner_id, o.business_name as workspace, u.name as payer, r.name as ref, b.amount_paid as amount, b.status as status, CASE WHEN b.status = 'completed' THEN 1 ELSE 0 END as counted, b.booking_date as at"), 'b.owner_id');

        $sales = $byOwner(DB::table('sales as sa')
            ->leftJoin('owners as o', 'o.id', '=', 'sa.owner_id')
            ->leftJoin('hotspot_users as u', 'u.id', '=', 'sa.hotspot_user_id')
            ->where('sa.total', '>', 0)
            ->whereBetween('sa.sold_at', [$p->start, $p->end])
            ->selectRaw("'sale' as type, sa.id as id, sa.owner_id as owner_id, o.business_name as workspace, u.name as payer, sa.booking_id as ref, sa.total as amount, sa.status as status, CASE WHEN sa.status = 'completed' THEN 1 ELSE 0 END as counted, sa.sold_at as at"), 'sa.owner_id');

        // A payment-status filter only applies to booking payments.
        $parts = collect(['subscription' => $subs, 'booking' => $bookings, 'sale' => $sales])
            ->when($payment, fn ($c) => $c->only('booking'))
            ->when($type, fn ($c) => $c->only($type));

        if ($parts->isEmpty()) {
            return new LengthAwarePaginator([], 0, 25);
        }

        $union = $parts->shift();
        foreach ($parts as $q) {
            $union->unionAll($q);
        }

        return DB::query()->fromSub($union, 't')
            ->orderBy($sort === 'amount' ? 'amount' : 'at', $dir)->orderBy('type')->orderBy('id', 'desc')
            ->paginate(25, ['*'], 'page')
            ->withQueryString()
            ->fragment('transactions');
    }
}
