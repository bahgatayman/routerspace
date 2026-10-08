@extends('layouts.admin')

@section('page-title', __('app.btn.add_plan'))
@section('crumb-parent', __('app.nav.plans'))
@section('handles-errors', '1')

@section('content')
<div class="ls-adm ls-adm-narrow">
    <a href="/admin/plans" class="ls-biz-back">&larr; {{ __('app.nav.plans') }}</a>
    <h1 class="ls-title">{{ __('app.btn.add_plan') }}</h1>
    @include('admin.plans._form')
</div>
@endsection
