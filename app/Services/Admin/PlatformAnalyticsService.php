<?php

namespace App\Services\Admin;

use App\Models\Booking;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Room;
use App\Models\Sale;
use App\Models\Subscription;
use App\Services\AnalyticsPeriod;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin — platform-wide numbers across every business (Owner = the
 * "workspace" in admin language). Money rules are the model scopes
 * (Booking::revenueRecognised / countsTowardGbv / outstanding, Sale::completed)
 * that the owners' own Financials use, so the two can never disagree:
 *
 *   Platform revenue   = subscriptions.amount_paid (admin-recorded renewals; no gateway)
 *   Workspace earnings = paid amount on completed bookings + completed product sales
 *   GBV                = non-cancelled / no-show bookings, total − coupon discount
 *   Outstanding        = net − paid on still-due, non-package bookings
 *
 * Shared sessions close into completed bookings, so they are already inside
 * bookings and never added again. Refunds, failed payments, commissions and
 * payment providers are not recorded by this system and are never estimated.
 *
 * Optional filters: ['owner_id' => int, 'plan_id' => int] narrow every query
 * to one business / the businesses on one plan.
 */
class PlatformAnalyticsService
{
    /** @return array<string, array{value: float|int, previous: float|int|null, change: ?float}> */
    public function kpis(AnalyticsPeriod $period, array $filters = []): array
    {
        $prev = $period->previous();
        $m = fn ($cur, $old) => ['value' => $cur, 'previous' => $old, 'change' => $this->change($cur, $old)];
        $snap = fn ($cur) => ['value' => $cur, 'previous' => null, 'change' => null];

        $bookings = fn (AnalyticsPeriod $p) => $this->bookingCounts($p, $filters);
        [$cur, $old] = [$bookings($period), $bookings($prev)];

        $earn = $this->earnings($period, $filters);
        $earnPrev = $this->earnings($prev, $filters);
        $bookingEarn = $this->bookingEarnings($period, $filters);

        $owners = $this->owners($filters);
        $totalAtStart = (clone $owners)->where('created_at', '<', $period->start)->count();

        return [
            'workspaces' => $snap((clone $owners)->count()),
            'active_workspaces' => $snap((clone $owners)->where('is_active', true)->where('subscription_expires_at', '>', now())->count()),
            'new_workspaces' => $m($this->newOwners($period, $filters), $this->newOwners($prev, $filters)),
            'growth_rate' => $snap($totalAtStart > 0 ? round($this->newOwners($period, $filters) / $totalAtStart * 100, 1) : null),
            'mrr' => $snap($this->mrr($filters)),
            'products' => $snap($this->scoped(Product::query(), $filters)->count()),
            'rooms' => $snap($this->scoped(Room::query(), $filters)->count()),
            'bookings' => $m($cur['total'], $old['total']),
            'completed' => $m($cur['completed'], $old['completed']),
            'cancellation_rate' => $m($cur['cancel_rate'], $old['cancel_rate']),
            'platform_revenue' => $m($this->platformRevenue($period, $filters), $this->platformRevenue($prev, $filters)),
            'earnings' => $m($earn, $earnPrev),
            'gbv' => $m($this->gbv($period, $filters), $this->gbv($prev, $filters)),
            'outstanding' => $snap($this->outstanding($filters)),
            'discounts' => $m($this->discounts($period, $filters), $this->discounts($prev, $filters)),
            'package_value' => $m($this->packageValue($period, $filters), $this->packageValue($prev, $filters)),
            'avg_booking_value' => $m($cur['completed'] ? round($bookingEarn / $cur['completed'], 2) : 0, $old['completed'] ? round($this->bookingEarnings($prev, $filters) / $old['completed'], 2) : 0),
            'active_customers' => $m($this->activeCustomers($period, $filters), $this->activeCustomers($prev, $filters)),
        ];
    }

    // ------------------------------------------------------------ money

