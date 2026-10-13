@extends('layouts.app')

@section('page-title', __('app.coupons.title'))

@section('content')
    <x-ui.page-header :title="__('app.coupons.title')" :subtitle="__('app.coupons.subtitle')" :count="$coupons->total()">
        @if ($canCreate)
            <x-slot:actions>
                <x-ui.button variant="primary" icon="plus" data-ls-open="create-coupon">{{ __('app.coupons.add') }}</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    <x-ui.flash />

    {{-- Filters: code search + status (server-side, same query params as before). --}}
    <form method="GET" action="{{ route('coupons.index') }}" class="ls-coupon-filters" role="search">
        <div class="ls-search">
            <x-ui.icon name="search" />
            <input type="search" name="search" value="{{ $search }}" class="ls-input" dir="ltr" autocomplete="off"
                   placeholder="{{ __('app.coupons.search_placeholder') }}" aria-label="{{ __('app.coupons.search_placeholder') }}">
        </div>
        <select name="status" class="ls-select" aria-label="{{ __('app.common.status') }}" onchange="this.form.submit()">
            <option value="">{{ __('app.coupons.filter_all') }}</option>
            @foreach (['active', 'inactive', 'scheduled', 'expired', 'limit_reached'] as $key)
                <option value="{{ $key }}" @selected($status === $key)>{{ __('app.coupons.status_'.$key) }}</option>
            @endforeach
        </select>
        <x-ui.button type="submit">{{ __('app.common.search') }}</x-ui.button>
    </form>

    @if ($coupons->isEmpty())
        <div class="ls-card">
            <x-ui.empty-state illustration="box" :title="$search || $status ? __('app.coupons.no_match') : __('app.coupons.empty')">
                @if (! $search && ! $status && $canCreate)
                    <x-ui.button variant="primary" icon="plus" data-ls-open="create-coupon">{{ __('app.coupons.add') }}</x-ui.button>
                @endif
            </x-ui.empty-state>
        </div>
    @else
        <div class="ls-coupon-grid">
            @foreach ($coupons as $coupon)
                <x-ui.coupon-card :coupon="$coupon">
                    @if ($canEdit)
                        <x-ui.button size="sm" icon="edit" :href="route('coupons.edit', $coupon->id)">{{ __('app.common.edit') }}</x-ui.button>
                        <form method="POST" action="{{ route('coupons.toggle', $coupon->id) }}">
                            @csrf
                            <x-ui.button type="submit" size="sm" variant="ghost" icon="power">{{ $coupon->is_active ? __('app.coupons.deactivate') : __('app.coupons.activate') }}</x-ui.button>
                        </form>
                    @endif
                    @if ($canDelete)
                        <form method="POST" action="{{ route('coupons.destroy', $coupon->id) }}" class="ls-push" onsubmit="return confirm(@js(__('app.coupons.delete_confirm')))">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="submit" size="sm" variant="danger-quiet" icon="trash" :icon-only="true"
                                :aria-label="__('app.common.delete').': '.$coupon->code" :title="__('app.common.delete')" />
                        </form>
                    @endif
                </x-ui.coupon-card>
            @endforeach
        </div>

        <div class="mt-6">{{ $coupons->links() }}</div>
    @endif

    @if ($canCreate)
        @include('coupons._quick-create-modal')
    @endif
@endsection
