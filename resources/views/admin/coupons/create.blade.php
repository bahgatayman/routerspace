@extends('layouts.admin')

@section('page-title', __('app.coupons.add'))
@section('crumb-parent', __('app.coupons.admin.title'))
@section('handles-errors', '1')

@section('content')
<div class="ls-adm ls-adm-narrow">
    <a href="{{ route('admin.coupons.index') }}" class="ls-biz-back">&larr; {{ __('app.coupons.admin.back') }}</a>
    <h1 class="ls-title">{{ __('app.coupons.add') }}</h1>

    @if (! $owner)
        {{-- Step 1: a coupon always belongs to one business; its rooms/products come from it. --}}
        <form method="GET" action="{{ route('admin.coupons.create') }}" class="ls-card ls-card-body ls-stack">
            <div class="ls-field">
                <label class="ls-label" for="f-owner">{{ __('app.coupons.admin.choose_business') }}</label>
                <select id="f-owner" name="owner" class="ls-select" required>
                    <option value="">—</option>
                    @foreach ($businesses as $o)<option value="{{ $o->id }}">{{ $o->business_name ?: $o->name }}</option>@endforeach
                </select>
                <p class="ls-hint">{{ __('app.coupons.admin.choose_business_hint') }}</p>
            </div>
            <div><x-ui.button type="submit" variant="primary">{{ __('app.coupons.admin.continue') }}</x-ui.button></div>
        </form>
    @else
        @include('admin.coupons._form', ['action' => route('admin.coupons.store'), 'method' => 'POST'])
    @endif
</div>
@endsection