    public function platformRevenue(AnalyticsPeriod $p, array $filters = []): float
    {
        return (float) $this->subscriptions($p, $filters)->sum('amount_paid');
    }

    public function bookingEarnings(AnalyticsPeriod $p, array $filters = []): float
    {
        return (float) $this->bookingsIn($p, $filters)->revenueRecognised()->sum('amount_paid');
    }

    public function salesEarnings(AnalyticsPeriod $p, array $filters = []): float
    {
        return (float) $this->scoped(Sale::query(), $filters)->completed()->whereBetween('sold_at', [$p->start, $p->end])->sum('total');
    }

    public function earnings(AnalyticsPeriod $p, array $filters = []): float
    {
        return round($this->bookingEarnings($p, $filters) + $this->salesEarnings($p, $filters), 2);
    }

    public function gbv(AnalyticsPeriod $p, array $filters = []): float
    {
        return (float) $this->bookingsIn($p, $filters)->countsTowardGbv()->sum(DB::raw(Booking::GBV_SQL));
    }

    public function outstanding(array $filters = []): float
    {
        return (float) $this->scoped(Booking::query(), $filters)->outstanding()->sum(DB::raw(Booking::OUTSTANDING_SQL));
    }

    public function discounts(AnalyticsPeriod $p, array $filters = []): float
    {
        return (float) $this->bookingsIn($p, $filters)->countsTowardGbv()->sum('discount_total');
    }

    public function packageValue(AnalyticsPeriod $p, array $filters = []): float
    {
        return (float) $this->bookingsIn($p, $filters)->revenueRecognised()->where('payment_method', Booking::METHOD_PACKAGE)->sum('amount_paid');
    }

    /** Monthly recurring revenue: the plan price of every business with an active, unexpired subscription. */
    public function mrr(array $filters = []): float
    {
        return (float) $this->owners($filters)
            ->where('owners.is_active', true)->where('subscription_expires_at', '>', now())
            ->join('plans', 'plans.id', '=', 'owners.plan_id')
            ->sum('plans.price_per_month');
    }

    // ------------------------------------------------------------ counts

    /** @return array{total: int, completed: int, cancelled: int, cancel_rate: float} */
    public function bookingCounts(AnalyticsPeriod $p, array $filters = []): array
    {
        $rows = $this->bookingsIn($p, $filters)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $total = (int) $rows->sum();
        $cancelled = (int) ($rows['cancelled'] ?? 0) + (int) ($rows['no_show'] ?? 0);

        return [
            'total' => $total,
            'completed' => (int) ($rows['completed'] ?? 0),
            'cancelled' => $cancelled,
            'cancel_rate' => $total ? round($cancelled / $total * 100, 1) : 0.0,
        ];
    }

    /** @return array<string, int> status => count (fixed order) */
    public function statusDistribution(AnalyticsPeriod $p, array $filters = []): array
    {
        $rows = $this->bookingsIn($p, $filters)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $order = ['completed', 'confirmed', 'pending', 'checked_in', 'open', 'cancelled', 'no_show'];

        return collect($order)->mapWithKeys(fn ($s) => [$s => (int) ($rows[$s] ?? 0)])->filter()->all();
    }

    /** @return array<string, int> payment_status => count, for bookings that count toward GBV */
    public function paymentStatusDistribution(AnalyticsPeriod $p, array $filters = []): array
    {
        $q = $this->bookingsIn($p, $filters)->countsTowardGbv();
        $package = (clone $q)->where('payment_method', Booking::METHOD_PACKAGE)->count();
        $rows = $q->where(fn ($w) => $w->whereNull('payment_method')->orWhere('payment_method', '!=', Booking::METHOD_PACKAGE))
            ->selectRaw('payment_status, COUNT(*) as n')->groupBy('payment_status')->pluck('n', 'payment_status');

        return array_filter([
            'paid' => (int) ($rows['paid'] ?? 0),
            'partial' => (int) ($rows['partial'] ?? 0),
            'unpaid' => (int) ($rows['unpaid'] ?? 0),
            'package' => $package,
        ]);
    }

