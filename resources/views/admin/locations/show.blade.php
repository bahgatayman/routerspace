@extends('layouts.admin')

@section('page-title', $location->name)
@section('crumb-parent', __('app.admin_platform.nav.locations'))

@php
    use App\Support\Money;
    $t = fn (string $key, array $r = []) => __('app.admin_platform.'.$key, $r);
@endphp

@section('content')
<div class="ls-adm">
    <a href="/admin/locations" class="ls-biz-back">&larr; {{ $t('nav.locations') }}</a>
    <header class="ls-biz-head">
        <div class="ls-biz-id">
            <h1 class="ls-biz-name">{{ $location->name }} @unless ($location->is_active)<x-ui.badge tone="neutral">{{ __('app.status.inactive') }}</x-ui.badge>@endunless</h1>
            <p class="ls-biz-meta">
                <a href="/admin/owners/{{ $location->owner_id }}?workspace={{ $location->id }}" class="ls-link">{{ $location->owner?->business_name }}</a>
                @foreach (array_filter([$location->city, $location->address, $location->phone]) as $bit) · <span>{{ $bit }}</span>@endforeach
            </p>
            @if ($location->description)<p class="ls-faint" style="margin:0">{{ $location->description }}</p>@endif
        </div>
        <div class="ls-biz-actions">
            <x-ui.button size="sm" :href="'/admin/owners/'.$location->owner_id.'?workspace='.$location->id">{{ $t('open_workspace') }}</x-ui.button>
            <x-ui.button size="sm" :href="'/admin/bookings?owner='.$location->owner_id.'&location='.$location->id">{{ __('app.nav.bookings') }}</x-ui.button>
        </div>
    </header>

    <section class="ls-card">
        <div class="ls-card-head"><h2 class="ls-card-title">{{ $t('nav.rooms') }} <span class="ls-count">{{ $location->rooms->count() }}</span></h2></div>
        <div class="ls-card-body ls-card-body--flush">
            @if ($location->rooms->isEmpty())
                <x-ui.empty-state :title="$t('no_rooms')" />
            @else
                <div class="ls-table-wrap">
                    <table class="ls-table ls-adm-table">
                        <thead><tr>
                            <th scope="col">{{ $t('col.room') }}</th>
                            <th scope="col">{{ $t('col.type') }}</th>
                            <th scope="col" class="is-num">{{ $t('col.capacity') }}</th>
                            <th scope="col">{{ $t('col.pricing') }}</th>
                            <th scope="col" class="is-num">{{ $t('col.bookings_all') }}</th>
                            <th scope="col" class="is-num">{{ $t('col.earned_all') }}</th>
                            <th scope="col">{{ __('app.common.status') }}</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($location->rooms as $room)
                                <tr class="row-link" data-href="/admin/rooms/{{ $room->id }}">
                                    <td><a href="/admin/rooms/{{ $room->id }}" class="ls-adm-ident-name">{{ $room->name }}</a></td>
                                    <td>{{ $room->typeLabel() }}</td>
                                    <td class="is-num">{{ $room->capacity }}</td>
                                    <td class="ls-faint">{{ $room->pricingSummary() }}</td>
                                    <td class="is-num">{{ number_format($bookings[$room->id]->n ?? 0) }}</td>
                                    <td class="is-money">{{ Money::format((float) ($bookings[$room->id]->earned ?? 0)) }}</td>
                                    <td>@if ($room->is_available)<span class="ls-status"><span class="ls-dot"></span>{{ $t('available') }}</span>@else<x-ui.badge tone="neutral">{{ $t('unavailable') }}</x-ui.badge>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>
</div>
@endsection
