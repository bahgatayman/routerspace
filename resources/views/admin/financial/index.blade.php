@extends('layouts.admin')

@section('page-title', __('app.section.financial'))

@section('content')

    <!-- Stat Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-xl shadow-sm border p-6">
            <p class="text-sm text-gray-500">{{ __('app.financial.total_revenue_all_time') }}</p>
            <p class="text-3xl font-bold text-gray-900 mt-1">
                ج.م {{ number_format($totalRevenue, 0) }}
            </p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-6">
            <p class="text-sm text-gray-500">{{ __('app.financial.this_month') }}</p>
            <p class="text-3xl font-bold text-green-600 mt-1">
                ج.م {{ number_format($thisMonthRevenue, 0) }}
            </p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-6">
            <p class="text-sm text-gray-500">{{ __('app.financial.this_year') }} ({{ $year }})</p>
            <p class="text-3xl font-bold text-blue-600 mt-1">
                ج.م {{ number_format($thisYearRevenue, 0) }}
            </p>
        </div>
        <div class="bg-white rounded-xl shadow-sm border p-6">
            <p class="text-sm text-gray-500">{{ __('app.financial.active_subscribers') }}</p>
            <p class="text-3xl font-bold text-purple-600 mt-1">
                {{ $activePerPlan->sum('count') }}
            </p>
        </div>
    </div>

    <!-- Monthly Revenue Bar Chart -->
    <div class="bg-white rounded-xl border shadow-sm p-6 mt-6">
        <div class="flex justify-between items-center mb-6">
            <h3 class="font-semibold text-gray-900">{{ __('app.financial.monthly_revenue_chart') }} — {{ $year }}</h3>
            <form method="GET">
                <select name="year" onchange="this.form.submit()"
                    class="border rounded-lg px-3 py-1.5 text-sm">
                    @foreach(range(now()->year, now()->year - 3) as $y)
                    <option value="{{ $y }}" {{ $year == $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endforeach
                </select>
            </form>
        </div>

        @php $maxRevenue = $monthlyData->max('revenue') ?: 1; @endphp
        <div class="flex items-end gap-2 h-48">
            @foreach($monthlyData as $data)
            <div class="flex-1 flex flex-col items-center gap-1">
                <span class="text-xs text-gray-500">
                    {{ $data['revenue'] > 0 ? number_format($data['revenue'], 0) : '' }}
                </span>
                <div class="w-full rounded-t-md bg-blue-500 transition-all"
                     style="height: {{ ($data['revenue'] / $maxRevenue) * 160 }}px; min-height: 2px;">
                </div>
                <span class="text-xs text-gray-500">{{ $data['label'] }}</span>
                <span class="text-xs text-gray-400">{{ $data['renewals'] > 0 ? $data['renewals'] . 'x' : '' }}</span>
            </div>
            @endforeach
        </div>
    </div>

    <!-- Revenue by Plan -->
    <div class="bg-white rounded-xl border shadow-sm p-6 mt-6">
        <h3 class="font-semibold text-gray-900 mb-4">{{ __('app.financial.revenue_by_plan') }} ({{ $year }})</h3>
        <div class="overflow-x-auto">
        <table class="w-full min-w-[36rem] text-sm">
            <thead>
                <tr class="text-left text-gray-500 border-b">
                    <th class="pb-3">{{ __('app.financial.plan') }}</th>
                    <th class="pb-3">{{ __('app.financial.price_month') }}</th>
                    <th class="pb-3">{{ __('app.financial.renewals') }}</th>
                    <th class="pb-3">{{ __('app.financial.revenue') }}</th>
                    <th class="pb-3">{{ __('app.financial.active_now') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($revenueByPlan as $row)
                <tr class="border-b last:border-0">
                    <td class="py-3 font-medium">{{ $row->plan?->name ?? __('app.financial.no_plan') }}</td>
                    <td class="py-3">{{ $row->plan?->formattedPrice() ?? '—' }}</td>
                    <td class="py-3">{{ $row->count }}</td>
                    <td class="py-3 font-semibold text-blue-600">
                        ج.م {{ number_format($row->revenue, 0) }}
                    </td>
                    <td class="py-3">
                        {{ $activePerPlan->where('plan_id', $row->plan_id)->first()?->count ?? 0 }}
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>

    <!-- Expiring Soon -->
    @if($expiringSoon->count() > 0)
    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-5 mt-6">
        <h3 class="font-semibold text-yellow-800 mb-3">
            ⚠ {{ $expiringSoon->count() }} {{ __('app.financial.subscriptions_expiring_14_days') }}
        </h3>
        <div class="space-y-2">
            @foreach($expiringSoon as $owner)
            <div class="flex justify-between items-center text-sm">
                <div>
                    <span class="font-medium text-gray-900">{{ $owner->business_name }}</span>
                    <span class="text-gray-500 ml-2">({{ $owner->plan?->name ?? __('app.financial.no_plan') }})</span>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-yellow-700">
                        {{ __('app.financial.expires') }} {{ $owner->subscription_expires_at->diffForHumans() }}
                    </span>
                    <a href="/admin/owners/{{ $owner->id }}"
                       class="text-blue-600 text-xs hover:underline">{{ __('app.btn.renew_subscription') }}</a>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    <!-- Recent Transactions -->
    <div class="bg-white rounded-xl border shadow-sm p-6 mt-6">
        <h3 class="font-semibold text-gray-900 mb-4">{{ __('app.financial.recent_transactions') }}</h3>
        <div class="overflow-x-auto">
        <table class="w-full min-w-[40rem] text-sm">
            <thead>
                <tr class="text-left text-gray-500 border-b text-xs uppercase tracking-wider">
                    <th class="pb-3">{{ __('app.financial.date') }}</th>
                    <th class="pb-3">{{ __('app.financial.owner') }}</th>
                    <th class="pb-3">{{ __('app.financial.plan') }}</th>
                    <th class="pb-3">{{ __('app.financial.months') }}</th>
                    <th class="pb-3">{{ __('app.financial.amount') }}</th>
                    <th class="pb-3">{{ __('app.financial.expires') }}</th>
                    <th class="pb-3">{{ __('app.financial.by') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($recentTransactions as $sub)
                <tr class="border-b last:border-0 hover:bg-gray-50">
                    <td class="py-3 text-gray-500">{{ $sub->created_at->format('d M Y') }}</td>
                    <td class="py-3">
                        <a href="/admin/owners/{{ $sub->owner_id }}"
                           class="font-medium text-blue-600 hover:underline">
                            {{ $sub->owner->business_name }}
                        </a>
                    </td>
                    <td class="py-3">
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">
                            {{ $sub->plan?->name ?? __('app.financial.no_plan') }}
                        </span>
                    </td>
                    <td class="py-3 text-center">{{ $sub->months }}</td>
                    <td class="py-3 font-semibold text-gray-900">
                        ج.م {{ number_format($sub->amount_paid, 0) }}
                    </td>
                    <td class="py-3 text-gray-500">{{ $sub->expires_at->format('d M Y') }}</td>
                    <td class="py-3 text-gray-400 text-xs">{{ $sub->admin->name }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>
@endsection