    public function newOwners(AnalyticsPeriod $p, array $filters = []): int
    {
        return $this->owners($filters)->whereBetween('owners.created_at', [$p->start, $p->end])->count();
    }

    /** Distinct members with at least one booking in the period (the only reliably tracked "active customer"). */
    public function activeCustomers(AnalyticsPeriod $p, array $filters = []): int
    {
        return (int) $this->bookingsIn($p, $filters)->countsTowardGbv()->whereNotNull('hotspot_user_id')->distinct()->count('hotspot_user_id');
    }

    // ------------------------------------------------------------ series

    /**
     * Time series bucketed by day (≤ 62 days), week (≤ 1 year) or month.
     *
     * @return array{labels: array<int, string>, keys: array<int, string>, unit: string, series: array<string, array<int, float|int>>}
     */
    public function series(AnalyticsPeriod $p, array $filters = []): array
    {
        $days = $p->days();
        $unit = $days <= 62 ? 'day' : ($days <= 366 ? 'week' : 'month');
        $buckets = $this->buckets($p, $unit);

        $byDate = fn (Collection $rows) => $rows->mapWithKeys(fn ($r) => [substr((string) $r->d, 0, 10) => (float) $r->v]);

        $bookingEarn = $byDate($this->bookingsIn($p, $filters)->revenueRecognised()
            ->selectRaw('booking_date as d, SUM(amount_paid) as v')->groupBy('booking_date')->get());
        $sales = $byDate($this->scoped(Sale::query(), $filters)->completed()->whereBetween('sold_at', [$p->start, $p->end])
            ->selectRaw('DATE(sold_at) as d, SUM(total) as v')->groupBy(DB::raw('DATE(sold_at)'))->get());
        $platform = $byDate($this->subscriptions($p, $filters)
            ->selectRaw('DATE(created_at) as d, SUM(amount_paid) as v')->groupBy(DB::raw('DATE(created_at)'))->get());
        $bookingsAll = $byDate($this->bookingsIn($p, $filters)->selectRaw('booking_date as d, COUNT(*) as v')->groupBy('booking_date')->get());
        $bookingsDone = $byDate($this->bookingsIn($p, $filters)->where('status', 'completed')->selectRaw('booking_date as d, COUNT(*) as v')->groupBy('booking_date')->get());
        $bookingsCancel = $byDate($this->bookingsIn($p, $filters)->whereIn('status', ['cancelled', 'no_show'])->selectRaw('booking_date as d, COUNT(*) as v')->groupBy('booking_date')->get());
        $renewals = $byDate($this->subscriptions($p, $filters)->selectRaw('DATE(created_at) as d, COUNT(*) as v')->groupBy(DB::raw('DATE(created_at)'))->get());
        $newOwners = $byDate($this->owners($filters)->whereBetween('owners.created_at', [$p->start, $p->end])
            ->selectRaw('DATE(owners.created_at) as d, COUNT(*) as v')->groupBy(DB::raw('DATE(owners.created_at)'))->get());
        $expired = $byDate($this->owners($filters)->whereBetween('subscription_expires_at', [$p->start, min($p->end, now())])
            ->selectRaw('DATE(subscription_expires_at) as d, COUNT(*) as v')->groupBy(DB::raw('DATE(subscription_expires_at)'))->get());

        $roll = function (Collection $daily) use ($buckets) {
            $out = array_fill(0, count($buckets), 0.0);
            foreach ($daily as $date => $v) {
                foreach ($buckets as $i => [$from, $to]) {
                    if ($date >= $from && $date <= $to) {
                        $out[$i] += $v;
                        break;
                    }
                }
            }

            return array_map(fn ($v) => round($v, 2), $out);
        };

        $earnings = array_map(fn ($a, $b) => round($a + $b, 2), $roll($bookingEarn), $roll($sales));

        return [
            'unit' => $unit,
            'keys' => array_map(fn ($b) => $b[0].'|'.$b[1], $buckets),
            'labels' => array_map(fn ($b) => $this->bucketLabel($b, $unit), $buckets),
            'series' => [
                'earnings' => $earnings,
                'booking_earnings' => $roll($bookingEarn),
                'sales' => $roll($sales),
                'platform_revenue' => $roll($platform),
                'bookings' => array_map('intval', $roll($bookingsAll)),
                'completed' => array_map('intval', $roll($bookingsDone)),
                'cancelled' => array_map('intval', $roll($bookingsCancel)),
                'renewals' => array_map('intval', $roll($renewals)),
                'new_workspaces' => array_map('intval', $roll($newOwners)),
                'expired' => array_map('intval', $roll($expired)),
            ],
        ];
    }

