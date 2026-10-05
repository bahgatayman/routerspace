@extends('layouts.admin')

@section('page-title', __('app.subscription.admin_requests'))

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">{{ __('app.subscription.admin_requests') }}</h1>
        @if ($pending->isNotEmpty())
            <span class="bg-yellow-100 text-yellow-800 px-3 py-1 rounded-full text-sm font-medium">
                {{ $pending->count() }} {{ __('app.subscription.status_pending') }}
            </span>
        @endif
    </div>


    <!-- Pending -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100">
            <h2 class="text-sm font-semibold text-gray-900">{{ __('app.subscription.awaiting_approval') }}</h2>
            <p class="text-xs text-gray-500 mt-0.5">{{ __('app.subscription.approve_hint') }}</p>
        </div>

        @if ($pending->isEmpty())
            <p class="px-5 py-10 text-center text-sm text-gray-400">{{ __('app.subscription.no_pending_requests') }}</p>
        @else
            <ul class="divide-y divide-gray-100">
                @foreach ($pending as $req)
                    <li class="px-5 py-4">
                        <div class="flex flex-col lg:flex-row lg:items-center gap-4">
                            <div class="min-w-0 flex-1">
                                <a href="/admin/owners/{{ $req->owner_id }}" class="font-medium text-gray-900 hover:text-blue-600">
                                    {{ $req->owner?->business_name }}
                                </a>
                                <p class="text-sm text-gray-500 mt-0.5">
                                    {{ $req->plan?->name }} ·
                                    {{ $req->months }} {{ __('app.subscription.months') }} ·
                                    <span class="font-semibold text-gray-900">ج.م {{ number_format($req->amount, 2) }}</span>
                                </p>
                                <p class="text-xs text-gray-400 mt-0.5">
                                    {{ __('app.subscription.requested_on') }} {{ $req->created_at->format('d M Y, H:i') }}
                                    @if ($req->owner?->subscription_expires_at)
                                        · {{ __('app.label.expires') }} {{ $req->owner->subscription_expires_at->format('d M Y') }}
                                    @endif
                                </p>
                                @if ($req->note)
                                    <p class="text-sm text-gray-600 mt-2 bg-gray-50 rounded-lg px-3 py-2">“{{ $req->note }}”</p>
                                @endif
                            </div>

                            <div class="flex items-center gap-2 shrink-0">
                                <form method="POST" action="{{ route('admin.subscription-requests.approve', $req->id) }}"
                                      onsubmit="return confirm('{{ __('app.subscription.approve_confirm') }}')">
                                    @csrf
                                    <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition text-sm font-medium">
                                        {{ __('app.subscription.approve') }}
                                    </button>
                                </form>

                                <details class="relative">
                                    <summary class="list-none cursor-pointer border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium">
                                        {{ __('app.subscription.reject') }}
                                    </summary>
                                    <form method="POST" action="{{ route('admin.subscription-requests.reject', $req->id) }}"
                                          class="absolute end-0 mt-2 w-72 bg-white rounded-lg shadow-lg border border-gray-100 p-3 z-20">
                                        @csrf
                                        <label class="block text-xs font-medium text-gray-600 mb-1">{{ __('app.subscription.reject_reason') }}</label>
                                        <input type="text" name="admin_note" maxlength="500"
                                               placeholder="{{ __('app.subscription.reject_reason_placeholder') }}"
                                               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-500">
                                        <button type="submit" class="mt-2 w-full bg-red-600 text-white px-3 py-2 rounded-lg hover:bg-red-700 transition text-sm font-medium">
                                            {{ __('app.subscription.confirm_reject') }}
                                        </button>
                                    </form>
                                </details>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <!-- History -->
    @if ($handled->isNotEmpty())
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mt-6">
            <div class="px-5 py-4 border-b border-gray-100">
                <h2 class="text-sm font-semibold text-gray-900">{{ __('app.subscription.request_history') }}</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-50 text-gray-500 uppercase text-xs tracking-wider">
                        <tr>
                            <th class="px-5 py-3">{{ __('app.label.business') }}</th>
                            <th class="px-5 py-3">{{ __('app.common.plan') }}</th>
                            <th class="px-5 py-3">{{ __('app.label.months') }}</th>
                            <th class="px-5 py-3">{{ __('app.common.total') }}</th>
                            <th class="px-5 py-3">{{ __('app.common.status') }}</th>
                            <th class="px-5 py-3">{{ __('app.admin.admin') }}</th>
                            <th class="px-5 py-3">{{ __('app.common.date') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach ($handled as $req)
                            @php $tone = ['green' => 'bg-green-100 text-green-700', 'red' => 'bg-red-100 text-red-700', 'yellow' => 'bg-yellow-100 text-yellow-800', 'gray' => 'bg-gray-100 text-gray-600'][$req->statusColor()]; @endphp
                            <tr>
                                <td class="px-5 py-3 font-medium text-gray-900">{{ $req->owner?->business_name }}</td>
                                <td class="px-5 py-3">{{ $req->plan?->name }}</td>
                                <td class="px-5 py-3">{{ $req->months }}</td>
                                <td class="px-5 py-3">ج.م {{ number_format($req->amount, 2) }}</td>
                                <td class="px-5 py-3">
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $tone }}">
                                        {{ __('app.subscription.status_' . $req->status) }}
                                    </span>
                                </td>
                                <td class="px-5 py-3 text-gray-500">{{ $req->admin?->name ?? '—' }}</td>
                                <td class="px-5 py-3 text-gray-500">{{ $req->handled_at?->format('d M Y') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
