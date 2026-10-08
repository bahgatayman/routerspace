@extends('layouts.admin')

@section('page-title', __('app.nav.bookings'))

@php
    use App\Support\Money;
    use App\Http\Controllers\Admin\BookingController as BC;
    $t = fn (string $key, array $r = []) => __('app.admin_platform.'.$key, $r);
    $hasFilters = request()->hasAny(['q', 'owner', 'location', 'room', 'status', 'payment', 'type', 'preset']);
    $chip = fn (?string $s) => request()->fullUrlWithQuery(['status' => $s, 'page' => null]);
@endphp

@section('content')
<div class="ls-adm">
    <header class="ls-page-head ls-adm-head">
        <div>
            <h1 class="ls-title">{{ __('app.nav.bookings') }} <span class="ls-count">{{ number_format($bookings->total()) }}</span></h1>
            <p class="ls-subtitle">{{ $filters['has_range'] ? $t('bookings_sub_range', ['from' => \Carbon\Carbon::parse($range['from'])->translatedFormat('M j, Y'), 'to' => \Carbon\Carbon::parse($range['to'])->translatedFormat('M j, Y')]) : $t('bookings_sub_all') }}</p>
        </div>
    </header>

    <form method="GET" class="ls-adm-filters" aria-label="{{ $t('filters') }}">
        @if ($filters['status'])<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
        <div class="ls-field ls-filter-field ls-filter-field--grow">
            <label class="ls-label" for="f-q">{{ __('app.common.search') }}</label>
            <div class="ls-search"><x-ui.icon name="search" /><input id="f-q" type="search" name="q" value="{{ $filters['search'] }}" class="ls-input" placeholder="{{ $t('search_bookings') }}"></div>
        </div>
        <div class="ls-field ls-filter-field">
            <label class="ls-label" for="f-owner">{{ $t('col.workspace') }}</label>
            <select id="f-owner" name="owner" class="ls-select" onchange="['location','room'].forEach(n => this.form[n] && (this.form[n].value = '')); this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                <option value="">{{ $t('all_workspaces') }}</option>
                @foreach ($owners as $o)<option value="{{ $o->id }}" @selected($filters['owner'] === $o->id)>{{ $o->business_name ?: $o->name }}</option>@endforeach
            </select>
        </div>
        @if ($locations->count() > 1)
            <div class="ls-field ls-filter-field">
                <label class="ls-label" for="f-loc">{{ $t('col.location') }}</label>
                <select id="f-loc" name="location" class="ls-select">
                    <option value="">{{ $t('all_locations') }}</option>
                    @foreach ($locations as $l)<option value="{{ $l->id }}" @selected($filters['location'] === $l->id)>{{ $l->name }}</option>@endforeach
                </select>
            </div>
        @endif
        @if ($rooms->isNotEmpty())
            <div class="ls-field ls-filter-field">
                <label class="ls-label" for="f-room">{{ $t('col.room') }}</label>
                <select id="f-room" name="room" class="ls-select">
                    <option value="">{{ $t('all_rooms') }}</option>
                    @foreach ($rooms as $r)<option value="{{ $r->id }}" @selected($filters['room'] === $r->id)>{{ $r->name }}</option>@endforeach
                </select>
            </div>
        @endif
        <div class="ls-field ls-filter-field">
            <label class="ls-label" for="f-pay">{{ $t('col.payment') }}</label>
            <select id="f-pay" name="payment" class="ls-select">
                <option value="">{{ __('app.common.all') }}</option>
                @foreach (BC::PAYMENTS as $p)<option value="{{ $p }}" @selected($filters['payment'] === $p)>{{ $t('payment.'.$p) }}</option>@endforeach
            </select>
        </div>
        <div class="ls-field ls-filter-field">
            <label class="ls-label" for="f-type">{{ $t('col.type') }}</label>
            <select id="f-type" name="type" class="ls-select">
                <option value="">{{ __('app.common.all') }}</option>
                @foreach (BC::TYPES as $ty)<option value="{{ $ty }}" @selected($filters['type'] === $ty)>{{ $t('types.'.$ty) }}</option>@endforeach
            </select>
        </div>
        @if ($filters['has_range'])
            @include('admin.partials.period', $range)
        @else
            <div class="ls-field ls-filter-field">
                <label class="ls-label" for="f-preset">{{ $t('period') }}</label>
                <select id="f-preset" name="preset" class="ls-select" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                    <option value="">{{ $t('presets.all_time') }}</option>
                    @foreach (BC::PRESETS as $p)@if ($p !== 'custom')<option value="{{ $p }}">{{ $t('presets.'.$p) }}</option>@endif @endforeach
                </select>
            </div>
        @endif
        <div class="ls-filter-actions">
            <button type="submit" class="ls-btn ls-btn--primary">{{ $t('apply') }}</button>
            @if ($hasFilters)<a href="/admin/bookings" class="ls-btn ls-btn--ghost">{{ $t('reset') }}</a>@endif
        </div>
    </form>

    <div class="ls-akpis">
        @include('admin.partials.stat', ['label' => $t('kpi.bookings'), 'value' => number_format($stats['total'])])
        @include('admin.partials.stat', ['label' => $t('kpi.gbv'), 'value' => Money::format($stats['gbv']), 'help' => $t('help.gbv')])
        @include('admin.partials.stat', ['label' => $t('collected'), 'value' => Money::format($stats['collected']), 'tone' => 'revenue', 'help' => $t('help.collected')])
        @include('admin.partials.stat', ['label' => $t('kpi.outstanding'), 'value' => Money::format($stats['outstanding']), 'tone' => $stats['outstanding'] > 0 ? 'warn' : null, 'help' => $t('help.outstanding'), 'href' => request()->fullUrlWithQuery(['payment' => 'due', 'page' => null])])
    </div>

    <nav class="ls-chips" aria-label="{{ __('app.common.status') }}">
        <a href="{{ $chip(null) }}" class="ls-chip {{ $filters['status'] ? '' : 'is-active' }}">{{ __('app.common.all') }} <span class="ls-chip-count">{{ $stats['total'] }}</span></a>
        @foreach (BC::STATUSES as $s)
            @if (($stats['by_status'][$s] ?? 0) > 0 || $filters['status'] === $s)
                <a href="{{ $chip($s) }}" class="ls-chip {{ $filters['status'] === $s ? 'is-active' : '' }}">{{ $t('status.'.$s) }} <span class="ls-chip-count">{{ $stats['by_status'][$s] ?? 0 }}</span></a>
            @endif
        @endforeach
    </nav>

    <section class="ls-card">
        @include('admin.bookings._table', ['bookings' => $bookings, 'sort' => $sort, 'dir' => $dir])
        @if ($bookings->isNotEmpty())
            <div class="ls-adm-pager">
                <span class="ls-faint">{{ $t('showing', ['from' => $bookings->firstItem(), 'to' => $bookings->lastItem(), 'total' => $bookings->total()]) }}</span>
                {{ $bookings->links('admin.partials.pager') }}
            </div>
        @endif
    </section>
    <p class="ls-faint ls-adm-foot">{{ $t('bookings_live_note') }}</p>
</div>
@endsection
