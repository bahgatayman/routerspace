{{--
    Product Analytics — $productSummary, $productSeries, $topProducts,
    $lowStockProducts, $productInsights (all from ProductAnalyticsService,
    computed in DashboardController for the dashboard's shared period).
    Only included when $showProducts (owner has the 'sales' feature).
--}}
@php
    $hasProductSales = collect($productSeries)->sum('units') > 0;
@endphp
<div class="mb-8">
    <h2 class="font-semibold text-gray-900 mb-4">{{ __('app.dashboard.product_analytics') }}</h2>

    <div class="ls-kpis-wrap mb-6"><div class="ls-kpis ls-kpis--6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div class="min-w-0">
                    <p class="text-xs lg:text-sm font-medium text-gray-500">{{ __('app.dashboard.top_selling_product') }}</p>
                    <p class="text-xl lg:text-2xl font-bold text-gray-900 mt-1 truncate">{{ $productSummary['topProduct']['name'] ?? '—' }}</p>
                    @if ($productSummary['topProduct'])
                        <p class="text-xs text-gray-400 mt-1">{{ $productSummary['topProduct']['units'] }} {{ __('app.dashboard.metric_units') }}</p>
                    @endif
                </div>
                <div class="ls-kpi-icon w-10 h-10 lg:w-12 lg:h-12 bg-amber-50 rounded-xl flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 10l-5.714 2.143L13 19l-2.286-6.857L5 10l5.714-2.143L13 1z"/>
                    </svg>
                </div>
            </div>
        </div>

        <button type="button" data-ls-kpi-metric="units" class="text-left bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs lg:text-sm font-medium text-gray-500">{{ __('app.dashboard.total_units_sold') }}</p>
                    <p class="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{{ $productSummary['totalUnits'] }}</p>
                </div>
                <div class="ls-kpi-icon w-10 h-10 lg:w-12 lg:h-12 bg-blue-50 rounded-xl flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                </div>
            </div>
        </button>

        <button type="button" data-ls-kpi-metric="revenue" class="text-left bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div class="min-w-0">
                    <p class="text-xs lg:text-sm font-medium text-gray-500">{{ __('app.dashboard.product_revenue') }}</p>
                    <p class="text-2xl lg:text-3xl font-bold text-green-600 mt-1 whitespace-nowrap">ج.م {{ number_format($productSummary['productRevenue'], 2) }}</p>
                </div>
                <div class="ls-kpi-icon w-10 h-10 lg:w-12 lg:h-12 bg-green-50 rounded-xl flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
        </button>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div class="min-w-0">
                    <p class="text-xs lg:text-sm font-medium text-gray-500">{{ __('app.dashboard.avg_product_order_value') }}</p>
                    <p class="text-2xl lg:text-3xl font-bold text-gray-900 mt-1 whitespace-nowrap">{{ $productSummary['avgOrderValue'] !== null ? 'ج.م '.number_format($productSummary['avgOrderValue'], 2) : '—' }}</p>
                </div>
                <div class="ls-kpi-icon w-10 h-10 lg:w-12 lg:h-12 bg-purple-50 rounded-xl flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m-10 0a1 1 0 100 2 1 1 0 000-2zm10 0a1 1 0 100 2 1 1 0 000-2z"/>
                    </svg>
                </div>
            </div>
        </div>

        <button type="button" data-ls-kpi-metric="orders" class="text-left bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs lg:text-sm font-medium text-gray-500">{{ __('app.dashboard.product_orders') }}</p>
                    <p class="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{{ $productSummary['orderCount'] }}</p>
                </div>
                <div class="ls-kpi-icon w-10 h-10 lg:w-12 lg:h-12 bg-orange-50 rounded-xl flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
        </button>

        <a href="/products?stock=low" class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6 hover:shadow-md transition block">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs lg:text-sm font-medium text-gray-500">{{ __('app.dashboard.low_stock_products') }}</p>
                    <p class="text-2xl lg:text-3xl font-bold {{ $productSummary['lowStockCount'] > 0 ? 'text-red-600' : 'text-gray-900' }} mt-1">{{ $productSummary['lowStockCount'] }}</p>
                </div>
                <div class="ls-kpi-icon w-10 h-10 lg:w-12 lg:h-12 {{ $productSummary['lowStockCount'] > 0 ? 'bg-red-50' : 'bg-gray-50' }} rounded-xl flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 {{ $productSummary['lowStockCount'] > 0 ? 'text-red-600' : 'text-gray-400' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375C2.754 3.75 2.25 4.254 2.25 4.875v1.5c0 .621.504 1.125 1.125 1.125z"/>
                    </svg>
                </div>
            </div>
        </a>
    </div></div>

    @if (! $hasProductSales)
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-8 text-center text-gray-400 mb-6">
            {{ __('app.dashboard.no_product_sales_for_period') }}
        </div>
    @else
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6 mb-6">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <h3 class="font-semibold text-gray-900">{{ __('app.dashboard.product_sales_trend') }}</h3>
                <div class="inline-flex items-center gap-1 bg-gray-50 border border-gray-100 rounded-lg p-1" role="group">
                    <button type="button" data-ls-product-metric="units" class="is-active px-3 py-1 rounded-md text-xs font-medium transition">{{ __('app.dashboard.metric_units') }}</button>
                    <button type="button" data-ls-product-metric="revenue" class="px-3 py-1 rounded-md text-xs font-medium transition">{{ __('app.dashboard.metric_revenue') }}</button>
                    <button type="button" data-ls-product-metric="orders" class="px-3 py-1 rounded-md text-xs font-medium transition">{{ __('app.dashboard.metric_orders') }}</button>
                </div>
            </div>
            <div style="height: 260px;">
                <canvas id="ls-product-trend-chart" role="img" aria-label="{{ __('app.dashboard.product_sales_trend') }}"></canvas>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 lg:gap-6 mb-6">
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <h3 class="font-semibold text-gray-900">{{ __('app.dashboard.top_selling_products') }}</h3>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="inline-flex items-center gap-1 bg-gray-50 border border-gray-100 rounded-lg p-1" role="group">
                            <button type="button" data-ls-product-view="ranking" class="is-active px-3 py-1 rounded-md text-xs font-medium transition">{{ __('app.dashboard.view_ranking') }}</button>
                            <button type="button" data-ls-product-view="share" class="px-3 py-1 rounded-md text-xs font-medium transition">{{ __('app.dashboard.view_share') }}</button>
                        </div>
                        <div class="inline-flex items-center gap-1 bg-gray-50 border border-gray-100 rounded-lg p-1" role="group" data-ls-rank-group>
                            <button type="button" data-ls-rank-by="revenue" class="is-active px-3 py-1 rounded-md text-xs font-medium transition">{{ __('app.dashboard.metric_revenue') }}</button>
                            <button type="button" data-ls-rank-by="units" class="px-3 py-1 rounded-md text-xs font-medium transition">{{ __('app.dashboard.metric_units') }}</button>
                        </div>
                    </div>
                </div>
                <div style="height: 280px;" data-ls-product-view-panel="ranking">
                    <canvas id="ls-top-products-chart" role="img" aria-label="{{ __('app.dashboard.top_selling_products') }}"></canvas>
                </div>
                <div style="height: 280px; display: none;" data-ls-product-view-panel="share">
                    <canvas id="ls-product-share-chart" role="img" aria-label="{{ __('app.dashboard.product_revenue_share') }}"></canvas>
                </div>
            </div>

            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6">
                <h3 class="font-semibold text-gray-900 mb-4">{{ __('app.dashboard.product_insights') }}</h3>
                @forelse ($productInsights as $insight)
                    <p class="text-sm text-gray-700 py-2 border-b border-gray-50 last:border-0">{{ $insight['text'] }}</p>
                @empty
                    <p class="text-sm text-gray-400">{{ __('app.dashboard.no_insights_yet') }}</p>
                @endforelse
            </div>
        </div>
    @endif

    @if ($lowStockProducts->isNotEmpty())
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6 mb-6">
            <h3 class="font-semibold text-gray-900 mb-4">{{ __('app.dashboard.low_stock_products') }}</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b">
                            <th class="pb-3">{{ __('app.table.th.name') }}</th>
                            <th class="pb-3">{{ __('app.inventory.current_stock') }}</th>
                            <th class="pb-3">{{ __('app.inventory.low_alert') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lowStockProducts as $product)
                            <tr class="border-b last:border-0">
                                <td class="py-3 pe-3 font-medium text-gray-900">{{ $product->name }}</td>
                                <td class="py-3 text-red-600 font-medium">{{ $product->stock_quantity }}</td>
                                <td class="py-3 text-gray-500">{{ $product->low_stock_threshold }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
window.LS_PRODUCT_ANALYTICS = @json([
    'series' => $productSeries,
    'topProducts' => $topProducts,
]);
</script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;
    const data = window.LS_PRODUCT_ANALYTICS;
    if (!data || !data.series) return;

    const dates = Object.keys(data.series);
    if (dates.length === 0) return;

    const metricAccessors = {
        units: (d) => data.series[d].units,
        revenue: (d) => data.series[d].revenue,
        orders: (d) => data.series[d].orders,
    };
    {{--
        @json() splits its expression on every top-level comma to look for
        an optional (options, depth) pair, so an inline array literal with
        more than 2 commas silently gets truncated — hence building the
        array in PHP first and passing a single variable instead.
    --}}
    @php($metricLabels = ['units' => __('app.dashboard.metric_units'), 'revenue' => __('app.dashboard.metric_revenue'), 'orders' => __('app.dashboard.metric_orders'), 'other' => __('app.dashboard.metric_other')])
    const metricLabels = @json($metricLabels);
    const isCoarsePointer = window.matchMedia('(pointer: coarse)').matches;

    function tooltipMode() {
        return isCoarsePointer ? { mode: 'nearest', intersect: true, events: ['click'] } : { mode: 'nearest', intersect: false };
    }

    let activeMetric = 'units';
    const trendCanvas = document.getElementById('ls-product-trend-chart');
    const trendChart = trendCanvas ? new Chart(trendCanvas, {
        type: 'line',
        data: {
            labels: dates.map((d) => new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })),
            datasets: [{ data: dates.map(metricAccessors.units), fill: true, tension: 0.3, borderColor: '#2e4f8f', backgroundColor: 'rgba(46,79,143,.12)', pointRadius: 2 }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 250 },
            scales: { y: { beginAtZero: true } },
            plugins: {
                legend: { display: false },
                tooltip: Object.assign({
                    callbacks: {
                        title: (items) => {
                            const d = dates[items[0].dataIndex];
                            return new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
                        },
                        label: (ctx) => {
                            const d = dates[ctx.dataIndex], row = data.series[d];
                            const money = window.LS ? window.LS.money(row.revenue) : row.revenue;
                            return `${row.units} ${metricLabels.units} · ${money} ${metricLabels.revenue} · ${row.orders} ${metricLabels.orders}`;
                        },
                    },
                }, tooltipMode()),
            },
        },
    }) : null;

    function switchTrendMetric(metric) {
        if (!trendChart) return;
        activeMetric = metric;
        trendChart.data.datasets[0].data = dates.map(metricAccessors[metric]);
        trendChart.update();
    }

    document.querySelectorAll('[data-ls-product-metric]').forEach((btn) => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('[data-ls-product-metric]').forEach((b) => b.classList.toggle('is-active', b === btn));
            switchTrendMetric(btn.dataset.lsProductMetric);
        });
    });

    document.querySelectorAll('[data-ls-kpi-metric]').forEach((card) => {
        card.addEventListener('click', () => {
            document.querySelector(`[data-ls-product-metric="${card.dataset.lsKpiMetric}"]`)?.click();
        });
    });

    const shareCanvas = document.getElementById('ls-product-share-chart');
    let shareChart = null;
    const shareColors = ['#2e4f8f', '#5a82c6', '#8fb2f0', '#c6511f', '#d98c3f', '#9ca3af'];

    function ensureShareChart() {
        if (shareChart || !shareCanvas) return;

        const sorted = [...data.topProducts].sort((a, b) => b.revenue - a.revenue);
        const top = sorted.slice(0, 6);
        const rest = sorted.slice(6);
        const otherRevenue = rest.reduce((sum, p) => sum + p.revenue, 0);
        const otherUnits = rest.reduce((sum, p) => sum + p.units, 0);
        const otherOrders = rest.reduce((sum, p) => sum + p.orders, 0);

        const slices = otherRevenue > 0
            ? [...top, { name: metricLabels.other || 'Other', revenue: otherRevenue, units: otherUnits, orders: otherOrders }]
            : top;
        const total = slices.reduce((sum, p) => sum + p.revenue, 0) || 1;

        shareChart = new Chart(shareCanvas, {
            type: 'doughnut',
            data: {
                labels: slices.map((p) => p.name),
                datasets: [{ data: slices.map((p) => p.revenue), backgroundColor: shareColors }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 250 },
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
                    tooltip: Object.assign({
                        callbacks: {
                            label: (ctx) => {
                                const p = slices[ctx.dataIndex];
                                const money = window.LS ? window.LS.money(p.revenue) : p.revenue;
                                const pct = ((p.revenue / total) * 100).toFixed(1);
                                return `${p.name} · ${pct}% · ${money} · ${p.units} ${metricLabels.units}`;
                            },
                        },
                    }, tooltipMode()),
                },
            },
        });
    }

    const rankGroup = document.querySelector('[data-ls-rank-group]');
    document.querySelectorAll('[data-ls-product-view]').forEach((btn) => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('[data-ls-product-view]').forEach((b) => b.classList.toggle('is-active', b === btn));
            const view = btn.dataset.lsProductView;
            document.querySelectorAll('[data-ls-product-view-panel]').forEach((panel) => {
                panel.style.display = panel.dataset.lsProductViewPanel === view ? 'block' : 'none';
            });
            if (rankGroup) rankGroup.style.display = view === 'ranking' ? 'inline-flex' : 'none';
            if (view === 'share') {
                ensureShareChart();
                shareChart?.resize();
            }
        });
    });

    const topCanvas = document.getElementById('ls-top-products-chart');
    let topChart = null;
    function renderTopProducts(rankBy) {
        const sorted = [...data.topProducts].sort((a, b) => b[rankBy] - a[rankBy]).slice(0, 10);
        if (!topCanvas) return;
        if (!topChart) {
            topChart = new Chart(topCanvas, {
                type: 'bar',
                data: { labels: sorted.map((p) => p.name), datasets: [{ data: sorted.map((p) => p[rankBy]), backgroundColor: '#2e4f8f' }] },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 250 },
                    plugins: {
                        legend: { display: false },
                        tooltip: Object.assign({
                            callbacks: {
                                label: (ctx) => {
                                    const p = sorted[ctx.dataIndex];
                                    const money = window.LS ? window.LS.money(p.revenue) : p.revenue;
                                    return `${p.name} · ${p.units} ${metricLabels.units} · ${money} · ${p.orders} ${metricLabels.orders}`;
                                },
                            },
                        }, tooltipMode()),
                    },
                },
            });
        } else {
            topChart.data.labels = sorted.map((p) => p.name);
            topChart.data.datasets[0].data = sorted.map((p) => p[rankBy]);
            topChart.update();
        }
    }
    renderTopProducts('revenue');
    document.querySelectorAll('[data-ls-rank-by]').forEach((btn) => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('[data-ls-rank-by]').forEach((b) => b.classList.toggle('is-active', b === btn));
            renderTopProducts(btn.dataset.lsRankBy);
        });
    });
})();
</script>
<style>
    [data-ls-product-metric], [data-ls-rank-by], [data-ls-product-view] { color: var(--color-text-secondary, #6b7280); }
    [data-ls-product-metric].is-active, [data-ls-rank-by].is-active, [data-ls-product-view].is-active { background: #2e4f8f; color: #fff; }
</style>
