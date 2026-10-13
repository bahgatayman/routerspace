{{--
    Shared by create.blade.php / edit.blade.php.
    $coupon (Coupon, may be a fresh un-saved instance on create)
    $roomGroups / $productGroups (Collection<string label, Collection<Room|Product>>)
    $selectedRoomIds / $selectedProductIds (array<int>)
--}}
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-6">
    <h3 class="font-semibold text-gray-900">{{ __('app.coupons.section_basic') }}</h3>
    @include('coupons._fields-basic')
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-4 mt-6">
    <h3 class="font-semibold text-gray-900">{{ __('app.coupons.section_applies_to') }}</h3>
    @include('coupons._fields-applies-to')
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 space-y-4 mt-6">
    <h3 class="font-semibold text-gray-900">{{ __('app.coupons.section_restrictions') }}</h3>
    @include('coupons._fields-restrictions')
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mt-6">
    <h3 class="font-semibold text-gray-900 mb-3">{{ __('app.coupons.section_status') }}</h3>
    <label class="inline-flex items-center gap-2 text-sm">
        <input type="checkbox" name="is_active" value="1" {{ old('is_active', $coupon->exists ? $coupon->is_active : true) ? 'checked' : '' }}>
        {{ __('app.coupons.is_active') }}
    </label>
</div>

<div class="flex gap-3 mt-6">
    <button type="submit" class="bg-blue-600 text-white px-5 py-2.5 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
        {{ __('app.common.save') }}
    </button>
    <a href="{{ $cancelUrl ?? route('coupons.index') }}" class="px-5 py-2.5 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-100 transition">
        {{ __('app.common.cancel') }}
    </a>
</div>
