@extends('layouts.admin')

@section('page-title', __('app.section.plans'))

@section('content')

    <div class="flex justify-between items-center mb-6">
        <h2 class="text-xl font-bold text-gray-900">{{ __('app.section.plans') }}</h2>
        <a href="/admin/plans/create" class="bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700 transition text-sm font-medium">{{ __('app.btn.add_plan') }}</a>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach($plans->sortBy('sort_order') as $plan)
        <div class="bg-white rounded-xl border shadow-sm p-6 {{ !$plan->is_active ? 'opacity-60' : '' }}">
            <div class="flex justify-between items-start mb-4">
                <div>
                    <h3 class="text-xl font-bold text-gray-900">{{ $plan->name }}</h3>
                    <p class="text-2xl font-bold text-blue-600 mt-1">{{ $plan->formattedPrice() }}</p>
                </div>
                <span class="text-xs px-2 py-1 rounded-full
                    {{ $plan->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                    {{ $plan->is_active ? __('app.common.active') : __('app.common.inactive') }}
                </span>
            </div>
            <div class="text-sm text-gray-600 space-y-1 mb-4">
                <p>👥 {{ __('app.plan.up_to_members', ['count' => number_format($plan->max_members)]) }}</p>
                <p>🏢 {{ $plan->owners_count }} {{ __('app.plan.active_owners') }}</p>
            </div>
            <div class="flex gap-2 pt-4 border-t">
                <a href="/admin/plans/{{ $plan->id }}/edit"
                   class="text-sm text-blue-600 hover:underline">{{ __('app.common.edit') }}</a>
                <form method="POST" action="/admin/plans/{{ $plan->id }}/toggle">
                    @csrf
                    <button class="text-sm {{ $plan->is_active ? 'text-yellow-600' : 'text-green-600' }}">
                        {{ $plan->is_active ? __('app.btn.disable') : __('app.btn.enable') }}
                    </button>
                </form>
            </div>
        </div>
        @endforeach
    </div>
@endsection
