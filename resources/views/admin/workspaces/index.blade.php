@extends('layouts.admin')

@section('page-title', __('app.admin_platform.nav.workspaces'))

@php
    use App\Support\Money;
    $t = fn (string $key, array $r = []) => __('app.admin_platform.'.$key, $r);
    $statusTone = ['active' => 'ok', 'expiring_soon' => 'warn', 'expired' => 'danger', 'disabled' => 'danger', 'never' => 'neutral'];
    $chip = fn (?string $s) => request()->fullUrlWithQuery(['status' => $s, 'page' => null]);
    $hasFilters = request()->hasAny(['q', 'status', 'plan', 'joined_from', 'joined_to', 'preset']);
@endphp

@section('content')
<div class="ls-adm">
    <header class="ls-page-head ls-adm-head">
        <div>
            <h1 class="ls-title">{{ $t('nav.workspaces') }} <span class="ls-count">{{ number_format($total) }}</span></h1>
            <p class="ls-subtitle">{{ $t('directory_sub') }}</p>
        </div>
        <div class="ls-actions">
            <x-ui.button variant="primary" icon="plus" href="/admin/owners/create">{{ __('app.admin.add_owner') }}</x-ui.button>
        </div>
    </header>

    <nav class="ls-chips" aria-label="{{ __('app.common.status') }}">
        <a href="{{ $chip(null) }}" class="ls-chip {{ $filters['status'] ? '' : 'is-active' }}">{{ __('app.common.all') }} <span class="ls-chip-count">{{ $total }}</span></a>
        @foreach ($counts as $s => $n)
            <a href="{{ $chip($s) }}" class="ls-chip {{ $filters['status'] === $s ? 'is-active' : '' }}" data-status-chip="{{ $s }}">{{ $t('ws_status.'.$s) }} <span class="ls-chip-count">{{ $n }}</span></a>
        @endforeach
    </nav>

    <form method="GET" class="ls-adm-filters" aria-label="{{ $t('filters') }}">
        @if ($filters['status'])<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
        <div class="ls-field ls-filter-field ls-filter-field--grow">
            <label class="ls-label" for="f-q">{{ __('app.common.search') }}</label>
            <div class="ls-search">
                <x-ui.icon name="search" />
                <input id="f-q" type="search" name="q" value="{{ $filters['q'] }}" class="ls-input" placeholder="{{ $t('search_ws') }}" autocomplete="off">
            </div>
        </div>
        <div class="ls-field ls-filter-field">
            <label class="ls-label" for="f-plan">{{ __('app.nav.plans') }}</label>
            <select id="f-plan" name="plan" class="ls-select">
                <option value="">{{ $t('all_plans') }}</option>
                @foreach ($plans as $p)<option value="{{ $p->id }}" @selected($filters['plan'] === $p->id)>{{ $p->name }}</option>@endforeach
            </select>
        </div>
        <div class="ls-field ls-filter-field">
            <label class="ls-label" for="f-jf">{{ $t('joined_from') }}</label>
            <input id="f-jf" type="date" name="joined_from" value="{{ $filters['joined_from'] }}" class="ls-input" dir="ltr">
        </div>
        <div class="ls-field ls-filter-field">
            <label class="ls-label" for="f-jt">{{ $t('joined_to') }}</label>
            <input id="f-jt" type="date" name="joined_to" value="{{ $filters['joined_to'] }}" class="ls-input" dir="ltr">
        </div>
        @include('admin.partials.period', $range)
        <div class="ls-filter-actions">
            <button type="submit" class="ls-btn ls-btn--primary">{{ $t('apply') }}</button>
            @if ($hasFilters)<a href="/admin/workspaces" class="ls-btn ls-btn--ghost">{{ $t('reset') }}</a>@endif
        </div>
    </form>

    <section class="ls-card" aria-label="{{ $t('nav.workspaces') }}">
        @if ($owners->isEmpty())
            <x-ui.empty-state :title="$hasFilters ? $t('no_match') : $t('no_workspaces')" :text="$hasFilters ? $t('no_match_text') : null">
                @if ($hasFilters)<a href="/admin/workspaces" class="ls-btn ls-btn--secondary">{{ $t('reset') }}</a>@endif
            </x-ui.empty-state>
        @else
            <div class="ls-table-wrap">
                <table class="ls-table ls-adm-table">
                    <thead><tr>
                        @include('admin.partials.sort', ['key' => 'name', 'label' => $t('col.workspace'), 'default' => 'asc'])
                        <th scope="col">{{ __('app.common.status') }}</th>
                        <th scope="col">{{ __('app.nav.plans') }}</th>
                        @include('admin.partials.sort', ['key' => 'locations', 'label' => $t('nav.locations'), 'class' => 'is-num'])
                        @include('admin.partials.sort', ['key' => 'rooms', 'label' => $t('nav.rooms'), 'class' => 'is-num'])
                        @include('admin.partials.sort', ['key' => 'products', 'label' => $t('col.products'), 'class' => 'is-num'])
                        @include('admin.partials.sort', ['key' => 'bookings', 'label' => $t('col.bookings_period'), 'class' => 'is-num'])
                        @include('admin.partials.sort', ['key' => 'earnings', 'label' => $t('col.earnings_period'), 'class' => 'is-num'])
                        @include('admin.partials.sort', ['key' => 'expires', 'label' => $t('col.expires'), 'default' => 'asc'])
                        @include('admin.partials.sort', ['key' => 'activity', 'label' => $t('col.last_activity')])
                        @include('admin.partials.sort', ['key' => 'created', 'label' => $t('col.joined')])
                    </tr></thead>
                    <tbody>
                        @foreach ($owners as $o)
                            @php $st = $o->subscriptionStatus(); @endphp
                            <tr class="row-link" data-href="/admin/owners/{{ $o->id }}">
                                <td>
                                    <div class="ls-adm-ident">
                                        <x-ui.avatar :name="$o->business_name ?: $o->name" size="sm" />
                                        <span>
                                            <a href="/admin/owners/{{ $o->id }}" class="ls-adm-ident-name">{{ $o->business_name ?: $o->name }}</a>
                                            <small>#{{ $o->id }} · {{ $o->name }} · <bdi dir="ltr">{{ $o->email }}</bdi></small>
                                        </span>
                                    </div>
                                </td>
                                <td><x-ui.badge :tone="$statusTone[$st] ?? 'neutral'">{{ __('app.admin_biz.status.'.$st) }}</x-ui.badge></td>
                                <td>{{ $o->plan?->name ?? '—' }}</td>
                                <td class="is-num">{{ $o->locations_count }}</td>
                                <td class="is-num">{{ $o->rooms_count }}</td>
                                <td class="is-num">{{ $o->products_count }}</td>
                                <td class="is-num">{{ number_format($o->period_bookings) }}</td>
                                <td class="is-money">{{ Money::format((float) $o->booking_earnings + (float) $o->sales_earnings) }}</td>
                                <td class="ls-nowrap">{{ $o->subscription_expires_at?->translatedFormat('M j, Y') ?? '—' }}</td>
                                <td class="ls-nowrap ls-faint">{{ $o->last_booking_at ? \Carbon\Carbon::parse($o->last_booking_at)->diffForHumans() : '—' }}</td>
                                <td class="ls-nowrap ls-faint">{{ $o->created_at?->translatedFormat('M j, Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="ls-adm-pager">
                <span class="ls-faint">{{ $t('showing', ['from' => $owners->firstItem(), 'to' => $owners->lastItem(), 'total' => $owners->total()]) }} · {{ $t('money_note_period') }}</span>
                {{ $owners->onEachSide(1)->links('admin.partials.pager') }}
            </div>
        @endif
    </section>
</div>
@endsection
