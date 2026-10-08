@extends('layouts.admin')

@section('page-title', __('app.admin_platform.nav.rooms'))

@php
    use App\Support\Money;
    $t = fn (string $key, array $r = []) => __('app.admin_platform.'.$key, $r);
    $hasFilters = request()->hasAny(['q', 'owner', 'location', 'type', 'available', 'idle', 'preset']);
@endphp

@section('content')
<div class="ls-adm">
    <header class="ls-page-head ls-adm-head">
        <div>
            <h1 class="ls-title">{{ $t('nav.rooms') }} <span class="ls-count">{{ number_format($rooms->total()) }}</span></h1>
            <p class="ls-subtitle">{{ $t('rooms_sub') }}</p>
        </div>
    </header>

    <form method="GET" class="ls-adm-filters" aria-label="{{ $t('filters') }}">
        <div class="ls-field ls-filter-field ls-filter-field--grow">
            <label class="ls-label" for="f-q">{{ __('app.common.search') }}</label>
            <div class="ls-search"><x-ui.icon name="search" /><input id="f-q" type="search" name="q" value="{{ $filters['q'] }}" class="ls-input" placeholder="{{ $t('search_rooms') }}"></div>
        </div>
        <div class="ls-field ls-filter-field">
            <label class="ls-label" for="f-owner">{{ $t('col.workspace') }}</label>
            <select id="f-owner" name="owner" class="ls-select" onchange="this.form.location && (this.form.location.value = ''); this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                <option value="">{{ $t('all_workspaces') }}</option>
                @foreach ($owners as $o)<option value="{{ $o->id }}" @selected($filters['owner'] === $o->id)>{{ $o->business_name ?: $o->name }}</option>@endforeach
            </select>
        </div>
        @if ($locations->isNotEmpty())
            <div class="ls-field ls-filter-field">
                <label class="ls-label" for="f-loc">{{ $t('col.location') }}</label>
                <select id="f-loc" name="location" class="ls-select">
                    <option value="">{{ $t('all_locations') }}</option>
                    @foreach ($locations as $l)<option value="{{ $l->id }}" @selected($filters['location'] === $l->id)>{{ $l->name }}</option>@endforeach
                </select>
            </div>
        @endif
        <div class="ls-field ls-filter-field">
            <label class="ls-label" for="f-type">{{ $t('col.type') }}</label>
            <select id="f-type" name="type" class="ls-select">
                <option value="">{{ __('app.common.all') }}</option>
                @foreach (\App\Http\Controllers\Admin\RoomController::TYPES as $ty)<option value="{{ $ty }}" @selected($filters['type'] === $ty)>{{ __('app.room_type.'.$ty) }}</option>@endforeach
            </select>
        </div>
        <div class="ls-field ls-filter-field">
            <label class="ls-label" for="f-av">{{ $t('availability') }}</label>
            <select id="f-av" name="available" class="ls-select">
                <option value="">{{ __('app.common.all') }}</option>
                <option value="1" @selected($filters['available'] === '1')>{{ $t('available') }}</option>
                <option value="0" @selected($filters['available'] === '0')>{{ $t('unavailable') }}</option>
            </select>
        </div>
        @include('admin.partials.period', $range)
        <label class="ls-adm-check"><input type="checkbox" name="idle" value="1" @checked($filters['idle'])> {{ $t('idle_only') }}</label>
        <div class="ls-filter-actions">
            <button type="submit" class="ls-btn ls-btn--primary">{{ $t('apply') }}</button>
            @if ($hasFilters)<a href="/admin/rooms" class="ls-btn ls-btn--ghost">{{ $t('reset') }}</a>@endif
        </div>
    </form>

    <section class="ls-card">
        @if ($rooms->isEmpty())
            <x-ui.empty-state :title="$hasFilters ? $t('no_match') : $t('no_rooms')" :text="$hasFilters ? $t('no_match_text') : null" />
        @else
            <div class="ls-table-wrap">
                <table class="ls-table ls-adm-table">
                    <thead><tr>
                        @include('admin.partials.sort', ['key' => 'name', 'label' => $t('col.room'), 'default' => 'asc'])
                        <th scope="col">{{ $t('col.workspace') }}</th>
                        <th scope="col">{{ $t('col.location') }}</th>
                        <th scope="col">{{ $t('col.type') }}</th>
                        @include('admin.partials.sort', ['key' => 'capacity', 'label' => $t('col.capacity'), 'class' => 'is-num'])
                        @include('admin.partials.sort', ['key' => 'price', 'label' => $t('col.pricing')])
                        @include('admin.partials.sort', ['key' => 'bookings', 'label' => $t('col.bookings_period'), 'class' => 'is-num'])
                        @include('admin.partials.sort', ['key' => 'earnings', 'label' => $t('col.earnings_period'), 'class' => 'is-num'])
                        <th scope="col">{{ __('app.common.status') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($rooms as $room)
                            <tr class="row-link" data-href="/admin/rooms/{{ $room->id }}">
                                <td><a href="/admin/rooms/{{ $room->id }}" class="ls-adm-ident-name">{{ $room->name }}</a></td>
                                <td><a href="/admin/owners/{{ $room->owner_id }}" class="ls-link">{{ $room->owner?->business_name ?? '—' }}</a></td>
                                <td>@if ($room->workspace)<a href="/admin/locations/{{ $room->workspace_id }}" class="ls-link">{{ $room->workspace->name }}</a>@else — @endif</td>
                                <td>{{ $room->typeLabel() }}</td>
                                <td class="is-num">{{ $room->capacity }}</td>
                                <td class="ls-faint">{{ $room->pricingSummary() }}</td>
                                <td class="is-num">{{ number_format($room->period_bookings) }}</td>
                                <td class="is-money">{{ Money::format((float) $room->period_earnings) }}</td>
                                <td>@if ($room->is_available)<span class="ls-status"><span class="ls-dot"></span>{{ $t('available') }}</span>@else<x-ui.badge tone="neutral">{{ $t('unavailable') }}</x-ui.badge>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="ls-adm-pager">
                <span class="ls-faint">{{ $t('showing', ['from' => $rooms->firstItem(), 'to' => $rooms->lastItem(), 'total' => $rooms->total()]) }} · {{ $t('money_note_period') }}</span>
                {{ $rooms->links('admin.partials.pager') }}
            </div>
        @endif
    </section>
</div>
@endsection
