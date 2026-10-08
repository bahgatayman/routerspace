@extends('layouts.admin')

@section('page-title', $owner->business_name ?: $owner->name)
@section('crumb-parent', __('app.admin_biz.tabs.financials'))

@php
    use App\Support\Money;
    $t = fn (string $key, array $r = []) => __('app.admin_platform.'.$key, $r);
    $c = $cards;
@endphp

@section('content')
<div class="ls-biz-page">
    @include('admin.business._header', ['active' => 'financials'])

    <form method="GET" class="ls-adm-filters">@include('admin.partials.period', $range)<div class="ls-filter-actions"><button class="ls-btn ls-btn--secondary">{{ $t('apply') }}</button></div></form>

    <div class="ls-akpis">
        @include('admin.partials.stat', ['label' => $t('kpi.earnings'), 'value' => Money::format($c['earnings']['value']), 'change' => $c['earnings']['change'], 'tone' => 'revenue', 'help' => $t('help.earnings')])
        @include('admin.partials.stat', ['label' => $t('kpi.booking_earnings'), 'value' => Money::format($c['booking_earnings']['value'])])
        @include('admin.partials.stat', ['label' => $t('kpi.sales'), 'value' => Money::format($c['sales']['value'])])
        @include('admin.partials.stat', ['label' => $t('expenses'), 'value' => Money::format($c['expenses']['value']), 'change' => $c['expenses']['change'], 'invert' => true])
        @include('admin.partials.stat', ['label' => $t('net_after_expenses'), 'value' => Money::format($c['net']['value']), 'tone' => $c['net']['value'] < 0 ? 'warn' : null, 'help' => $t('help.net')])
        @include('admin.partials.stat', ['label' => $t('kpi.gbv'), 'value' => Money::format($c['gbv']['value']), 'help' => $t('help.gbv')])
        @include('admin.partials.stat', ['label' => $t('kpi.outstanding'), 'value' => Money::format($c['outstanding']['value']), 'sub' => $t('all_time'), 'tone' => $c['outstanding']['value'] > 0 ? 'warn' : null, 'href' => '/admin/bookings?payment=due&owner='.$owner->id])
        @include('admin.partials.stat', ['label' => $t('kpi.discounts'), 'value' => Money::format($c['discounts']['value'])])
        @include('admin.partials.stat', ['label' => $t('kpi.package_value'), 'value' => Money::format($c['package_value']['value']), 'help' => $t('help.package_value')])
        @include('admin.partials.stat', ['label' => $t('paid_to_platform'), 'value' => Money::format($c['platform_revenue']['value']), 'tone' => 'brand', 'href' => '/admin/owners/'.$owner->id.'/subscription'])
    </div>

    @include('admin.partials.chart', ['id' => 'b-earn', 'title' => $t('chart.earnings_title'), 'note' => $t('chart.click_bucket'), 'spec' => $chart])

    <div class="ls-adm-grid ls-adm-grid--3">
        @foreach ([['by_room', $byRoom, 'room_name', 'revenue', 'bookings'], ['by_product', $byProduct, 'name', 'revenue', 'quantity'], ['by_expense', $byCategory, 'name', 'amount', null]] as [$key, $rows, $nameCol, $valCol, $countCol])
            <section class="ls-card">
                <div class="ls-card-head"><h2 class="ls-card-title">{{ $t('breakdown.'.$key) }}</h2></div>
                <div class="ls-card-body">
                    @if (empty($rows))
                        <p class="ls-faint" style="margin:0">{{ $t('nothing_in_period') }}</p>
                    @else
                        <ul class="ls-adm-list">
                            @foreach ($rows as $r)<li><span class="ls-trunc">{{ $r[$nameCol] }}@if ($countCol)<small class="ls-faint"> · {{ number_format($r[$countCol]) }}</small>@endif</span><span class="ls-num">{{ Money::format($r[$valCol]) }}</span></li>@endforeach
                        </ul>
                    @endif
                </div>
            </section>
        @endforeach
    </div>
</div>
@endsection
