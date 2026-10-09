@extends('layouts.admin')

@section('page-title', $owner->business_name ?: $owner->name)
@section('crumb-parent', __('app.admin_biz.tabs.products'))

@php
    use App\Support\Money;
    $t = fn (string $key, array $r = []) => __('app.admin_platform.'.$key, $r);
    $base = '/admin/owners/'.$owner->id.'/products';
    $stockTone = ['out' => 'danger', 'low' => 'warn', 'in_stock' => 'ok', 'untracked' => 'neutral'];
@endphp

@section('content')
<div class="ls-biz-page">
    @include('admin.business._header', ['active' => 'products'])

    <div class="ls-akpis">
        @include('admin.partials.stat', ['label' => $t('col.products'), 'value' => number_format($stats['total']), 'sub' => $t('active_n', ['n' => $stats['active']])])
        @include('admin.partials.stat', ['label' => $t('low_stock'), 'value' => number_format($stats['low']), 'tone' => $stats['low'] ? 'warn' : null, 'href' => $base.'?stock=low'])
        @include('admin.partials.stat', ['label' => $t('out_of_stock'), 'value' => number_format($stats['out']), 'tone' => $stats['out'] ? 'warn' : null, 'href' => $base.'?stock=out'])
        @include('admin.partials.stat', ['label' => $t('inventory_value'), 'value' => Money::format($stats['inventory_value']), 'help' => $t('help.inventory_value')])
        @include('admin.partials.stat', ['label' => $t('kpi.sales'), 'value' => Money::format($stats['sales']), 'tone' => 'revenue', 'help' => $t('help.sales')])
    </div>

    @php $hasProductSales = collect($productSeries)->sum('units') > 0; @endphp
    @if ($hasProductSales)
        <div class="ls-akpis">
            @include('admin.partials.stat', ['label' => __('app.dashboard.top_selling_product'), 'value' => $productSummary['topProduct']['name'] ?? '—'])
            @include('admin.partials.stat', ['label' => __('app.dashboard.total_units_sold'), 'value' => number_format($productSummary['totalUnits'])])
            @include('admin.partials.stat', ['label' => __('app.dashboard.avg_product_order_value'), 'value' => $productSummary['avgOrderValue'] !== null ? Money::format($productSummary['avgOrderValue']) : '—'])
            @include('admin.partials.stat', ['label' => __('app.dashboard.product_orders'), 'value' => number_format($productSummary['orderCount'])])
        </div>

        <div class="ls-adm-grid">
            @include('admin.partials.chart', ['id' => 'biz-product-trend', 'title' => __('app.dashboard.product_sales_trend'), 'spec' => $productTrendChart, 'height' => 240])
            @include('admin.partials.chart', ['id' => 'biz-top-products', 'title' => __('app.dashboard.top_selling_products'), 'spec' => $topProductsChart, 'height' => 240])
        </div>

        @if (! empty($productInsights))
            <section class="ls-card">
                <div class="ls-card-head"><h2 class="ls-card-title">{{ __('app.dashboard.product_insights') }}</h2></div>
                <div class="ls-card-body">
                    <ul style="margin: 0; padding-inline-start: 1.25rem;">
                        @foreach ($productInsights as $insight)
                            <li style="margin-bottom: var(--space-2, 8px);">{{ $insight['text'] }}</li>
                        @endforeach
                    </ul>
                </div>
            </section>
        @endif
    @endif

    <form method="GET" class="ls-adm-filters">
        <div class="ls-field ls-filter-field ls-filter-field--grow">
            <label class="ls-label" for="f-q">{{ __('app.common.search') }}</label>
            <div class="ls-search"><x-ui.icon name="search" /><input id="f-q" type="search" name="q" value="{{ $filters['search'] }}" class="ls-input" placeholder="{{ $t('search_products') }}"></div>
        </div>
        <div class="ls-field ls-filter-field">
            <label class="ls-label" for="f-type">{{ $t('col.type') }}</label>
            <select id="f-type" name="type" class="ls-select">
                <option value="">{{ __('app.common.all') }}</option>
                <option value="product" @selected($filters['type'] === 'product')>{{ $t('product_types.product') }}</option>
                <option value="service" @selected($filters['type'] === 'service')>{{ $t('product_types.service') }}</option>
            </select>
        </div>
        <div class="ls-field ls-filter-field">
            <label class="ls-label" for="f-stock">{{ $t('stock') }}</label>
            <select id="f-stock" name="stock" class="ls-select">
                <option value="">{{ __('app.common.all') }}</option>
                @foreach (['low', 'out', 'inactive'] as $s)<option value="{{ $s }}" @selected($filters['stock'] === $s)>{{ $t('stock_filter.'.$s) }}</option>@endforeach
            </select>
        </div>
        @include('admin.partials.period', $range)
        <div class="ls-filter-actions">
            <button type="submit" class="ls-btn ls-btn--primary">{{ $t('apply') }}</button>
            @if (request()->hasAny(['q', 'type', 'stock', 'preset']))<a href="{{ $base }}" class="ls-btn ls-btn--ghost">{{ $t('reset') }}</a>@endif
        </div>
    </form>

    <section class="ls-card">
        @if ($products->isEmpty())
            <x-ui.empty-state :title="$t('no_products')" />
        @else
            <div class="ls-table-wrap">
                <table class="ls-table ls-adm-table">
                    <thead><tr>
                        <th scope="col">{{ $t('col.product') }}</th>
                        <th scope="col">{{ $t('col.type') }}</th>
                        <th scope="col" class="is-num">{{ $t('price') }}</th>
                        <th scope="col" class="is-num">{{ $t('cost') }}</th>
                        <th scope="col" class="is-num">{{ $t('stock') }}</th>
                        <th scope="col" class="is-num">{{ $t('sold_period') }}</th>
                        <th scope="col" class="is-num">{{ $t('revenue_period') }}</th>
                        <th scope="col">{{ __('app.common.status') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($products as $p)
                            @php $ss = $p->stockStatus(); @endphp
                            <tr class="row-link" data-href="{{ $base }}/{{ $p->id }}">
                                <td><a href="{{ $base }}/{{ $p->id }}" class="ls-adm-ident-name">{{ $p->name }}</a>@if ($p->sku)<small class="ls-adm-sub" dir="ltr">{{ $p->sku }}</small>@endif</td>
                                <td>{{ $t('product_types.'.($p->isService() ? 'service' : 'product')) }}</td>
                                <td class="is-money">{{ Money::format((float) $p->price) }}</td>
                                <td class="is-money">{{ $p->purchase_price !== null ? Money::format((float) $p->purchase_price) : '—' }}</td>
                                <td class="is-num">@if ($p->tracksStock())<x-ui.badge :tone="$stockTone[$ss]" :dot="false">{{ number_format((int) $p->stock_quantity) }}</x-ui.badge>@else — @endif</td>
                                <td class="is-num">{{ number_format((int) $p->sold_qty) }}</td>
                                <td class="is-money">{{ Money::format((float) $p->sold_revenue) }}</td>
                                <td>@if ($p->is_active)<span class="ls-status"><span class="ls-dot"></span>{{ __('app.status.active') }}</span>@else<x-ui.badge tone="neutral">{{ __('app.status.inactive') }}</x-ui.badge>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="ls-adm-pager"><span class="ls-faint">{{ $t('showing', ['from' => $products->firstItem(), 'to' => $products->lastItem(), 'total' => $products->total()]) }}</span>{{ $products->links('admin.partials.pager') }}</div>
        @endif
    </section>
</div>
@endsection
