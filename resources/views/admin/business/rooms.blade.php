@extends('layouts.admin')

@section('page-title', $owner->business_name ?: $owner->name)
@section('crumb-parent', __('app.admin_biz.tabs.rooms'))

@php
    use App\Support\Money;
    $t = fn (string $key, array $r = []) => __('app.admin_platform.'.$key, $r);
@endphp

@section('content')
<div class="ls-biz-page">
    @include('admin.business._header', ['active' => 'rooms', 'locationAware' => true])

    <form method="GET" class="ls-adm-filters">
        @if ($workspace)<input type="hidden" name="workspace" value="{{ $workspace->id }}">@endif
        @include('admin.partials.period', $range)
        <div class="ls-filter-actions"><button class="ls-btn ls-btn--secondary">{{ $t('apply') }}</button></div>
    </form>

    <section class="ls-card">
        @if ($rooms->isEmpty())
            <x-ui.empty-state :title="$t('no_rooms')" />
        @else
            <div class="ls-table-wrap">
                <table class="ls-table ls-adm-table">
                    <thead><tr>
                        <th scope="col">{{ $t('col.room') }}</th>
                        <th scope="col">{{ $t('col.location') }}</th>
                        <th scope="col">{{ $t('col.type') }}</th>
                        <th scope="col" class="is-num">{{ $t('col.capacity') }}</th>
                        <th scope="col">{{ $t('col.pricing') }}</th>
                        <th scope="col" class="is-num">{{ $t('col.bookings_period') }}</th>
                        <th scope="col" class="is-num">{{ $t('hours_billed') }}</th>
                        <th scope="col" class="is-num">{{ $t('col.earnings_period') }}</th>
                        <th scope="col">{{ __('app.common.status') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($rooms as $room)
                            <tr class="row-link" data-href="/admin/rooms/{{ $room->id }}">
                                <td><a href="/admin/rooms/{{ $room->id }}" class="ls-adm-ident-name">{{ $room->name }}</a></td>
                                <td>{{ $room->workspace?->name ?? '—' }}</td>
                                <td>{{ $room->typeLabel() }}</td>
                                <td class="is-num">{{ $room->capacity }}</td>
                                <td class="ls-faint">{{ $room->pricingSummary() }}</td>
                                <td class="is-num">{{ number_format($room->period_bookings) }}</td>
                                <td class="is-num">{{ number_format((float) $room->period_hours, 1) }}</td>
                                <td class="is-money">{{ Money::format((float) $room->period_earnings) }}</td>
                                <td>@if ($room->is_available)<span class="ls-status"><span class="ls-dot"></span>{{ $t('available') }}</span>@else<x-ui.badge tone="neutral">{{ $t('unavailable') }}</x-ui.badge>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection
