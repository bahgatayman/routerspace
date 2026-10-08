<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesPeriod;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Booking;
use App\Models\Owner;
use App\Models\Product;
use App\Models\Room;
use App\Models\SaleItem;
use App\Models\StaffActivityLog;
use App\Models\Workspace;
use App\Services\Admin\BusinessOverviewService;
use App\Services\Admin\PlatformAnalyticsService;
use App\Services\ExpenseAnalyticsService;
use App\Services\RevenueAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Super Admin → one business (Owner = tenant) in one place. Every query is
 * keyed to this owner; a `?workspace=` location filter, a product or a room
 * is only accepted when it belongs to it (another owner's id → 404).
 */
class BusinessController extends Controller
{
    use ResolvesPeriod;

    public function show(Request $request, int $owner, BusinessOverviewService $overview): View
    {
        $owner = $this->owner($owner);
        $workspace = $this->location($request, $owner);

        return view('admin.business.overview', [
            'owner' => $owner,
            'workspace' => $workspace,
            'summary' => $overview->summary($owner, $workspace),
            'health' => $overview->health($owner),
            'activity' => $overview->recentActivity($owner),
        ]);
    }

    public function products(Request $request, int $owner): View
    {
        $owner = $this->owner($owner);
        [$period, $range] = $this->resolvePeriod($request);
        $search = trim((string) $request->query('q', ''));
        $stock = in_array($request->query('stock'), ['low', 'out', 'inactive'], true) ? $request->query('stock') : null;
        $type = in_array($request->query('type'), ['product', 'service'], true) ? $request->query('type') : null;

        // Units / revenue sold in the period, one grouped query for the page.
        $soldIn = fn ($q) => $q->whereIn('sale_id', DB::table('sales')->where('owner_id', $owner->id)->where('status', 'completed')
            ->whereBetween('sold_at', [$period->start, $period->end])->select('id'));

        $products = Product::where('owner_id', $owner->id)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$search.'%')->orWhere('sku', 'like', '%'.$search.'%')))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($stock === 'low', fn ($q) => $q->lowStock())
            ->when($stock === 'out', fn ($q) => $q->where('track_stock', true)->where('type', 'product')->where('stock_quantity', '<=', 0))
            ->when($stock === 'inactive', fn ($q) => $q->where('is_active', false))
            ->withSum(['saleItems as sold_qty' => $soldIn], 'quantity')
            ->withSum(['saleItems as sold_revenue' => $soldIn], 'line_total')
            ->orderByDesc('sold_revenue')->orderBy('name')
            ->paginate(25)->withQueryString();

        $all = Product::where('owner_id', $owner->id);
        $stats = [
            'total' => (clone $all)->count(),
            'active' => (clone $all)->where('is_active', true)->count(),
            'low' => (clone $all)->where('is_active', true)->lowStock()->count(),
            'out' => (clone $all)->where('is_active', true)->where('track_stock', true)->where('type', 'product')->where('stock_quantity', '<=', 0)->count(),
            'inventory_value' => (float) (clone $all)->where('track_stock', true)->where('type', 'product')->where('stock_quantity', '>', 0)->sum(DB::raw('stock_quantity * COALESCE(purchase_price, 0)')),
            'sales' => app(RevenueAnalyticsService::class)->saleRevenue($owner, $period),
        ];

        return view('admin.business.products', compact('owner', 'products', 'stats', 'range') + [
            'workspace' => null, 'filters' => compact('search', 'stock', 'type'),
        ]);
    }

    public function product(Request $request, int $owner, int $product): View
    {
        $owner = $this->owner($owner);
        $product = Product::where('owner_id', $owner->id)->findOrFail($product);
        [$period, $range] = $this->resolvePeriod($request);

        $items = SaleItem::query()->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.owner_id', $owner->id)->where('sale_items.product_id', $product->id)->where('sales.status', 'completed');
        $inPeriod = (clone $items)->whereBetween('sales.sold_at', [$period->start, $period->end]);

        return view('admin.business.product', [
            'owner' => $owner, 'workspace' => null, 'product' => $product, 'range' => $range,
            'stats' => [
                'qty' => (int) (clone $inPeriod)->sum('sale_items.quantity'),
                'revenue' => (float) (clone $inPeriod)->sum('sale_items.line_total'),
                'cost' => (float) (clone $inPeriod)->sum(DB::raw('sale_items.quantity * COALESCE(sale_items.unit_cost, 0)')),
                'qty_all' => (int) (clone $items)->sum('sale_items.quantity'),
                'revenue_all' => (float) (clone $items)->sum('sale_items.line_total'),
            ],
            'recent' => (clone $items)->select('sale_items.*', 'sales.sold_at', 'sales.booking_id')->orderByDesc('sales.sold_at')->take(15)->get(),
            'movements' => $product->movements()->take(15)->get(),
        ]);
    }

    public function rooms(Request $request, int $owner): View
    {
        $owner = $this->owner($owner);
        $workspace = $this->location($request, $owner);
        [$period, $range] = $this->resolvePeriod($request);
        $inPeriod = fn ($q) => $q->whereDate('booking_date', '>=', $period->startDate())->whereDate('booking_date', '<=', $period->endDate());

        $rooms = Room::where('owner_id', $owner->id)->with('workspace:id,name')
            ->when($workspace, fn ($q) => $q->where('workspace_id', $workspace->id))
            ->withCount(['bookings as period_bookings' => fn ($q) => $inPeriod($q)->countsTowardGbv()])
            ->withSum(['bookings as period_earnings' => fn ($q) => $inPeriod($q)->revenueRecognised()], 'amount_paid')
            ->withSum(['bookings as period_hours' => fn ($q) => $inPeriod($q)->revenueRecognised()], 'total_hours')
            ->orderBy('workspace_id')->orderBy('name')
            ->get();

        return view('admin.business.rooms', compact('owner', 'workspace', 'rooms', 'range'));
    }

    public function bookings(Request $request, int $owner): View
    {
        $owner = $this->owner($owner);
        $workspace = $this->location($request, $owner);
        [$period, $range] = $this->resolvePeriod($request);
        $status = in_array($request->query('status'), BookingController::STATUSES, true) ? $request->query('status') : null;

        $base = Booking::where('owner_id', $owner->id)
            ->whereDate('booking_date', '>=', $period->startDate())->whereDate('booking_date', '<=', $period->endDate())
            ->when($workspace, fn ($q) => $q->whereIn('room_id', Room::where('owner_id', $owner->id)->where('workspace_id', $workspace->id)->select('id')));

        $byStatus = (clone $base)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $stats = [
            'total' => (int) $byStatus->sum(),
            'by_status' => $byStatus,
            'gbv' => (float) (clone $base)->countsTowardGbv()->sum(DB::raw(Booking::GBV_SQL)),
            'collected' => (float) (clone $base)->revenueRecognised()->sum('amount_paid'),
            'outstanding' => (float) (clone $base)->outstanding()->sum(DB::raw(Booking::OUTSTANDING_SQL)),
        ];

        $bookings = (clone $base)->when($status, fn ($q) => $q->where('status', $status))
            ->with(['owner:id,business_name', 'room:id,name,workspace_id', 'room.workspace:id,name', 'hotspotUser:id,name,phone'])
            ->withExists('sharedSession')
            ->orderByDesc('booking_date')->orderByDesc('start_time')
            ->paginate(25)->withQueryString();

        return view('admin.business.bookings', compact('owner', 'workspace', 'bookings', 'stats', 'range', 'status'));
    }

    public function financials(Request $request, int $owner, PlatformAnalyticsService $analytics, RevenueAnalyticsService $revenue, ExpenseAnalyticsService $expenses): View
    {
        $owner = $this->owner($owner);
        [$period, $range] = $this->resolvePeriod($request);
        $f = ['owner_id' => $owner->id];
        $prev = $period->previous();

        $earnings = $analytics->earnings($period, $f);
        $spent = $expenses->totalExpenses($owner, $period);
        $cards = [
            'earnings' => ['value' => $earnings, 'change' => $analytics->change($earnings, $analytics->earnings($prev, $f))],
            'booking_earnings' => ['value' => $analytics->bookingEarnings($period, $f), 'change' => null],
            'sales' => ['value' => $analytics->salesEarnings($period, $f), 'change' => null],
            'expenses' => ['value' => $spent, 'change' => $analytics->change($spent, $expenses->totalExpenses($owner, $prev))],
            'net' => ['value' => round($earnings - $spent, 2), 'change' => null],
            'gbv' => ['value' => $analytics->gbv($period, $f), 'change' => null],
            'outstanding' => ['value' => $analytics->outstanding($f), 'change' => null],
            'discounts' => ['value' => $analytics->discounts($period, $f), 'change' => null],
            'package_value' => ['value' => $analytics->packageValue($period, $f), 'change' => null],
            'platform_revenue' => ['value' => $analytics->platformRevenue($period, $f), 'change' => null],
        ];

        $series = $analytics->series($period, $f);
        $t = fn (string $k) => __('app.admin_platform.'.$k);
        $chart = [
            'type' => 'bar', 'stacked' => true, 'money' => true, 'axis' => $t('chart.period'), 'labels' => $series['labels'],
            'datasets' => [
                ['label' => $t('kpi.booking_earnings'), 'data' => $series['series']['booking_earnings'], 'color' => 'c1'],
                ['label' => $t('kpi.sales'), 'data' => $series['series']['sales'], 'color' => 'c2'],
            ],
            'links' => array_map(fn ($k) => "/admin/owners/{$owner->id}/bookings?preset=custom&from=".explode('|', $k)[0].'&to='.explode('|', $k)[1], $series['keys']),
        ];

        return view('admin.business.financials', [
            'owner' => $owner, 'workspace' => null, 'range' => $range, 'cards' => $cards, 'chart' => $chart,
            'byRoom' => $revenue->revenueByRoom($owner, $period),
            'byProduct' => array_slice($revenue->revenueByProduct($owner, $period), 0, 10),
            'byCategory' => $expenses->expensesByCategory($owner, $period),
        ]);
    }

    public function activity(Request $request, int $owner): View
    {
        $owner = $this->owner($owner);
        $actor = in_array($request->query('actor'), ['owner', 'staff'], true) ? $request->query('actor') : null;

        return view('admin.business.activity', [
            'owner' => $owner, 'workspace' => null, 'actor' => $actor,
            'logs' => StaffActivityLog::where('owner_id', $owner->id)
                ->when($actor, fn ($q) => $q->where('actor_type', $actor))
                ->latest('created_at')->paginate(30)->withQueryString(),
        ]);
    }

    /** Super Admin actions taken on this business (audit trail). */
    public function audit(int $owner): View
    {
        $owner = $this->owner($owner);

        return view('admin.business.audit', [
            'owner' => $owner,
            'workspace' => null,
            'logs' => AdminAuditLog::where('owner_id', $owner->id)->latest('created_at')->paginate(25),
        ]);
    }

    private function owner(int $id): Owner
    {
        return Owner::with(['plan', 'workspaces' => fn ($q) => $q->orderBy('name')])->findOrFail($id);
    }

    /** The selected location, only if it belongs to this owner. */
    private function location(Request $request, Owner $owner): ?Workspace
    {
        $id = $request->integer('workspace');

        return $id ? Workspace::where('owner_id', $owner->id)->findOrFail($id) : null;
    }
}
