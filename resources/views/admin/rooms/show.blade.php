@extends('layouts.admin')

@section('page-title', $room->name)
@section('crumb-parent', __('app.admin_platform.nav.rooms'))

@php
    use App\Support\Money;
    $t = fn (string $key, array $r = []) => __('app.admin_platform.'.$key, $r);
    $rules = $room->pricingRules();
@endphp

@section('content')
<div class="ls-adm">
    <a href="/admin/rooms" class="ls-biz-back">&larr; {{ $t('nav.rooms') }}</a>
    <header class="ls-biz-head">
        <div class="ls-biz-id">
            <h1 class="ls-biz-name">{{ $room->name }}
                @if ($room->is_available)<x-ui.badge tone="ok">{{ $t('available') }}</x-ui.badge>@else<x-ui.badge tone="neutral">{{ $t('unavailable') }}</x-ui.badge>@endif
            </h1>
            <p class="ls-biz-meta">
                <a href="/admin/owners/{{ $room->owner_id }}" class="ls-link">{{ $room->owner?->business_name }}</a>
                @if ($room->workspace) · <a href="/admin/locations/{{ $room->workspace_id }}" class="ls-link">{{ $room->workspace->name }}</a>@endif
                · {{ $room->typeLabel() }} · {{ $t('capacity_n', ['n' => $room->capacity]) }}
            </p>
        </div>
        <div class="ls-biz-actions">
            <x-ui.button size="sm" :href="'/admin/bookings?owner='.$room->owner_id.'&room='.$room->id">{{ $t('all_bookings') }}</x-ui.button>
        </div>
    </header>

    <form method="GET" class="ls-adm-filters">@include('admin.partials.period', $range)<div class="ls-filter-actions"><button class="ls-btn ls-btn--secondary">{{ $t('apply') }}</button></div></form>

    <div class="ls-akpis">
        @include('admin.partials.stat', ['label' => $t('kpi.bookings'), 'value' => number_format($stats['bookings']), 'href' => '/admin/bookings?owner='.$room->owner_id.'&room='.$room->id.'&preset='.$range['preset'].'&from='.$range['from'].'&to='.$range['to']])
        @include('admin.partials.stat', ['label' => $t('kpi.completed'), 'value' => number_format($stats['completed'])])
        @include('admin.partials.stat', ['label' => $t('kpi.cancellation_rate'), 'value' => number_format($stats['cancel_rate'], 1).'%'])
        @include('admin.partials.stat', ['label' => $t('kpi.earnings'), 'value' => Money::format($stats['earnings']), 'tone' => 'revenue', 'help' => $t('help.room_earnings')])
        @include('admin.partials.stat', ['label' => $t('kpi.gbv'), 'value' => Money::format($stats['gbv']), 'help' => $t('help.gbv')])
        @include('admin.partials.stat', ['label' => $t('hours_billed'), 'value' => number_format($stats['hours'], 1)])
        @include('admin.partials.stat', ['label' => $t('kpi.outstanding'), 'value' => Money::format($stats['outstanding']), 'sub' => $t('all_time'), 'tone' => $stats['outstanding'] > 0 ? 'warn' : null])
    </div>

    <div class="ls-adm-grid">
        <section class="ls-card">
            <div class="ls-card-head"><h2 class="ls-card-title">{{ $t('pricing_title') }}</h2></div>
            <div class="ls-card-body ls-stack">
                <dl class="ls-kv">
                    <dt>{{ $t('pricing_model') }}</dt><dd>{{ __('app.pricing.model.'.($room->pricing_model ?: 'hourly')) }}</dd>
                    <dt>{{ $t('col.pricing') }}</dt><dd>{{ $room->pricingSummary() }}</dd>
                    @if ($room->isShared())
                        <dt>{{ $t('billing_unit') }}</dt><dd>{{ $room->billingUnitLabel() }}</dd>
                    @endif
                    <dt>{{ $t('buffer') }}</dt><dd>{{ $room->billing_buffer_minutes ? $t('minutes_n', ['n' => $room->billing_buffer_minutes]) : '—' }}</dd>
                </dl>
                @if ($room->pricingProfiles->isNotEmpty())
                    <div>
                        <h3 class="ls-section-title">{{ $t('pricing_profiles') }}</h3>
                        <ul class="ls-adm-list">
                            @foreach ($room->pricingProfiles as $p)
                                <li><span>{{ $p->name }} @unless ($p->is_active)<x-ui.badge tone="neutral" :dot="false">{{ __('app.status.inactive') }}</x-ui.badge>@endunless</span><span class="ls-num">{{ Money::format((float) $p->price_per_hour) }}{{ __('app.common.slash_hr') }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if ($room->plans->isNotEmpty())
                    <div>
                        <h3 class="ls-section-title">{{ $t('custom_plans') }}</h3>
                        <ul class="ls-adm-list">
                            @foreach ($room->plans as $p)
                                <li><span>{{ $p->name }}</span><span class="ls-num">{{ Money::format((float) $p->price) }}</span></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if ($room->description)<p class="ls-faint" style="margin:0">{{ $room->description }}</p>@endif
            </div>
        </section>

        <section class="ls-card">
            <div class="ls-card-head"><h2 class="ls-card-title">{{ $t('today_availability') }}</h2></div>
            <div class="ls-card-body">
                @if (empty($today))
                    <p class="ls-faint">{{ $t('closed_today') }}</p>
                @else
                    <ul class="ls-adm-slots">
                        @foreach ($today as $seg)
                            @php $state = $seg['closed'] ? 'closed' : ($seg['available'] <= 0 ? 'full' : ($seg['used'] > 0 ? 'partial' : 'free')); @endphp
                            <li class="is-{{ $state }}">
                                <span class="ls-num" dir="ltr">{{ \Carbon\Carbon::parse($seg['start'])->format('g:i A') }} – {{ \Carbon\Carbon::parse($seg['end'])->format('g:i A') }}</span>
                                <span>{{ $t('slot.'.$state) }}@if (! $seg['closed'] && $room->isShared()) · {{ $seg['available'] }}/{{ $seg['capacity'] }}@endif</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>
    </div>

    <section class="ls-card">
        <div class="ls-card-head"><h2 class="ls-card-title">{{ $t('recent_bookings') }} <span class="ls-count">{{ number_format($stats['all_time']) }}</span></h2></div>
        <div class="ls-card-body ls-card-body--flush">
            @include('admin.bookings._table', ['bookings' => $recent, 'hide' => ['workspace', 'room']])
        </div>
    </section>
</div>
@endsection
