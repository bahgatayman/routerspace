@extends('layouts.admin')

@section('page-title', __('app.coupons.edit'))
@section('crumb-parent', __('app.coupons.admin.title'))
@section('handles-errors', '1')

@section('content')
<div class="ls-adm ls-adm-narrow">
    <a href="{{ route('admin.coupons.show', $coupon->id) }}" class="ls-biz-back">&larr; <span dir="ltr">{{ $coupon->code }}</span></a>
    <h1 class="ls-title">{{ __('app.coupons.edit') }}</h1>
    @include('admin.coupons._form', ['action' => route('admin.coupons.update', $coupon->id), 'method' => 'PUT'])
</div>
@endsection