    // ------------------------------------------------------------ rankings

    /**
     * Businesses ranked by workspace earnings in the period, with bookings
     * and platform revenue alongside (three grouped queries, then merged).
     *
     * @return Collection<int, array{owner_id: int, name: string, earnings: float, bookings: int, platform_revenue: float}>
     */
    public function topWorkspaces(AnalyticsPeriod $p, array $filters = [], int $limit = 8, string $by = 'earnings'): Collection
    {
        $bookingEarn = $this->bookingsIn($p, $filters)->revenueRecognised()->selectRaw('owner_id, SUM(amount_paid) as v')->groupBy('owner_id')->pluck('v', 'owner_id');
        $sales = $this->scoped(Sale::query(), $filters)->completed()->whereBetween('sold_at', [$p->start, $p->end])->selectRaw('owner_id, SUM(total) as v')->groupBy('owner_id')->pluck('v', 'owner_id');
        $counts = $this->bookingsIn($p, $filters)->countsTowardGbv()->selectRaw('owner_id, COUNT(*) as v')->groupBy('owner_id')->pluck('v', 'owner_id');
        $platform = $this->subscriptions($p, $filters)->selectRaw('owner_id, SUM(amount_paid) as v')->groupBy('owner_id')->pluck('v', 'owner_id');

        $ids = collect([$bookingEarn->keys(), $sales->keys(), $counts->keys(), $platform->keys()])->flatten()->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }
        $names = Owner::whereIn('id', $ids)->get(['id', 'business_name', 'name'])->keyBy('id');

