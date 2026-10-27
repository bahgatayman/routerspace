@extends('layouts.app')

{{--
    Changing a series' price per date — step 2 of 2: what it will do before
    anything is saved (RecurringBookingService::pricePreview()). Only upcoming
    dates paid normally are repriced; past dates, dates changed on their own
    and every recorded payment stay exactly as they are.
    Expects: $series, $impact.
--}}
@php
    $before = $impact['before'];
    $after = $impact['after'];
    $money = fn ($n) => \App\Support\Money::format((float) $n);
@endphp

@section('page-title', __('app.series.price_title'))

@section('content')
    <div class="ls-series-page">
        <nav class="ls-wizard-crumbs" aria-label="{{ __('app.workspace.wizard.breadcrumb') }}">
            <a href="{{ route('bookings.series.index') }}" class="ls-link">{{ __('app.series.index_title') }}</a>
            <span aria-hidden="true">/</span>
            <a href="{{ route('bookings.series.show', $series) }}" class="ls-link">{{ $series->hotspotUser?->name }} · {{ $series->room?->name }}</a>
        </nav>

        <x-ui.page-header :title="__('app.series.price_title')" :subtitle="__('app.series.price_subtitle')" />

        <section class="ls-card ls-series-block">
            <div class="ls-card-body">
                <dl class="ls-series-money-grid">
                    <div><dt>{{ __('app.series.price_old') }}</dt><dd>{{ $impact['old'] === null ? '—' : $money($impact['old']) }}</dd></div>
                    <div><dt>{{ __('app.series.price_new') }}</dt><dd><b>{{ $money($impact['new']) }}</b></dd></div>
                    <div><dt>{{ __('app.series.price_affected') }}</dt><dd>{{ trans_choice('app.series.upcoming', $impact['affected'], ['count' => $impact['affected']]) }}</dd></div>
                    <div><dt>{{ __('app.series.price_diff') }}</dt><dd class="ls-num"><bdi dir="ltr">{{ $impact['diff'] > 0 ? '+' : ($impact['diff'] < 0 ? '−' : '') }}{{ $money(abs($impact['diff'])) }}</bdi></dd></div>
                </dl>

                <table class="ls-series-impact">
                    <thead>
                        <tr><th scope="col"></th><th scope="col">{{ __('app.series.now') }}</th><th scope="col">{{ __('app.series.after_change') }}</th></tr>
                    </thead>
                    <tbody>
                        <tr><th scope="row">{{ __('app.series.money_total') }}</th><td class="is-money">{{ $money($before['total']) }}</td><td class="is-money">{{ $money($after['total']) }}</td></tr>
                        <tr><th scope="row">{{ __('app.series.money_paid') }}</th><td class="is-money">{{ $money($before['paid']) }}</td><td class="is-money">{{ $money($after['paid']) }}</td></tr>
                        <tr><th scope="row">{{ __('app.series.money_remaining') }}</th><td class="is-money">{{ $money($before['remaining']) }}</td><td class="is-money">{{ $money($after['remaining']) }}</td></tr>
                        @if ($after['overpaid'] > 0)
                            <tr><th scope="row">{{ __('app.series.money_overpaid') }}</th><td class="is-money">{{ $money($before['overpaid']) }}</td><td class="is-money">{{ $money($after['overpaid']) }}</td></tr>
                        @endif
                    </tbody>
                </table>

                @if ($impact['skipped'])
                    <x-ui.banner tone="info">
                        {{ trans_choice('app.series.price_skipped', count($impact['skipped']), ['count' => count($impact['skipped'])]) }}
                        {{ collect($impact['skipped'])->map(fn ($s) => \Carbon\Carbon::parse($s['date'])->translatedFormat('j M').' ('.__('app.series.reasons.'.$s['reason']).')')->implode(', ') }}
                    </x-ui.banner>
                @endif
                @if ($impact['package_dates'] > 0)
                    <p class="ls-hint">{{ trans_choice('app.series.price_package_dates', $impact['package_dates'], ['count' => $impact['package_dates']]) }}</p>
                @endif
                <p class="ls-hint">{{ __('app.series.price_keeps') }}</p>

                <div class="ls-series-actions">
                    <a href="{{ route('bookings.series.show', $series) }}" class="ls-btn ls-btn--secondary">{{ __('app.series.price_back') }}</a>
                    <form method="POST" action="{{ route('bookings.series.price', $series) }}">
                        @csrf
                        <input type="hidden" name="price" value="{{ number_format($impact['new'], 2, '.', '') }}">
                        <x-ui.button type="submit" variant="primary" :disabled="$impact['affected'] === 0">{{ trans_choice('app.series.price_apply', $impact['affected'], ['count' => $impact['affected']]) }}</x-ui.button>
                    </form>
                </div>
            </div>
        </section>
    </div>
@endsection
