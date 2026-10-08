@extends('layouts.admin')

@section('page-title', $product->name)
@section('crumb-parent', $owner->business_name ?: $owner->name)

@php
    use App\Support\Money;
    $t = fn (string $key, array $r = []) => __('app.admin_platform.'.$key, $r);
    $stockTone = ['out' => 'danger', 'low' => 'warn', 'in_stock' => 'ok', 'untracked' => 'neutral'];
    $ss = $product->stockStatus();
@endphp

@section('content')
<div class="ls-biz-page">
    @include('admin.business._header', ['active' => 'products'])

    <a href="/admin/owners/{{ $owner->id }}/products" class="ls-biz-back">&larr; {{ __('app.admin_biz.tabs.products') }}</a>
    <header class="ls-biz-head">
        <div class="ls-biz-id">
            <h1 class="ls-biz-name">{{ $product->name }}
                @if ($product->tracksStock())<x-ui.badge :tone="$stockTone[$ss]">{{ $t('stock_status.'.$ss) }}</x-ui.badge>@endif
                @unless ($product->is_active)<x-ui.badge tone="neutral">{{ __('app.status.inactive') }}</x-ui.badge>@endunless
            </h1>
            <p class="ls-biz-meta">{{ $t('product_types.'.($product->isService() ? 'service' : 'product')) }}@if ($product->sku) · <span dir="ltr">{{ $product->sku }}</span>@endif</p>
            @if ($product->description)<p class="ls-faint" style="margin:0">{{ $product->description }}</p>@endif
        </div>
    </header>

    <form method="GET" class="ls-adm-filters">@include('admin.partials.period', $range)<div class="ls-filter-actions"><button class="ls-btn ls-btn--secondary">{{ $t('apply') }}</button></div></form>

    <div class="ls-akpis">
        @include('admin.partials.stat', ['label' => $t('price'), 'value' => Money::format((float) $product->price), 'sub' => $product->marginPercent() !== null && $product->purchase_price !== null ? $t('margin', ['pct' => $product->marginPercent()]) : null])
        @include('admin.partials.stat', ['label' => $t('stock'), 'value' => $product->tracksStock() ? number_format((int) $product->stock_quantity) : '—', 'sub' => $product->tracksStock() && $product->low_stock_threshold !== null ? $t('threshold', ['n' => $product->low_stock_threshold]) : $t('not_tracked_stock')])
        @include('admin.partials.stat', ['label' => $t('sold_period'), 'value' => number_format($stats['qty'])])
        @include('admin.partials.stat', ['label' => $t('revenue_period'), 'value' => Money::format($stats['revenue']), 'tone' => 'revenue'])
        @include('admin.partials.stat', ['label' => $t('gross_profit'), 'value' => Money::format($stats['revenue'] - $stats['cost']), 'help' => $t('help.gross_profit')])
        @include('admin.partials.stat', ['label' => $t('lifetime_revenue'), 'value' => Money::format($stats['revenue_all']), 'sub' => $t('units_n', ['n' => number_format($stats['qty_all'])])])
    </div>

    <div class="ls-adm-grid">
        <section class="ls-card">
            <div class="ls-card-head"><h2 class="ls-card-title">{{ $t('recent_sales') }}</h2></div>
            <div class="ls-card-body ls-card-body--flush">
                @if ($recent->isEmpty())
                    <x-ui.empty-state :title="$t('no_sales')" />
                @else
                    <div class="ls-table-wrap"><table class="ls-table">
                        <thead><tr><th scope="col">{{ $t('col.date') }}</th><th scope="col" class="is-num">{{ $t('qty') }}</th><th scope="col" class="is-num">{{ $t('line_total') }}</th><th scope="col">{{ $t('related') }}</th></tr></thead>
                        <tbody>
                            @foreach ($recent as $i)
                                <tr>
                                    <td class="ls-nowrap">{{ \Carbon\Carbon::parse($i->sold_at)->translatedFormat('M j, Y g:i A') }}</td>
                                    <td class="is-num">{{ $i->quantity }}</td>
                                    <td class="is-money">{{ Money::format((float) $i->line_total) }}</td>
                                    <td>@if ($i->booking_id)<a href="/admin/bookings/{{ $i->booking_id }}" class="ls-link">#{{ $i->booking_id }}</a>@else {{ $t('counter_sale') }} @endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                @endif
            </div>
        </section>

        <section class="ls-card">
            <div class="ls-card-head"><h2 class="ls-card-title">{{ $t('stock_movements') }}</h2></div>
            <div class="ls-card-body ls-card-body--flush">
                @if ($movements->isEmpty())
                    <x-ui.empty-state :title="$t('no_movements')" />
                @else
                    <div class="ls-table-wrap"><table class="ls-table">
                        <thead><tr><th scope="col">{{ $t('col.date') }}</th><th scope="col">{{ $t('col.type') }}</th><th scope="col" class="is-num">{{ $t('change') }}</th><th scope="col" class="is-num">{{ $t('stock') }}</th></tr></thead>
                        <tbody>
                            @foreach ($movements as $m)
                                <tr>
                                    <td class="ls-nowrap">{{ $m->created_at?->translatedFormat('M j, Y g:i A') }}</td>
                                    <td>{{ Lang::has('app.inventory.movement.'.$m->type) ? __('app.inventory.movement.'.$m->type) : ucfirst(str_replace('_', ' ', $m->type)) }}@if ($m->note)<small class="ls-adm-sub">{{ $m->note }}</small>@endif</td>
                                    <td class="is-num">{{ $m->quantity_change > 0 ? '+' : '' }}{{ $m->quantity_change }}</td>
                                    <td class="is-num">{{ $m->new_quantity }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                @endif
            </div>
        </section>
    </div>
</div>
@endsection
