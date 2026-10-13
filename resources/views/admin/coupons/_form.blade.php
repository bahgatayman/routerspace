{{--
    Super Admin coupon form: the business is shown (fixed), then the SAME fields the
    owner screen uses (coupons._form) so both validate and look alike.
    Expects $owner, $coupon, $roomGroups, $productGroups, $selectedRoomIds, $selectedProductIds, $action, $method.
--}}
<div class="ls-paper ls-adm-coupon-owner">
    <x-ui.icon name="building" />
    <span>{{ __('app.coupons.admin.business') }}: <b>{{ $owner->business_name ?: $owner->name }}</b></span>
    @if ($method === 'POST')
        <a href="{{ route('admin.coupons.create') }}" class="ls-link">{{ __('app.common.edit') }}</a>
    @endif
</div>

@if ($errors->any())
    <x-ui.banner tone="danger">
        <ul class="ls-error-list">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </x-ui.banner>
@endif

<form method="POST" action="{{ $action }}">
    @csrf
    @if ($method !== 'POST') @method($method) @endif
    @if ($method === 'POST')<input type="hidden" name="owner_id" value="{{ $owner->id }}">@endif
    @include('coupons._form', ['cancelUrl' => $coupon->exists ? route('admin.coupons.show', $coupon->id) : route('admin.coupons.index')])
</form>