        return $ids->map(fn ($id) => [
            'owner_id' => (int) $id,
            'name' => $names[$id]->business_name ?? $names[$id]->name ?? '#'.$id,
            'earnings' => round((float) ($bookingEarn[$id] ?? 0) + (float) ($sales[$id] ?? 0), 2),
            'bookings' => (int) ($counts[$id] ?? 0),
            'platform_revenue' => round((float) ($platform[$id] ?? 0), 2),
        ])->filter(fn ($r) => $r[$by] > 0)->sortByDesc($by)->take($limit)->values();
    }

    /** @return Collection<int, array{plan_id: ?int, name: string, revenue: float, renewals: int, active: int, mrr: float}> */
    public function revenueByPlan(AnalyticsPeriod $p, array $filters = []): Collection
    {
        $rev = $this->subscriptions($p, $filters)->selectRaw('plan_id, SUM(amount_paid) as v, COUNT(*) as n')->groupBy('plan_id')->get()->keyBy('plan_id');
        $active = $this->owners($filters)->where('owners.is_active', true)->where('subscription_expires_at', '>', now())
            ->selectRaw('plan_id, COUNT(*) as n')->groupBy('plan_id')->pluck('n', 'plan_id');

        return Plan::orderBy('sort_order')->get(['id', 'name', 'price_per_month'])->map(fn (Plan $plan) => [
            'plan_id' => $plan->id,
            'name' => $plan->name,
            'revenue' => round((float) ($rev[$plan->id]->v ?? 0), 2),
            'renewals' => (int) ($rev[$plan->id]->n ?? 0),
            'active' => (int) ($active[$plan->id] ?? 0),
            'mrr' => round((int) ($active[$plan->id] ?? 0) * (float) $plan->price_per_month, 2),
        ])->filter(fn ($r) => $r['revenue'] > 0 || $r['active'] > 0)->values();
    }

    /** Rooms with the most (non-cancelled) bookings in the period. */
    public function topRooms(AnalyticsPeriod $p, array $filters = [], int $limit = 5): Collection
    {
        $rows = $this->bookingsIn($p, $filters)->countsTowardGbv()->selectRaw('room_id, COUNT(*) as n')->groupBy('room_id')->orderByDesc('n')->limit($limit)->pluck('n', 'room_id');
        $rooms = Room::with('owner:id,business_name,name')->whereIn('id', $rows->keys())->get()->keyBy('id');

        return $rows->map(fn ($n, $id) => ['room' => $rooms[$id] ?? null, 'bookings' => (int) $n])->filter(fn ($r) => $r['room'])->values();
    }

    // ------------------------------------------------------------ helpers

    public function change(float|int|null $cur, float|int|null $old): ?float
    {
        if ($cur === null || $old === null || (float) $old == 0.0) {
            return null;
        }

        return round(((float) $cur - (float) $old) / abs((float) $old) * 100, 1);
    }

    /** Owners narrowed by the filters. */
    public function owners(array $filters = []): Builder
    {
        return Owner::query()
            ->when($filters['owner_id'] ?? null, fn ($q, $id) => $q->where('owners.id', $id))
            ->when($filters['plan_id'] ?? null, fn ($q, $id) => $q->where('owners.plan_id', $id));
    }

    /** Any owner-scoped model narrowed by the filters. */
    public function scoped(Builder $q, array $filters = []): Builder
    {
        $table = $q->getModel()->getTable();

        return $q->when($filters['owner_id'] ?? null, fn ($w, $id) => $w->where($table.'.owner_id', $id))
            ->when($filters['plan_id'] ?? null, fn ($w, $id) => $w->whereIn($table.'.owner_id', Owner::where('plan_id', $id)->select('id')));
    }

    private function bookingsIn(AnalyticsPeriod $p, array $filters): Builder
    {
        return $this->scoped(Booking::query(), $filters)
            ->whereDate('booking_date', '>=', $p->startDate())
            ->whereDate('booking_date', '<=', $p->endDate());
    }

    private function subscriptions(AnalyticsPeriod $p, array $filters): Builder
    {
        return Subscription::query()
            ->whereBetween('subscriptions.created_at', [$p->start, $p->end])
            ->when($filters['owner_id'] ?? null, fn ($q, $id) => $q->where('subscriptions.owner_id', $id))
            ->when($filters['plan_id'] ?? null, fn ($q, $id) => $q->where('subscriptions.plan_id', $id));
    }

    /** @return array<int, array{0: string, 1: string}> [from, to] Y-m-d */
    private function buckets(AnalyticsPeriod $p, string $unit): array
    {
        $out = [];
        $cursor = $p->start->copy()->startOfDay();
        $end = $p->end->copy()->startOfDay();
        while ($cursor->lte($end)) {
            $to = match ($unit) {
                'day' => $cursor->copy(),
                'week' => $cursor->copy()->addDays(6),
                default => $cursor->copy()->endOfMonth()->startOfDay(),
            };
            if ($to->gt($end)) {
                $to = $end->copy();
            }
            $out[] = [$cursor->toDateString(), $to->toDateString()];
            $cursor = $to->copy()->addDay();
        }

        return $out;
    }

    private function bucketLabel(array $b, string $unit): string
    {
        $from = Carbon::parse($b[0]);

        return match ($unit) {
            'day' => $from->translatedFormat('M j'),
            'week' => $from->translatedFormat('M j').' – '.Carbon::parse($b[1])->translatedFormat('M j'),
            default => $from->translatedFormat('M Y'),
        };
    }
}
