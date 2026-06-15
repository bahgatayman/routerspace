@extends('layouts.admin')

@section('page-title', $owner->business_name)

@section('content')
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Owner Info -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-800">Owner Information</h2>
                <form method="POST" action="/admin/owners/{{ $owner->id }}/toggle-active">
                    @csrf
                    @method('PUT')
                    <button type="submit" class="text-sm font-medium px-3 py-1.5 rounded-lg border transition
                        {{ $owner->is_active ? 'text-red-600 border-red-200 hover:bg-red-50' : 'text-green-600 border-green-200 hover:bg-green-50' }}"
                            onclick="return confirm('{{ $owner->is_active ? 'Deactivate' : 'Activate' }} this owner?')">
                        {{ $owner->is_active ? 'Deactivate' : 'Activate' }}
                    </button>
                </form>
            </div>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Name</dt>
                    <dd class="text-gray-900 font-medium">{{ $owner->name }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Email</dt>
                    <dd class="text-gray-900">{{ $owner->email }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Business</dt>
                    <dd class="text-gray-900 font-medium">{{ $owner->business_name }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">MikroTik Host</dt>
                    <dd class="text-gray-900">{{ $owner->mikrotik_host }}:{{ $owner->mikrotik_port }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">MikroTik Username</dt>
                    <dd class="text-gray-900">{{ $owner->mikrotik_username }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Hotspot Users</dt>
                    <dd class="text-gray-900">
                        <a href="/admin/owners/{{ $owner->id }}/users" class="text-blue-600 hover:underline font-medium">{{ $usersCount }}</a>
                    </dd>
                </div>
            </dl>
        </div>

        <!-- Subscription Card -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">Subscription</h2>

            @php $status = $owner->subscriptionStatus(); @endphp
            <div class="mb-4">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                    {{ $status === 'active' ? 'bg-green-100 text-green-800' : '' }}
                    {{ $status === 'expiring_soon' ? 'bg-yellow-100 text-yellow-800' : '' }}
                    {{ $status === 'expired' ? 'bg-red-100 text-red-800' : '' }}
                    {{ $status === 'never' ? 'bg-gray-100 text-gray-800' : '' }}
                    {{ $status === 'disabled' ? 'bg-red-100 text-red-800' : '' }}">
                    @if ($status === 'active') Active
                    @elseif ($status === 'expiring_soon') Expiring Soon ({{ $owner->daysUntilExpiry() }} days)
                    @elseif ($status === 'expired') Expired
                    @elseif ($status === 'never') Never Activated
                    @elseif ($status === 'disabled') Disabled
                    @endif
                </span>
            </div>

            @if ($owner->subscription_expires_at)
                <dl class="space-y-2 text-sm mb-4">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Expires At</dt>
                        <dd class="text-gray-900 font-medium">{{ $owner->subscription_expires_at->format('Y-m-d') }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Days Remaining</dt>
                        <dd class="text-gray-900">{{ $owner->daysUntilExpiry() }}</dd>
                    </div>
                </dl>
            @endif

            <h3 class="text-md font-semibold text-gray-800 mb-3 mt-6">Renew Subscription</h3>
            <form method="POST" action="/admin/owners/{{ $owner->id }}/renew" class="space-y-3">
                @csrf
                <div class="flex gap-3">
                    <div class="flex-1">
                        <label class="block text-xs text-gray-500 mb-1">Months</label>
                        <input type="number" name="months" min="1" max="24" value="1" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
                    </div>
                    <div class="flex-[2]">
                        <label class="block text-xs text-gray-500 mb-1">Notes (optional)</label>
                        <input type="text" name="notes" placeholder="Renewal note..."
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
                    </div>
                </div>
                <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700 transition text-sm font-medium shadow-sm">
                    Renew Subscription
                </button>
            </form>
        </div>
    </div>

    <!-- Subscription History -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mt-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Subscription History</h2>
        @if ($subscriptions->isEmpty())
            <p class="text-sm text-gray-500">No subscription records.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 bg-gray-50 border-b border-gray-100">
                            <th class="px-4 py-3 font-medium">Months</th>
                            <th class="px-4 py-3 font-medium">Starts At</th>
                            <th class="px-4 py-3 font-medium">Expires At</th>
                            <th class="px-4 py-3 font-medium">Notes</th>
                            <th class="px-4 py-3 font-medium">Renewed By</th>
                            <th class="px-4 py-3 font-medium">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($subscriptions as $sub)
                            <tr class="border-b border-gray-50">
                                <td class="px-4 py-3 font-medium">{{ $sub->months }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $sub->starts_at->format('Y-m-d') }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $sub->expires_at->format('Y-m-d') }}</td>
                                <td class="px-4 py-3 text-gray-500 max-w-[200px] truncate">{{ $sub->notes ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $sub->admin->name }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ $sub->created_at->format('Y-m-d') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <!-- Feature Access -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mt-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Feature Access</h3>
        <div class="grid grid-cols-1 gap-3">
            @foreach ($features as $feature)
                <div class="flex items-center justify-between p-3 rounded-lg border
                    {{ $owner->features->contains('id', $feature->id) ? 'bg-green-50 border-green-200' : 'bg-gray-50 border-gray-200' }}">
                    <div class="flex items-center gap-3">
                        @include('admin.features._icon', ['icon' => $feature->icon])
                        <div>
                            <p class="text-sm font-medium text-gray-900">{{ $feature->name }}</p>
                            <p class="text-xs text-gray-500">{{ $feature->description }}</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        @if (!$feature->is_active)
                            <span class="text-xs text-gray-400 bg-gray-100 px-2 py-1 rounded-full">Globally Disabled</span>
                        @else
                            <form method="POST" action="/admin/owners/{{ $owner->id }}/features/{{ $feature->id }}/toggle">
                                @csrf
                                <button type="submit"
                                    class="text-xs px-3 py-1 rounded-full font-medium transition
                                    {{ $owner->features->contains('id', $feature->id)
                                        ? 'bg-green-100 text-green-700 hover:bg-red-100 hover:text-red-700'
                                        : 'bg-gray-100 text-gray-600 hover:bg-green-100 hover:text-green-700' }}">
                                    {{ $owner->features->contains('id', $feature->id) ? '✓ Enabled' : '+ Enable' }}
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endsection
