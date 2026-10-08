@extends('layouts.admin')

@section('page-title', __('app.plan.edit_plan'))
@section('crumb-parent', __('app.nav.plans'))
@section('handles-errors', '1')

@section('content')
<div class="ls-adm ls-adm-narrow">
    <a href="/admin/plans/{{ $plan->id }}" class="ls-biz-back">&larr; {{ $plan->name }}</a>
    <h1 class="ls-title">{{ __('app.plan.edit_plan') }}: {{ $plan->name }}</h1>
    @include('admin.plans._form', ['plan' => $plan])
</div>
@endsection
