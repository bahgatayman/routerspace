<?php

namespace App\Services\Admin;

use App\Models\Booking;
use App\Models\Owner;
use App\Models\Room;
use App\Models\Sale;
use App\Models\Subscription;
use App\Services\AnalyticsPeriod;
use Illuminate\Support\Collection;

/**
 * Super Admin → rule-based insights. Every item is derived from a grouped
 * query over real data and states what happened, the metric behind it, why
 * it matters, a suggested action and where to act. Rules only fire past a
 * minimum volume, so a business with two bookings is never "declining".
 * Nothing is predicted or estimated.
 */
class PlatformInsightsService
{
    public const CANCEL_RATE = 25.0;   // % of bookings cancelled / no-show

    public const CANCEL_MIN = 10;      // bookings in the period before the rate means anything

    public const DECLINE_PCT = 40.0;   // % drop in earnings vs the previous period

    public const DECLINE_MIN = 500.0;  // previous-period earnings (EGP) before a drop is meaningful

    public function __construct(private PlatformAnalyticsService $analytics) {}

    /**
     * @return array{items: array<int, array{level: string, key: string, title: string, metric: string, why: string, action: string, url: ?string}>, untracked: array<int, string>}
     */
    public function forPeriod(AnalyticsPeriod $period, array $filters = []): array
    {
        $items = [];
        $add = function (string $level, string $key, array $r, ?string $url) use (&$items) {
            $t = fn (string $part) => __("app.admin_platform.insights.{$key}.{$part}", $r);
            $items[] = ['level' => $level, 'key' => $key, 'title' => $t('title'), 'metric' => $t('metric'), 'why' => $t('why'), 'action' => $t('action'), 'url' => $url];
        };
        $money = fn ($v) => number_format((float) $v, 2);
        $biz = fn (int $id) => '/admin/owners/'.$id;

        // ---- risks first

        $expired = $this->expiredWithoutRenewal($filters);
        if ($expired->isNotEmpty()) {
            $add('danger', 'expired', ['count' => $expired->count(), 'names' => $this->names($expired)], '/admin/workspaces?status=expired');
        }

        $expiring = $this->analytics->owners($filters)->where('is_active', true)
            ->whereBetween('subscription_expires_at', [now(), now()->addDays(7)])->get(['id', 'business_name', 'name']);
        if ($expiring->isNotEmpty()) {
            $add('warning', 'expiring', ['count' => $expiring->count(), 'names' => $this->names($expiring)], '/admin/workspaces?status=expiring');
        }

        foreach ($this->declining($period, $filters) as $row) {
            $add('warning', 'declining', ['name' => $row['name'], 'pct' => $row['pct'], 'now' => $money($row['now']), 'before' => $money($row['before'])], $biz($row['owner_id']));
        }

        foreach ($this->highCancellation($period, $filters) as $row) {
            $add('warning', 'cancellations', ['name' => $row['name'], 'rate' => $row['rate'], 'cancelled' => $row['cancelled'], 'total' => $row['total']], '/admin/bookings?owner='.$row['owner_id'].'&status=cancelled');
        }

        $noRooms = $this->analytics->owners($filters)->where('is_active', true)->doesntHave('rooms')->get(['id', 'business_name', 'name']);
        if ($noRooms->isNotEmpty()) {
            $add('info', 'no_rooms', ['count' => $noRooms->count(), 'names' => $this->names($noRooms)], '/admin/workspaces?sort=rooms&dir=asc');
        }

        $quiet = $this->activeWithoutBookings($period, $filters);
        if ($quiet->isNotEmpty()) {
            $add('info', 'no_bookings', ['count' => $quiet->count(), 'names' => $this->names($quiet)], '/admin/workspaces?sort=bookings&dir=asc');
        }

        $idle = $this->idleRooms($period, $filters);
        if ($idle > 0) {
            $add('info', 'idle_rooms', ['count' => $idle], '/admin/rooms?idle=1&preset=custom&from='.$period->startDate().'&to='.$period->endDate());
        }

        // ---- what is working

        $top = $this->analytics->topWorkspaces($period, $filters, 1)->first();
        if ($top) {
            $add('positive', 'top_earnings', ['name' => $top['name'], 'amount' => $money($top['earnings'])], $biz($top['owner_id']));
        }
        $topB = $this->analytics->topWorkspaces($period, $filters, 1, 'bookings')->first();
        if ($topB && (! $top || $topB['owner_id'] !== $top['owner_id'])) {
            $add('positive', 'top_bookings', ['name' => $topB['name'], 'count' => $topB['bookings']], $biz($topB['owner_id']));
        }

        $room = $this->analytics->topRooms($period, $filters, 1)->first();
        if ($room) {
            $add('positive', 'top_room', ['room' => $room['room']->name, 'name' => $room['room']->owner?->business_name ?? '', 'count' => $room['bookings']], '/admin/rooms/'.$room['room']->id);
        }

        $plans = $this->analytics->revenueByPlan($period, $filters);
        $byActive = $plans->sortByDesc('active')->first();
        if ($byActive && $byActive['active'] > 0) {
            $add('positive', 'top_plan', ['plan' => $byActive['name'], 'count' => $byActive['active'], 'mrr' => $money($byActive['mrr'])], '/admin/plans/'.$byActive['plan_id']);
        }
        $byRevenue = $plans->sortByDesc('revenue')->first();
        if ($byRevenue && $byRevenue['revenue'] > 0 && $byRevenue['plan_id'] !== $byActive['plan_id']) {
            $add('positive', 'top_plan_revenue', ['plan' => $byRevenue['name'], 'amount' => $money($byRevenue['revenue'])], '/admin/plans/'.$byRevenue['plan_id']);
        }

        return [
            'items' => $items,
            'untracked' => ['feature_usage', 'failed_payments', 'refunds', 'commissions', 'logins'],
        ];
    }

    /** Businesses whose subscription ended and no renewal was recorded after it. */
    public function expiredWithoutRenewal(array $filters = []): Collection
    {
        return $this->analytics->owners($filters)
            ->where('subscription_expires_at', '<', now())
            ->whereNotExists(fn ($q) => $q->from('subscriptions')->whereColumn('subscriptions.owner_id', 'owners.id')
                ->whereColumn('subscriptions.created_at', '>', 'owners.subscription_expires_at'))
            ->get(['id', 'business_name', 'name']);
    }

    /** Earnings down ≥ DECLINE_PCT vs the previous equal period (two grouped queries per period). */
    public function declining(AnalyticsPeriod $period, array $filters = [], int $limit = 3): Collection
    {
        $now = $this->earningsByOwner($period, $filters);
        $before = $this->earningsByOwner($period->previous(), $filters);

        $rows = $before->filter(fn ($v) => $v >= self::DECLINE_MIN)->map(function ($v, $id) use ($now) {
            $cur = (float) ($now[$id] ?? 0);

            return ['owner_id' => (int) $id, 'now' => $cur, 'before' => $v, 'pct' => round(($v - $cur) / $v * 100)];
        })->filter(fn ($r) => $r['pct'] >= self::DECLINE_PCT)->sortByDesc('pct')->take($limit);

        return $this->withNames($rows);
    }

    public function highCancellation(AnalyticsPeriod $period, array $filters = [], int $limit = 3): Collection
    {
        $rows = $this->analytics->scoped(Booking::query(), $filters)
            ->whereDateBetween('booking_date', $period->startDate(), $period->endDate())
            ->selectRaw("owner_id, COUNT(*) as total, SUM(CASE WHEN status IN ('cancelled','no_show') THEN 1 ELSE 0 END) as cancelled")
            ->groupBy('owner_id')->having('total', '>=', self::CANCEL_MIN)->get()
            ->map(fn ($r) => ['owner_id' => (int) $r->owner_id, 'total' => (int) $r->total, 'cancelled' => (int) $r->cancelled, 'rate' => round($r->cancelled / $r->total * 100, 1)])
            ->filter(fn ($r) => $r['rate'] >= self::CANCEL_RATE)->sortByDesc('rate')->take($limit);

        return $this->withNames($rows);
    }

    /** Active, subscribed businesses that have rooms but no booking in the period. */
    public function activeWithoutBookings(AnalyticsPeriod $period, array $filters = []): Collection
    {
        return $this->analytics->owners($filters)->where('is_active', true)->where('subscription_expires_at', '>', now())
            ->has('rooms')
            ->whereDoesntHave('bookings', fn ($q) => $q->whereDateBetween('booking_date', $period->startDate(), $period->endDate()))
            ->get(['id', 'business_name', 'name']);
    }

    /** Bookable rooms of active businesses with no booking at all in the period. */
    public function idleRooms(AnalyticsPeriod $period, array $filters = []): int
    {
        return $this->analytics->scoped(Room::query(), $filters)->where('is_available', true)
            ->whereIn('owner_id', Owner::where('is_active', true)->where('subscription_expires_at', '>', now())->select('id'))
            ->whereDoesntHave('bookings', fn ($q) => $q->whereDateBetween('booking_date', $period->startDate(), $period->endDate()))
            ->count();
    }

    private function earningsByOwner(AnalyticsPeriod $p, array $filters): Collection
    {
        $bookings = $this->analytics->scoped(Booking::query(), $filters)->revenueRecognised()
            ->whereDateBetween('booking_date', $p->startDate(), $p->endDate())
            ->selectRaw('owner_id, SUM(amount_paid) as v')->groupBy('owner_id')->pluck('v', 'owner_id');
        $sales = $this->analytics->scoped(Sale::query(), $filters)->completed()->whereBetween('sold_at', [$p->start, $p->end])
            ->selectRaw('owner_id, SUM(total) as v')->groupBy('owner_id')->pluck('v', 'owner_id');

        return $bookings->keys()->merge($sales->keys())->unique()
            ->mapWithKeys(fn ($id) => [$id => (float) ($bookings[$id] ?? 0) + (float) ($sales[$id] ?? 0)]);
    }

    private function withNames(Collection $rows): Collection
    {
        $names = Owner::whereIn('id', $rows->pluck('owner_id'))->get(['id', 'business_name', 'name'])->keyBy('id');

        return $rows->map(fn ($r) => $r + ['name' => $names[$r['owner_id']]->business_name ?? $names[$r['owner_id']]->name ?? '#'.$r['owner_id']])->values();
    }

    private function names(Collection $owners, int $max = 3): string
    {
        $list = $owners->take($max)->map(fn ($o) => $o->business_name ?: $o->name)->implode('، ');

        return $owners->count() > $max ? $list.' +'.($owners->count() - $max) : $list;
    }
}
