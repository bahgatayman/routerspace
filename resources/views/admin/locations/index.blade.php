@extends('layouts.admin')

@section('page-title', __('app.admin_platform.nav.locations'))

@php $t = fn (string $key, array $r = []) => __('app.admin_platform.'.$key, $r); @endphp

@section('content')
<div class="ls-adm">
    <header class="ls-page-head ls-adm-head">
        <div>
            <h1 class="ls-title">{{ $t('nav.locations') }} <span class="ls-count">{{ number_format($locations->total()) }}</span></h1>
            <p class="ls-subtitle">{{ $t('locations_sub') }}</p>
        </div>
    </header>

    <form method="GET" class="ls-adm-filters" aria-label="{{ $t('filters') }}">
        <div class="ls-field ls-filter-field ls-filter-field--grow">
            <label class="ls-label" for="f-q">{{ __('app.common.search') }}</label>
            <div class="ls-search"><x-ui.icon name="search" /><input id="f-q" type="search" name="q" value="{{ $filters['q'] }}" class="ls-input" placeholder="{{ $t('search_locations') }}"></div>
        </div>
        <div class="ls-field ls-filter-field">
            <label class="ls-label" for="f-owner">{{ $t('col.workspace') }}</label>
            <select id="f-owner" name="owner" class="ls-select">
                <option value="">{{ $t('all_workspaces') }}</option>
                @foreach ($owners as $o)<option value="{{ $o->id }}" @selected($filters['owner'] === $o->id)>{{ $o->business_name ?: $o->name }}</option>@endforeach
            </select>
        </div>
        <div class="ls-filter-actions">
            <button type="submit" class="ls-btn ls-btn--primary">{{ $t('apply') }}</button>
            @if (request()->hasAny(['q', 'owner']))<a href="/admin/locations" class="ls-btn ls-btn--ghost">{{ $t('reset') }}</a>@endif
        </div>
    </form>

    <section class="ls-card">
        @if ($locations->isEmpty())
            <x-ui.empty-state :title="$t('no_locations')" />
        @else
            <div class="ls-table-wrap">
                <table class="ls-table ls-adm-table">
                    <thead><tr>
                        <th scope="col">{{ $t('col.location') }}</th>
                        <th scope="col">{{ $t('col.workspace') }}</th>
                        <th scope="col">{{ $t('col.city') }}</th>
                        <th scope="col" class="is-num">{{ $t('nav.rooms') }}</th>
                        <th scope="col">{{ __('app.common.status') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($locations as $l)
                            <tr class="row-link" data-href="/admin/locations/{{ $l->id }}">
                                <td><a href="/admin/locations/{{ $l->id }}" class="ls-adm-ident-name">{{ $l->name }}</a>@if ($l->address)<small class="ls-adm-sub">{{ $l->address }}</small>@endif</td>
                                <td><a href="/admin/owners/{{ $l->owner_id }}?workspace={{ $l->id }}" class="ls-link">{{ $l->owner?->business_name ?? '—' }}</a></td>
                                <td>{{ $l->city ?: '—' }}</td>
                                <td class="is-num">{{ $l->available_rooms_count }} / {{ $l->rooms_count }}</td>
                                <td>@if ($l->is_active)<span class="ls-status"><span class="ls-dot"></span>{{ __('app.status.active') }}</span>@else<x-ui.badge tone="neutral">{{ __('app.status.inactive') }}</x-ui.badge>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="ls-adm-pager">
                <span class="ls-faint">{{ $t('showing', ['from' => $locations->firstItem(), 'to' => $locations->lastItem(), 'total' => $locations->total()]) }}</span>
                {{ $locations->links('admin.partials.pager') }}
            </div>
        @endif
    </section>
</div>
@endsection
