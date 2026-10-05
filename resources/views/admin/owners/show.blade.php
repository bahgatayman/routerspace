@extends('layouts.admin')

@section('page-title', $owner->business_name)

@section('content')
    @include('admin.business._header', ['active' => 'subscription', 'workspace' => null])

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Owner Info -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-800">{{ __('app.admin.owner_info') }}</h2>
            </div>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500">{{ __('app.common.name') }}</dt>
                    <dd class="text-gray-900 font-medium">{{ $owner->name }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">{{ __('app.common.email') }}</dt>
                    <dd class="text-gray-900">{{ $owner->email }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">{{ __('app.label.business') }}</dt>
                    <dd class="text-gray-900 font-medium">{{ $owner->business_name }}</dd>
                </div>
                @if ($owner->hasFeature('hotspot'))
                <div class="flex justify-between">
                    <dt class="text-gray-500">{{ __('app.label.mikrotik_host') }}</dt>
                    <dd class="text-gray-900">{{ $owner->mikrotik_host ? $owner->mikrotik_host.':'.$owner->mikrotik_port : '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">{{ __('app.label.mikrotik_username') }}</dt>
                    <dd class="text-gray-900">{{ $owner->mikrotik_username ?: '—' }}</dd>
                </div>
                @endif
                <div class="flex justify-between">
                    <dt class="text-gray-500">{{ __('app.user.hotspot_users') }}</dt>
                    <dd class="text-gray-900">
                        <a href="/admin/owners/{{ $owner->id }}/users" class="text-blue-600 hover:underline font-medium">{{ $usersCount }}</a>
                    </dd>
                </div>
            </dl>
        </div>

        <!-- Subscription Card -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">{{ __('app.admin.subscription') }}</h2>

            @php $status = $owner->subscriptionStatus(); @endphp
            <div class="mb-4">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                    {{ $status === 'active' ? 'bg-green-100 text-green-800' : '' }}
                    {{ $status === 'expiring_soon' ? 'bg-yellow-100 text-yellow-800' : '' }}
                    {{ $status === 'expired' ? 'bg-red-100 text-red-800' : '' }}
                    {{ $status === 'never' ? 'bg-gray-100 text-gray-800' : '' }}
                    {{ $status === 'disabled' ? 'bg-red-100 text-red-800' : '' }}">
                    @if ($status === 'active') {{ __('app.status.active') }}
                    @elseif ($status === 'expiring_soon') {{ __('app.status.expiring_soon') }} ({{ $owner->daysUntilExpiry() }}d)
                    @elseif ($status === 'expired') {{ __('app.status.expired') }}
                    @elseif ($status === 'never') {{ __('app.status.never_activated') }}
                    @elseif ($status === 'disabled') {{ __('app.status.disabled') }}
                    @endif
                </span>
            </div>

            @if ($owner->subscription_expires_at)
                <dl class="space-y-2 text-sm mb-4">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">{{ __('app.label.expires_at') }}</dt>
                        <dd class="text-gray-900 font-medium">{{ $owner->subscription_expires_at->format('Y-m-d') }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">{{ __('app.label.days_remaining') }}</dt>
                        <dd class="text-gray-900">{{ $owner->daysUntilExpiry() }}</dd>
                    </div>
                </dl>
            @endif

            <h3 class="text-md font-semibold text-gray-800 mb-3 mt-6">{{ __('app.label.renew_subscription') }}</h3>
            <form method="POST" action="/admin/owners/{{ $owner->id }}/renew">
                @csrf

                <!-- Plan selector cards -->
                <div class="grid grid-cols-2 gap-3 mb-4">
                    @foreach($plans as $plan)
                    <label class="cursor-pointer">
                        <input type="radio" name="plan_id" value="{{ $plan->id }}"
                            {{ $owner->plan_id == $plan->id ? 'checked' : '' }}
                            class="sr-only peer">
                        <div class="border-2 rounded-xl p-3 transition
                            peer-checked:border-red-500 peer-checked:bg-red-50
                            hover:border-gray-300">
                            <p class="font-semibold text-sm text-gray-900">{{ $plan->name }}</p>
                            <p class="text-xs text-gray-500">{{ $plan->max_members }} {{ __('app.plan.members') }}</p>
                            <p class="text-sm font-bold text-red-600 mt-1">{{ $plan->formattedPrice() }}</p>
                        </div>
                    </label>
                    @endforeach
                </div>

                <!-- Toggle: Months / Custom Date -->
                <div class="flex gap-2 mb-3 bg-gray-100 rounded-lg p-1">
                    <button type="button" id="tab-months"
                        class="flex-1 text-sm font-medium py-1.5 rounded-md bg-white shadow-sm"
                        onclick="switchMode('months')">{{ __('app.table.th.months') }}</button>
                    <button type="button" id="tab-date"
                        class="flex-1 text-sm font-medium py-1.5 rounded-md text-gray-500 hover:text-gray-700"
                        onclick="switchMode('date')">{{ __('app.common.date') }}</button>
                </div>

                <!-- Months input (default) -->
                <div id="field-months" class="mb-3">
                    <label class="text-sm font-medium text-gray-700">{{ __('app.table.th.months') }}</label>
                    <input type="number" name="months" min="1" max="24" value="1"
                        id="months-input"
                        class="mt-1 w-full border rounded-xl px-4 py-2.5">
                </div>

                <!-- Date input (hidden by default) -->
                <div id="field-date" class="mb-3 hidden">
                    <label class="text-sm font-medium text-gray-700">{{ __('app.label.expires_at') }}</label>
                    <input type="date" name="expires_at"
                        id="date-input" value="{{ old('expires_at', $owner->subscription_expires_at?->addMonths(1)->format('Y-m-d')) }}"
                        class="mt-1 w-full border rounded-xl px-4 py-2.5">
                </div>

                <!-- Auto-calculated total -->
                <div class="bg-gray-50 rounded-xl p-3 mb-3 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-600">{{ __('app.label.total_amount') }}</span>
                        <span id="total-amount" class="font-bold text-red-600">ج.م 0</span>
                    </div>
                </div>

                <!-- Notes -->
                <input type="text" name="notes" placeholder="{{ __('app.placeholder.notes_optional') }}"
                    class="w-full border rounded-xl px-4 py-2.5 mb-3 text-sm">

                <button type="submit"
                    class="w-full bg-red-600 text-white py-2.5 rounded-xl font-semibold hover:bg-red-700">
                    {{ __('app.btn.renew_subscription') }}
                </button>
            </form>

            <script>
            const planPrices = {
                @foreach($plans as $plan)
                {{ $plan->id }}: {{ $plan->price_per_month }},
                @endforeach
            };
            const baseDate = new Date('{{ ($owner->subscription_expires_at?->isFuture() ? $owner->subscription_expires_at : now())->format("Y-m-d") }}');

            function calcMonthsBetween(d1, d2) {
                let months = (d2.getFullYear() - d1.getFullYear()) * 12;
                months += d2.getMonth() - d1.getMonth();
                return Math.max(1, months + (d2.getDate() >= d1.getDate() ? 0 : -1));
            }

            function updateTotal() {
                const selectedPlan = document.querySelector('input[name="plan_id"]:checked');
                if (!selectedPlan) return;
                const price = planPrices[selectedPlan.value] || 0;
                let months = 0;

                if (document.getElementById('field-months').classList.contains('hidden')) {
                    const dateVal = document.getElementById('date-input').value;
                    if (dateVal) {
                        const d = new Date(dateVal + 'T23:59:59');
                        months = calcMonthsBetween(baseDate, d);
                    }
                } else {
                    months = parseInt(document.getElementById('months-input').value) || 0;
                }

                const total = price * months;
                document.getElementById('total-amount').textContent =
                    total === 0 ? 'Free' : 'ج.م ' + total.toLocaleString();
            }

            function switchMode(mode) {
                document.getElementById('field-months').classList.toggle('hidden', mode !== 'months');
                document.getElementById('field-date').classList.toggle('hidden', mode !== 'date');
                document.getElementById('tab-months').className =
                    'flex-1 text-sm font-medium py-1.5 rounded-md ' +
                    (mode === 'months' ? 'bg-white shadow-sm' : 'text-gray-500 hover:text-gray-700');
                document.getElementById('tab-date').className =
                    'flex-1 text-sm font-medium py-1.5 rounded-md ' +
                    (mode === 'date' ? 'bg-white shadow-sm' : 'text-gray-500 hover:text-gray-700');
                updateTotal();
            }

            document.querySelectorAll('input[name="plan_id"]').forEach(r =>
                r.addEventListener('change', updateTotal));
            document.getElementById('months-input').addEventListener('input', updateTotal);
            document.getElementById('date-input').addEventListener('input', updateTotal);
            updateTotal();
            </script>
        </div>
    </div>

    <!-- Subscription History -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mt-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">{{ __('app.label.subscription_history') }}</h2>
        @if ($subscriptions->isEmpty())
            <p class="text-sm text-gray-500">{{ __('app.empty.no_subscriptions') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 bg-gray-50 border-b border-gray-100">
                            <th class="px-4 py-3 font-medium">{{ __('app.table.th.months') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('app.table.th.starts_at') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('app.table.th.expires_at') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('app.table.th.notes') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('app.table.th.renewed_by') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('app.table.th.date') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($subscriptions as $sub)
                            <tr class="border-b border-gray-50">
                                <td class="px-4 py-3 font-medium">{{ $sub->months }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $sub->starts_at->format('Y-m-d') }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $sub->expires_at->format('Y-m-d') }}</td>
                                <td class="px-4 py-3 text-gray-500 max-w-[200px] truncate">{{ $sub->notes ?? '—' }}</td>
                                <td class="px-4 py-3">{{ $sub->admin?->name ?? '—' }}</td>
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
        <h3 class="text-lg font-semibold text-gray-900 mb-4">{{ __('app.admin.feature_access') }}</h3>
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
                            <span class="text-xs text-gray-400 bg-gray-100 px-2 py-1 rounded-full">{{ __('app.status.globally_disabled') }}</span>
                        @else
                            <form method="POST" action="/admin/owners/{{ $owner->id }}/features/{{ $feature->id }}/toggle">
                                @csrf
                                <button type="submit"
                                    class="text-xs px-3 py-1 rounded-full font-medium transition
                                    {{ $owner->features->contains('id', $feature->id)
                                        ? 'bg-green-100 text-green-700 hover:bg-red-100 hover:text-red-700'
                                        : 'bg-gray-100 text-gray-600 hover:bg-green-100 hover:text-green-700' }}">
                                    {{ $owner->features->contains('id', $feature->id) ? '✓ ' . __('app.status.enabled') : '+ ' . __('app.btn.enable') }}
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endsection
