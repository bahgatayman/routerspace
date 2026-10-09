@extends('layouts.admin')

@section('page-title', $owner->business_name ?: $owner->name)
@section('crumb-parent', __('app.admin_biz.tabs.bookings'))

@php
    use App\Support\Money;
    $t = fn (string $key, array $r = []) => __('app.admin_platform.'.$key, $r);
    $chip = fn (?string $s) => request()->fullUrlWithQuery(['status' => $s, 'page' => null]);
@endphp

@section('content')
<div class="ls-biz-page">
    @include('admin.business._header', ['active' => 'bookings', 'locationAware' => true])

    <form method="GET" class="ls-adm-filters">
        @if ($workspace)<input type="hidden" name="workspace" value="{{ $workspace->id }}">@endif
        @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
        @include('admin.partials.period', $range)
        <div class="ls-filter-actions">
            <button class="ls-btn ls-btn--secondary">{{ $t('apply') }}</button>
            <a href="/admin/bookings?owner={{ $owner->id }}" class="ls-btn ls-btn--ghost">{{ $t('advanced_filters') }}</a>
        </div>
    </form>

    <div class="ls-akpis">
        @include('admin.partials.stat', ['label' => $t('kpi.bookings'), 'value' => number_format($stats['total'])])
        @include('admin.partials.stat', ['label' => $t('kpi.gbv'), 'value' => Money::format($stats['gbv']), 'help' => $t('help.gbv')])
        @include('admin.partials.stat', ['label' => $t('collected'), 'value' => Money::format($stats['collected']), 'tone' => 'revenue', 'help' => $t('help.collected')])
        @include('admin.partials.stat', ['label' => $t('kpi.outstanding'), 'value' => Money::format($stats['outstanding']), 'tone' => $stats['outstanding'] > 0 ? 'warn' : null])
    </div>

    @include('admin.partials.chart', ['id' => 'biz-status', 'title' => $t('chart.status_title'), 'note' => $t('chart.click_slice'), 'spec' => $statusChart, 'height' => 220])

    <nav class="ls-chips" aria-label="{{ __('app.common.status') }}">
        <a href="{{ $chip(null) }}" class="ls-chip {{ $status ? '' : 'is-active' }}">{{ __('app.common.all') }} <span class="ls-chip-count">{{ $stats['total'] }}</span></a>
        @foreach (\App\Http\Controllers\Admin\BookingController::STATUSES as $s)
            @if (($stats['by_status'][$s] ?? 0) > 0)
                <a href="{{ $chip($s) }}" class="ls-chip {{ $status === $s ? 'is-active' : '' }}">{{ $t('status.'.$s) }} <span class="ls-chip-count">{{ $stats['by_status'][$s] }}</span></a>
            @endif
        @endforeach
    </nav>

    <section class="ls-card">
        @include('admin.bookings._table', ['bookings' => $bookings, 'hide' => ['workspace']])
        @if ($bookings->isNotEmpty())
            <div class="ls-adm-pager"><span class="ls-faint">{{ $t('showing', ['from' => $bookings->firstItem(), 'to' => $bookings->lastItem(), 'total' => $bookings->total()]) }}</span>{{ $bookings->links('admin.partials.pager') }}</div>
        @endif
    </section>
</div>
@endsection
