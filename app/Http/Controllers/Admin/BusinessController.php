<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\BusinessHeaderProps;
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
use App\Services\ProductAnalyticsService;
use App\Services\RevenueAnalyticsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super Admin → one business (Owner = tenant) in one place. Every query is
 * keyed to this owner; a `?workspace=` location filter, a product or a room
 * is only accepted when it belongs to it (another owner's id → 404).
 */
class BusinessController extends Controller
{
    use BusinessHeaderProps, ResolvesPeriod;

    public function show(Request $request, int $owner, BusinessOverviewService $overview): Response
    {
        $owner = $this->owner($owner);
        $workspace = $this->location($request, $owner);

        return Inertia::render('Admin/Business/Overview', [
            'business' => $this->businessHeader($owner),
            'workspace' => $workspace?->only(['id', 'name']),
            'maxMembers' => (int) ($owner->plan?->max_members ?? 0),
            'summary' => $overview->summary($owner, $workspace),
            'health' => $overview->health($owner),
            'activity' => $overview->recentActivity($owner)->map(fn ($a) => [
                'at' => $a['at']?->translatedFormat('M j · g:i A'),
                'at_iso' => $a['at']?->toIso8601String(),
                'actor' => $a['actor'],
                'text' => $a['text'],
                'kind' => $a['kind'],
                'url' => $a['url'],
            ])->all(),
            'locations' => $owner->workspaces->map(fn (Workspace $ws) => [
                'id' => $ws->id,
                'name' => $ws->name,
                'details' => collect([$ws->city, $ws->address, $ws->phone])->filter()->implode(' · ') ?: '—',
                'is_active' => (bool) $ws->is_active,
            ])->all(),
        ]);
    }

    public function products(Request $request, int $owner, ProductAnalyticsService $productAnalytics): Response
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

        // Reuses the same ProductAnalyticsService built for the Owner
        // Dashboard, read-only, scoped to this one owner — not a 2nd
        // implementation of product analytics for the admin side.
        $productSummary = $productAnalytics->summary($owner, $period);
        $productSeries = $productAnalytics->dailySeries($owner, $period);
        $topProducts = array_slice($productAnalytics->topProducts($owner, $period), 0, 10);
        $productInsights = $productAnalytics->insights($owner, $period);

        $trendDates = array_keys($productSeries);
        $productTrendChart = [
            'type' => 'line', 'money' => true, 'axis' => __('app.dashboard.metric_revenue'),
            'labels' => array_map(fn ($d) => Carbon::parse($d)->format('j M'), $trendDates),
            'datasets' => [[
                'label' => __('app.dashboard.metric_revenue'),
                'data' => array_map(fn ($d) => $productSeries[$d]['revenue'], $trendDates),
                'color' => 'c1',
            ]],
        ];

        $topProductsChart = [
            'type' => 'bar', 'horizontal' => true, 'money' => true, 'axis' => __('app.dashboard.top_selling_products'),
            'labels' => array_map(fn ($p) => $p['name'], $topProducts),
            'datasets' => [[
                'label' => __('app.dashboard.metric_revenue'),
                'data' => array_map(fn ($p) => $p['revenue'], $topProducts),
                'color' => 'c1',
            ]],
            'links' => array_map(fn ($p) => "/admin/owners/{$owner->id}/products/{$p['product_id']}", $topProducts),
        ];

        return Inertia::render('Admin/Business/Products', [
            'business' => $this->businessHeader($owner),
            'products' => $products->through(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'kind' => $p->isService() ? 'service' : 'product',
                'price' => (float) $p->price,
                'purchase_price' => $p->purchase_price !== null ? (float) $p->purchase_price : null,
                'tracks_stock' => $p->tracksStock(),
                'stock_status' => $p->stockStatus(),
                'stock_quantity' => (int) $p->stock_quantity,
                'sold_qty' => (int) $p->sold_qty,
                'sold_revenue' => (float) $p->sold_revenue,
                'is_active' => (bool) $p->is_active,
            ]),
            'stats' => $stats,
            'range' => $range,
            'hasProductSales' => collect($productSeries)->sum('units') > 0,
            'productSummary' => [
                'topProduct' => $productSummary['topProduct']['name'] ?? null,
                'totalUnits' => $productSummary['totalUnits'],
                'avgOrderValue' => $productSummary['avgOrderValue'],
                'orderCount' => $productSummary['orderCount'],
            ],
            'productTrendChart' => $productTrendChart,
            'topProductsChart' => $topProductsChart,
            'productInsights' => array_values(array_map(fn ($i) => ['text' => $i['text']], $productInsights)),
            'filters' => ['q' => $search, 'stock' => $stock, 'type' => $type],
        ]);
    }

    public function product(Request $request, int $owner, int $product): Response
    {
        $owner = $this->owner($owner);
        $product = Product::where('owner_id', $owner->id)->findOrFail($product);
        [$period, $range] = $this->resolvePeriod($request);

        $items = SaleItem::query()->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.owner_id', $owner->id)->where('sale_items.product_id', $product->id)->where('sales.status', 'completed');
        $inPeriod = (clone $items)->whereBetween('sales.sold_at', [$period->start, $period->end]);

        $stats = [
            'qty' => (int) (clone $inPeriod)->sum('sale_items.quantity'),
            'revenue' => (float) (clone $inPeriod)->sum('sale_items.line_total'),
            'cost' => (float) (clone $inPeriod)->sum(DB::raw('sale_items.quantity * COALESCE(sale_items.unit_cost, 0)')),
            'qty_all' => (int) (clone $items)->sum('sale_items.quantity'),
            'revenue_all' => (float) (clone $items)->sum('sale_items.line_total'),
        ];
        $stats['profit'] = $stats['revenue'] - $stats['cost'];

        return Inertia::render('Admin/Business/Product', [
            'business' => $this->businessHeader($owner),
            'range' => $range,
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'description' => $product->description,
                'kind' => $product->isService() ? 'service' : 'product',
                'tracks_stock' => $product->tracksStock(),
                'stock_status' => $product->stockStatus(),
                'stock_quantity' => (int) $product->stock_quantity,
                'low_stock_threshold' => $product->low_stock_threshold,
                'is_active' => (bool) $product->is_active,
                'price' => (float) $product->price,
                'margin' => $product->marginPercent() !== null && $product->purchase_price !== null ? $product->marginPercent() : null,
            ],
            'stats' => $stats,
            'recent' => (clone $items)->select('sale_items.*', 'sales.sold_at', 'sales.booking_id')->orderByDesc('sales.sold_at')->take(15)->get()
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'date' => Carbon::parse($i->sold_at)->translatedFormat('M j, Y g:i A'),
                    'quantity' => $i->quantity,
                    'line_total' => (float) $i->line_total,
                    'booking_id' => $i->booking_id,
                ])->all(),
            'movements' => $product->movements()->take(15)->get()->map(fn ($m) => [
                'id' => $m->id,
                'date' => $m->created_at?->translatedFormat('M j, Y g:i A'),
                'type' => Lang::has('app.inventory.movement.'.$m->type) ? __('app.inventory.movement.'.$m->type) : ucfirst(str_replace('_', ' ', $m->type)),
                'note' => $m->note,
                'change' => ($m->quantity_change > 0 ? '+' : '').$m->quantity_change,
                'new_quantity' => $m->new_quantity,
            ])->all(),
        ]);
    }

    public function rooms(Request $request, int $owner): Response
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

        return Inertia::render('Admin/Business/Rooms', [
            'business' => $this->businessHeader($owner),
            'workspace' => $workspace?->only(['id', 'name']),
            'range' => $range,
            'rooms' => $rooms->map(fn (Room $room) => [
                'id' => $room->id,
                'name' => $room->name,
                'location' => $room->workspace?->name,
                'type' => $room->typeLabel(),
                'capacity' => $room->capacity,
                'pricing' => $room->pricingSummary(),
                'period_bookings' => (int) $room->period_bookings,
                'period_hours' => (float) $room->period_hours,
                'period_earnings' => (float) $room->period_earnings,
                'is_available' => (bool) $room->is_available,
            ])->all(),
        ]);
    }

    public function bookings(Request $request, int $owner): Response
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
            'gbv' => (float) (clone $base)->countsTowardGbv()->sum(DB::raw(Booking::GBV_SQL)),
            'collected' => (float) (clone $base)->revenueRecognised()->sum('amount_paid'),
            'outstanding' => (float) (clone $base)->outstanding()->sum(DB::raw(Booking::OUTSTANDING_SQL)),
        ];

        // Same doughnut shape/colors as the platform dashboard's status chart
        // (admin/dashboard), just scoped to this one owner (+ workspace, if
        // filtered) instead of platform-wide — reuses $byStatus above rather
        // than a 2nd query, and links back to this same page's status chips.
        $statusColors = ['completed' => 'success', 'confirmed' => 'info', 'pending' => 'warning', 'checked_in' => 'c3', 'open' => 'c3', 'cancelled' => 'danger', 'no_show' => 'neutral'];
        // ->values() re-indexes after filter() — without it, the gappy keys
        // left behind (e.g. 1, 4, 5, 6) survive into ->map()->all() and
        // json_encode() turns that into a JS object instead of an array.
        $presentStatuses = collect(BookingController::STATUSES)->filter(fn ($s) => ($byStatus[$s] ?? 0) > 0)->values();
        $chip = fn (?string $s) => $request->fullUrlWithQuery(['status' => $s, 'page' => null]);
        $statusChart = [
            'type' => 'doughnut', 'axis' => __('app.common.status'),
            'labels' => $presentStatuses->map(fn ($s) => __('app.admin_platform.status.'.$s))->all(),
            'datasets' => [[
                'label' => __('app.nav.bookings'),
                'data' => $presentStatuses->map(fn ($s) => (int) $byStatus[$s])->all(),
                'color' => $presentStatuses->map(fn ($s) => $statusColors[$s] ?? 'neutral')->all(),
            ]],
            'links' => $presentStatuses->map(fn ($s) => $chip($s))->all(),
        ];

        $bookings = (clone $base)->when($status, fn ($q) => $q->where('status', $status))
            ->with(['owner:id,business_name', 'room:id,name,workspace_id', 'room.workspace:id,name', 'hotspotUser:id,name,phone'])
            ->withExists('sharedSession')
            ->orderByDesc('booking_date')->orderByDesc('start_time')
            ->paginate(25)->withQueryString();

        return Inertia::render('Admin/Business/Bookings', [
            'business' => $this->businessHeader($owner),
            'workspace' => $workspace?->only(['id', 'name']),
            'range' => $range,
            'status' => $status,
            'stats' => $stats,
            'statusChart' => $statusChart,
            'chips' => [
                'all' => $chip(null),
                'statuses' => $presentStatuses->map(fn ($s) => ['key' => $s, 'count' => (int) $byStatus[$s], 'href' => $chip($s)])->all(),
            ],
            'bookings' => $bookings->through(fn (Booking $b) => BookingController::tableRow($b)),
        ]);
    }

    public function financials(Request $request, int $owner, PlatformAnalyticsService $analytics, RevenueAnalyticsService $revenue, ExpenseAnalyticsService $expenses): Response
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

        return Inertia::render('Admin/Business/Financials', [
            'business' => $this->businessHeader($owner),
            'range' => $range,
            'cards' => $cards,
            'chart' => $chart,
            'byRoom' => array_values($revenue->revenueByRoom($owner, $period)),
            'byProduct' => array_values(array_slice($revenue->revenueByProduct($owner, $period), 0, 10)),
            'byCategory' => array_values($expenses->expensesByCategory($owner, $period)),
        ]);
    }

    public function activity(Request $request, int $owner): Response
    {
        $owner = $this->owner($owner);
        $actor = in_array($request->query('actor'), ['owner', 'staff'], true) ? $request->query('actor') : null;

        return Inertia::render('Admin/Business/Activity', [
            'business' => $this->businessHeader($owner),
            'actor' => $actor,
            'actorChips' => collect([['key' => null, 'label' => __('app.common.all')], ['key' => 'owner', 'label' => __('app.admin_biz.owner')], ['key' => 'staff', 'label' => __('app.admin_biz.staff_member')]])
                ->map(fn ($c) => $c + ['href' => $request->fullUrlWithQuery(['actor' => $c['key'], 'page' => null])])->all(),
            'logs' => StaffActivityLog::where('owner_id', $owner->id)
                ->when($actor, fn ($q) => $q->where('actor_type', $actor))
                ->latest('created_at')->paginate(30)->withQueryString()
                ->through(fn (StaffActivityLog $l) => [
                    'id' => $l->id,
                    'kind' => $l->actor_type ?? 'staff',
                    'at' => $l->created_at?->translatedFormat('M j, Y · g:i A'),
                    'at_iso' => $l->created_at?->toIso8601String(),
                    'actor' => $l->actor_name ?: ($l->actor_type === 'owner' ? __('app.admin_biz.owner') : __('app.admin_biz.staff_member')),
                    'text' => $l->description ?: $l->action,
                    'url' => $l->subject_type === Booking::class && $l->subject_id ? '/admin/bookings/'.$l->subject_id : null,
                ]),
        ]);
    }

    /** Super Admin actions taken on this business (audit trail). */
    public function audit(int $owner): Response
    {
        $owner = $this->owner($owner);

        return Inertia::render('Admin/Business/Audit', [
            'business' => $this->businessHeader($owner),
            'logs' => AdminAuditLog::where('owner_id', $owner->id)->latest('created_at')->paginate(25)
                ->through(fn (AdminAuditLog $log) => [
                    'id' => $log->id,
                    'when' => $log->created_at->translatedFormat('M j, Y · g:i A'),
                    'admin' => $log->admin_name ?? '—',
                    'action' => $log->description ?? $log->action,
                    'reason' => $log->reason ?? '—',
                ]),
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
