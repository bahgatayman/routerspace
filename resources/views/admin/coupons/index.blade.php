@extends('layouts.admin')

@section('page-title', __('app.coupons.admin.title'))

@php
    use App\Support\Money;
    $c = fn (string $key, array $r = []) => __('app.coupons.'.$key, $r);
    $a = fn (string $key, array $r = []) => __('app.coupons.admin.'.$key, $r);
@endphp

@section('content')
<div class="ls-adm">
    <header class="ls-page-head ls-adm-head">
        <div>
            <h1 class="ls-title">{{ $a('title') }} <span class="ls-count">{{ number_format($total) }}</span></h1>
            <p class="ls-subtitle">{{ $a('subtitle') }}</p>
        </div>
        <div class="ls-actions"><x-ui.button variant="primary" icon="plus" :href="route('admin.coupons.create', $ownerId ? ['owner' => $ownerId] : [])">{{ $c('add') }}</x-ui.button></div>
    </header>

    <form method="GET" class="ls-adm-filters" role="search">
        <div class="ls-field ls-filter-field ls-filter-field--grow">
            <label class="ls-label" for="f-q">{{ __('app.common.search') }}</label>
            <div class="ls-search"><x-ui.icon name="search" /><input id="f-q" type="search" name="q" value="{{ $search }}" class="ls-input" dir="ltr" placeholder="{{ $c('search_placeholder') }}" autocomplete="off"></div>
        </div>
        <div class="ls-field ls-filter-field">
            <label class="ls-label" for="f-owner">{{ $a('business') }}</label>
            <select id="f-owner" name="owner" class="ls-select" onchange="this.form.submit()">
                <option value="">{{ $a('all_businesses') }}</option>
                @foreach ($businesses as $o)<option value="{{ $o->id }}" @selected($ownerId === $o->id)>{{ $o->business_name ?: $o->name }}</option>@endforeach
            </select>
        </div>
        <div class="ls-field ls-filter-field">
            <label class="ls-label" for="f-status">{{ __('app.common.status') }}</label>
            <select id="f-status" name="status" class="ls-select" onchange="this.form.submit()">
                <option value="">{{ $c('filter_all') }}</option>
                @foreach (['active', 'inactive', 'scheduled', 'expired', 'limit_reached'] as $key)
                    <option value="{{ $key }}" @selected($status === $key)>{{ $c('status_'.$key) }}</option>
                @endforeach
            </select>
        </div>
        <div class="ls-filter-actions"><x-ui.button type="submit">{{ __('app.common.search') }}</x-ui.button></div>
    </form>

    <section class="ls-card">
        @if ($coupons->isEmpty())
            <x-ui.empty-state illustration="box" :title="$search || $status || $ownerId ? $c('no_match') : $c('empty')" />
        @else
            <div class="ls-table-wrap">
                <table class="ls-table ls-adm-table">
                    <thead><tr>
                        <th scope="col">{{ $c('col_code') }}</th>
                        <th scope="col">{{ $a('business') }}</th>
                        <th scope="col">{{ $c('col_discount') }}</th>
                        <th scope="col">{{ $a('col_scope') }}</th>
                        <th scope="col">{{ $a('col_validity') }}</th>
                        <th scope="col">{{ $c('col_usage') }}</th>
                        <th scope="col">{{ $c('col_status') }}</th>
                        <th scope="col"><span class="ls-sr">{{ __('app.common.actions') }}</span></th>
                    </tr></thead>
                    <tbody>
                        @foreach ($coupons as $coupon)
                            @php
                                $scope = collect([
                                    $coupon->appliesTo('rooms') ? ($coupon->rooms_count ? trans_choice('app.coupons.card.n_rooms', $coupon->rooms_count, ['count' => $coupon->rooms_count]) : $c('all_rooms')) : null,
                                    $coupon->appliesTo('products') ? ($coupon->products_count ? trans_choice('app.coupons.card.n_products', $coupon->products_count, ['count' => $coupon->products_count]) : $c('all_products')) : null,
                                ])->filter()->implode(' · ');
                            @endphp
                            <tr>
                                <td><a href="{{ route('admin.coupons.show', $coupon->id) }}" class="ls-adm-coupon-code" dir="ltr">{{ $coupon->code }}</a></td>
                                <td>
                                    @if ($coupon->owner)
                                        <a href="{{ route('admin.business.show', $coupon->owner_id) }}" class="ls-link">{{ $coupon->owner->business_name ?: $coupon->owner->name }}</a>
                                    @else — @endif
                                </td>
                                <td class="ls-adm-coupon-value ls-num">
                                    {{ $coupon->discount_type === 'percentage' ? rtrim(rtrim(number_format((float) $coupon->discount_value, 1), '0'), '.').'%' : Money::format((float) $coupon->discount_value) }}
                                    @if ($coupon->minimum_spend && (float) $coupon->minimum_spend > 0)<small class="ls-adm-sub">{{ __('app.coupons.card.min_spend') }} {{ Money::format((float) $coupon->minimum_spend) }}</small>@endif
                                </td>
                                <td class="ls-adm-coupon-scope">{{ $scope }}</td>
                                <td class="ls-nowrap">
                                    @if ($coupon->starts_at || $coupon->expires_at)
                                        @if ($coupon->starts_at)<span class="ls-num">{{ $a('from', ['date' => $coupon->starts_at->translatedFormat('j M Y')]) }}</span>@endif
                                        @if ($coupon->expires_at)<small class="ls-adm-sub ls-num">{{ $a('until', ['date' => $coupon->expires_at->translatedFormat('j M Y')]) }}</small>@endif
                                    @else
                                        <span class="ls-faint">{{ $a('always') }}</span>
                                    @endif
                                </td>
                                <td class="ls-num ls-nowrap">{{ $coupon->usage_limit !== null ? $c('used_of', ['used' => $coupon->usages_count, 'limit' => $coupon->usage_limit]) : $c('used_count', ['count' => $coupon->usages_count]) }}</td>
                                <td><x-ui.badge :tone="$coupon->statusTone()">{{ $coupon->statusLabel() }}</x-ui.badge></td>
                                <td class="ls-nowrap">
                                    <div class="ls-adm-row-actions">
                                        @php $toggleLabel = $coupon->is_active ? $c('deactivate') : $c('activate'); @endphp
                                        <x-ui.button size="sm" variant="ghost" icon="edit" :icon-only="true" :href="route('admin.coupons.edit', $coupon->id)"
                                            :aria-label="__('app.common.edit').': '.$coupon->code" :title="__('app.common.edit')" />
                                        <form method="POST" action="{{ route('admin.coupons.toggle', $coupon->id) }}"
                                              @if ($coupon->is_active) data-confirm="{{ $a('confirm_deactivate', ['code' => $coupon->code]) }}" data-confirm-label="{{ $c('deactivate') }}" @endif>
                                            @csrf
                                            <x-ui.button type="submit" size="sm" variant="ghost" icon="power" :icon-only="true"
                                                :aria-label="$toggleLabel.': '.$coupon->code" :title="$toggleLabel" />
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($coupons->hasPages())<div>{{ $coupons->links() }}</div>@endif
</div>
@endsection
