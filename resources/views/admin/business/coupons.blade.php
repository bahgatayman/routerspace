@extends('layouts.admin')

@section('page-title', $owner->business_name ?: $owner->name)
@section('crumb-parent', __('app.admin_biz.tabs.coupons'))

@php
    $c = fn (string $key, array $r = []) => __('app.coupons.'.$key, $r);
    $chip = fn (?string $s) => request()->fullUrlWithQuery(['status' => $s, 'page' => null]);
@endphp

@section('content')
<div class="ls-biz-page">
    @include('admin.business._header', ['active' => 'coupons'])

    <div class="ls-biz-toolbar">
        <nav class="ls-chips" aria-label="{{ __('app.common.status') }}">
            <a href="{{ $chip(null) }}" class="ls-chip {{ $status ? '' : 'is-active' }}">{{ $c('filter_all') }} <span class="ls-chip-count">{{ $total }}</span></a>
            @foreach ($counts as $key => $n)
                <a href="{{ $chip($key) }}" class="ls-chip {{ $status === $key ? 'is-active' : '' }}">{{ $c('status_'.$key) }} <span class="ls-chip-count">{{ $n }}</span></a>
            @endforeach
        </nav>
        <x-ui.button variant="primary" icon="plus" :href="route('admin.coupons.create', ['owner' => $owner->id])">{{ $c('add') }}</x-ui.button>
    </div>

    @if ($coupons->isEmpty())
        <section class="ls-card">
            <x-ui.empty-state illustration="box" :title="$status ? $c('no_match') : $c('empty')" />
        </section>
    @else
        <div class="ls-coupon-grid">
            @foreach ($coupons as $coupon)
                <x-ui.coupon-card :coupon="$coupon" :href="route('admin.coupons.show', $coupon->id)">
                    <x-ui.button size="sm" icon="edit" :href="route('admin.coupons.edit', $coupon->id)">{{ __('app.common.edit') }}</x-ui.button>
                    <form method="POST" action="{{ route('admin.coupons.toggle', $coupon->id) }}">
                        @csrf
                        <x-ui.button type="submit" size="sm" variant="ghost" icon="power">{{ $coupon->is_active ? $c('deactivate') : $c('activate') }}</x-ui.button>
                    </form>
                </x-ui.coupon-card>
            @endforeach
        </div>
        <div class="ls-adm-pager"><span></span>{{ $coupons->links('admin.partials.pager') }}</div>
    @endif
</div>
@endsection
