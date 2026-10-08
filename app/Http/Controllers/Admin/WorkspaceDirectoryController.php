<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesPeriod;
use App\Http\Controllers\Controller;
use App\Models\Owner;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Super Admin → Workspaces: the directory of every business (Owner = tenant).
 * One paginated query: counts and period money are aggregate subselects
 * (withCount / withSum), never per-row queries. Sort keys and statuses are
 * whitelisted; the status rules mirror Owner::subscriptionStatus().
 */
class WorkspaceDirectoryController extends Controller
{
    use ResolvesPeriod;

    public const STATUSES = ['active', 'expiring', 'expired', 'suspended', 'never'];

    public const SORTS = [
        'name' => 'business_name', 'created' => 'created_at', 'expires' => 'subscription_expires_at',
        'products' => 'products_count', 'rooms' => 'rooms_count', 'locations' => 'locations_count',
        'bookings' => 'period_bookings', 'earnings' => 'period_earnings', 'activity' => 'last_booking_at',
    ];

    public function index(Request $request): View
    {
        [$period, $range] = $this->resolvePeriod($request);
        $search = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : null;
        $planId = Plan::whereKey($request->integer('plan'))->value('id');
        $sort = array_key_exists($request->query('sort'), self::SORTS) ? $request->query('sort') : 'created';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $joinedFrom = $this->validDate($request->query('joined_from'));
        $joinedTo = $this->validDate($request->query('joined_to'));

        $inPeriod = fn ($q) => $q->whereDate('booking_date', '>=', $period->startDate())->whereDate('booking_date', '<=', $period->endDate());

        $base = Owner::query()
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $w->where('business_name', 'like', $like)->orWhere('name', 'like', $like)->orWhere('email', 'like', $like);
                if (ctype_digit($search)) {
                    $w->orWhere('id', (int) $search);
                }
            }))
            ->when($planId, fn ($q) => $q->where('plan_id', $planId))
            ->when($joinedFrom, fn ($q) => $q->whereDate('created_at', '>=', $joinedFrom))
            ->when($joinedTo, fn ($q) => $q->whereDate('created_at', '<=', $joinedTo));

        $counts = collect(self::STATUSES)->mapWithKeys(fn ($s) => [$s => $this->status(clone $base, $s)->count()]);

        $owners = $this->status($base, $status)
            ->with('plan:id,name')
            ->withCount(['products', 'rooms', 'workspaces as locations_count', 'hotspotUsers as members_count'])
            ->withCount(['bookings as period_bookings' => fn ($q) => $inPeriod($q)->countsTowardGbv()])
            ->withSum(['bookings as booking_earnings' => fn ($q) => $inPeriod($q)->revenueRecognised()], 'amount_paid')
            ->withSum(['sales as sales_earnings' => fn ($q) => $q->completed()->whereBetween('sold_at', [$period->start, $period->end])], 'total')
            ->withMax('bookings as last_booking_at', 'created_at')
            ->when($sort === 'earnings',
                fn ($q) => $q->orderByRaw('COALESCE(booking_earnings, 0) + COALESCE(sales_earnings, 0) '.$dir),
                fn ($q) => $q->orderBy(self::SORTS[$sort], $dir))
            ->orderBy('id', 'desc')
            ->paginate(20)
            ->withQueryString();

        return view('admin.workspaces.index', [
            'owners' => $owners,
            'counts' => $counts,
            'total' => $counts->sum(),
            'plans' => Plan::orderBy('sort_order')->get(['id', 'name']),
            'filters' => ['q' => $search, 'status' => $status, 'plan' => $planId, 'joined_from' => $joinedFrom, 'joined_to' => $joinedTo],
            'sort' => $sort,
            'dir' => $dir,
            'range' => $range,
        ]);
    }

    private function status(Builder $q, ?string $status): Builder
    {
        $soon = now()->addDays(8);

        return match ($status) {
            'never' => $q->whereNull('subscription_expires_at'),
            'suspended' => $q->whereNotNull('subscription_expires_at')->where('is_active', false),
            'expired' => $q->where('is_active', true)->where('subscription_expires_at', '<=', now()),
            'expiring' => $q->where('is_active', true)->where('subscription_expires_at', '>', now())->where('subscription_expires_at', '<', $soon),
            'active' => $q->where('is_active', true)->where('subscription_expires_at', '>=', $soon),
            default => $q,
        };
    }
}
