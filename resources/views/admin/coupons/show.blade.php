@extends('layouts.admin')

@section('page-title', $coupon->code)
@section('crumb-parent', __('app.coupons.admin.title'))

@php
    use App\Support\Money;
    $c = fn (string $key, array $r = []) => __('app.coupons.'.$key, $r);
    $a = fn (string $key, array $r = []) => __('app.coupons.admin.'.$key, $r);
    $business = $coupon->owner ? ($coupon->owner->business_name ?: $coupon->owner->name) : null;
@endphp

@section('content')
<div class="ls-adm">
    <a href="{{ $coupon->owner_id ? route('admin.business.coupons', $coupon->owner_id) : route('admin.coupons.index') }}" class="ls-biz-back">&larr; {{ $a('back') }}</a>

    <header class="ls-page-head ls-adm-head">
        <div>
            <h1 class="ls-title" dir="ltr">{{ $coupon->code }}</h1>
            @if ($business)<p class="ls-subtitle"><a href="{{ route('admin.business.show', $coupon->owner_id) }}" class="ls-link">{{ $business }}</a></p>@endif
        </div>
        <div class="ls-actions">
            <x-ui.button icon="edit" :href="route('admin.coupons.edit', $coupon->id)">{{ __('app.common.edit') }}</x-ui.button>
            <form method="POST" action="{{ route('admin.coupons.toggle', $coupon->id) }}"
                  @if ($coupon->is_active) data-confirm="{{ $a('confirm_deactivate', ['code' => $coupon->code]) }}" data-confirm-label="{{ $c('deactivate') }}" @endif>
                @csrf
                <x-ui.button type="submit" variant="ghost" icon="power">{{ $coupon->is_active ? $c('deactivate') : $c('activate') }}</x-ui.button>
            </form>
            @if ($coupon->usages_count === 0)
                <form method="POST" action="{{ route('admin.coupons.destroy', $coupon->id) }}" data-confirm="{{ $a('confirm_delete', ['code' => $coupon->code]) }}" data-confirm-label="{{ __('app.common.delete') }}">
                    @csrf @method('DELETE')
                    <x-ui.button type="submit" variant="danger-quiet" icon="trash">{{ __('app.common.delete') }}</x-ui.button>
                </form>
            @endif
        </div>
    </header>

    <div class="ls-adm-coupon-layout">
        <x-ui.coupon-card :coupon="$coupon" :business="$business" />

        <div class="ls-adm-coupon-side">
            <section class="ls-card">
                <div class="ls-card-head"><h2 class="ls-card-title">{{ $a('targets') }}</h2></div>
                <div class="ls-card-body">
                <dl class="ls-kv">
                    @if ($coupon->appliesTo('rooms'))
                        <dt>{{ $c('rooms') }}</dt>
                        <dd>{{ $coupon->rooms->isEmpty() ? $c('all_rooms') : $coupon->rooms->map(fn ($r) => $r->name.($r->workspace ? ' ('.$r->workspace->name.')' : ''))->implode('، ') }}</dd>
                    @endif
                    @if ($coupon->appliesTo('products'))
                        <dt>{{ $c('products') }}</dt>
                        <dd>{{ $coupon->products->isEmpty() ? $c('all_products') : $coupon->products->pluck('name')->implode('، ') }}</dd>
                    @endif
                    <dt>{{ $c('usage_limit') }}</dt><dd>{{ $coupon->usage_limit ?? $c('no_limit') }}</dd>
                    <dt>{{ $c('per_customer_limit') }}</dt><dd>{{ $coupon->per_customer_limit ?? $c('no_limit') }}</dd>
                    <dt>{{ $c('minimum_spend') }}</dt><dd>{{ $coupon->minimum_spend !== null && (float) $coupon->minimum_spend > 0 ? Money::format((float) $coupon->minimum_spend) : '—' }}</dd>
                    <dt>{{ $c('starts_at') }}</dt><dd class="ls-num">{{ $coupon->starts_at?->translatedFormat('j M Y, g:i A') ?? '—' }}</dd>
                    <dt>{{ $c('expires_at') }}</dt><dd class="ls-num">{{ $coupon->expires_at?->translatedFormat('j M Y, g:i A') ?? $c('no_expiry') }}</dd>
                </dl>
                </div>
            </section>

            <section class="ls-card">
                <div class="ls-card-head"><h2 class="ls-card-title">{{ $a('recent_uses') }} <span class="ls-count">{{ $coupon->usages_count }}</span></h2></div>
                <div class="ls-card-body">
                @if ($usages->isEmpty())
                    <p class="ls-hint" style="margin: 0">{{ $a('no_uses') }}</p>
                @else
                    <div class="ls-table-wrap">
                        <table class="ls-table ls-adm-table">
                            <thead><tr>
                                <th scope="col">{{ $a('col_date') }}</th>
                                <th scope="col">{{ $a('col_customer') }}</th>
                                <th scope="col">{{ $a('col_booking') }}</th>
                                <th scope="col" class="is-num">{{ $a('col_discount_given') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($usages as $u)
                                    <tr>
                                        <td class="ls-num ls-nowrap">{{ $u->used_at?->translatedFormat('j M Y') }}</td>
                                        <td>{{ $u->hotspotUser?->name ?? '—' }}</td>
                                        <td>@if ($u->booking_id)<a href="{{ route('admin.bookings.show', $u->booking_id) }}" class="ls-link ls-num">#{{ $u->booking_id }}</a>@else — @endif</td>
                                        <td class="is-money">{{ Money::format((float) $u->discount_amount) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                </div>
            </section>
        </div>
    </div>
</div>
@endsection
