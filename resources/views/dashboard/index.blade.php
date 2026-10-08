@extends('layouts.app')

@section('page-title', __('app.section.dashboard'))

@section('content')
    <div class="flex flex-wrap items-center gap-3 mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Welcome, {{ $owner->business_name }}</h1>
        @if ($hasConfiguredWorkingHours ?? false)
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium {{ $isOpenNow ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                <span class="w-1.5 h-1.5 rounded-full {{ $isOpenNow ? 'bg-green-500' : 'bg-gray-400' }}"></span>
                {{ $isOpenNow ? __('app.label.open_now') : __('app.label.closed_now') }}
            </span>
        @endif
    </div>

    @if ($owner->hasFeature('hotspot') && $mikrotikError)
        <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-lg mb-6">
            MikroTik unreachable — live stats unavailable
        </div>
    @endif

    {{-- ================= Top KPIs — the 4-6 most important numbers =================
         Needs Attention always renders here regardless of feature mix (a
         hotspot-only owner still needs to see subscription alerts), so this
         grid is never conditionally hidden as a whole. --}}
    {{-- Sized by the space it actually has (container queries in panel.css), not the viewport: 2 → 3 → 6 columns, Revenue leads at double width. --}}
    <div class="ls-kpis-wrap mb-8"><div class="ls-kpis ls-kpis--5">
        @if ($showRevenue)
            <div class="ls-kpi-hero bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
                <div class="flex items-center justify-between">
                    <div class="min-w-0">
                        <p class="text-xs lg:text-sm font-medium text-gray-500">{{ __('app.dashboard.revenue_today') }}</p>
                        <p class="text-2xl lg:text-3xl font-bold text-green-600 mt-1 whitespace-nowrap">ج.م {{ number_format($revenueToday, 2) }}</p>
                        <p class="text-xs text-gray-400 mt-1 truncate">{{ __('app.dashboard.revenue_this_month') }}: ج.م {{ number_format($revenueThisMonth, 2) }}</p>
                        @if ($revenueComparison['changePercent'] !== null)
                            @php $up = $revenueComparison['change'] >= 0; @endphp
                            <p class="text-xs mt-1 font-medium {{ $up ? 'text-green-600' : 'text-red-600' }}">
                                {{ $up ? '▲' : '▼' }} {{ number_format(abs($revenueComparison['changePercent']), 1) }}% {{ __('app.dashboard.vs_previous_period') }}
                            </p>
                        @endif
                    </div>
                    <div class="ls-kpi-icon w-10 h-10 lg:w-12 lg:h-12 bg-green-50 rounded-xl flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 lg:w-6 lg:h-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                </div>
            </div>
        @endif

        @if ($owner->hasFeature('booking'))
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs lg:text-sm font-medium text-gray-500">{{ __('app.label.today_bookings') }}</p>
                        <p class="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{{ $todayBookings }}</p>
                    </div>
                    <div class="ls-kpi-icon w-10 h-10 lg:w-12 lg:h-12 bg-orange-50 rounded-xl flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 lg:w-6 lg:h-6 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                    </div>
                </div>
            </div>
        @endif

        @if ($showWorkspace)
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs lg:text-sm font-medium text-gray-500">{{ __('app.dashboard.current_occupancy') }}</p>
                        <p class="text-2xl lg:text-3xl font-bold text-brand-600 mt-1">{{ $occupancy['percent'] }}%</p>
                        <p class="text-xs text-gray-400 mt-1">{{ __('app.dashboard.seats_occupied', ['occupied' => $occupancy['occupied'], 'capacity' => $occupancy['capacity']]) }}</p>
                    </div>
                    <div class="ls-kpi-icon w-10 h-10 lg:w-12 lg:h-12 bg-brand-50 rounded-xl flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 lg:w-6 lg:h-6 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs lg:text-sm font-medium text-gray-500">{{ __('app.label.available_rooms') }}</p>
                        <p class="text-2xl lg:text-3xl font-bold text-green-600 mt-1">{{ $availableRoomsNow }}</p>
                    </div>
                    <div class="ls-kpi-icon w-10 h-10 lg:w-12 lg:h-12 bg-green-50 rounded-xl flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 lg:w-6 lg:h-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                        </svg>
                    </div>
                </div>
            </div>
        @endif

        <a href="{{ route('notifications.index') }}" class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6 hover:shadow-md transition block">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs lg:text-sm font-medium text-gray-500">{{ __('app.dashboard.needs_attention') }}</p>
                    <p class="text-2xl lg:text-3xl font-bold {{ $needsAttentionCount > 0 ? 'text-red-600' : 'text-gray-900' }} mt-1">{{ $needsAttentionCount }}</p>
                </div>
                <div class="ls-kpi-icon w-10 h-10 lg:w-12 lg:h-12 {{ $needsAttentionCount > 0 ? 'bg-red-50' : 'bg-gray-50' }} rounded-xl flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 {{ $needsAttentionCount > 0 ? 'text-red-600' : 'text-gray-400' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                    </svg>
                </div>
            </div>
        </a>
    </div></div>

    {{-- ================= Period selector — drives trend/utilization/peak-hours/status/new-customers/products ================= --}}
    @if ($showRevenue || $owner->hasFeature('booking') || $showProducts)
        <div class="flex flex-wrap items-center gap-3 mb-6">
            <div class="inline-flex items-center gap-1 bg-white border border-gray-100 rounded-lg p-1 shadow-sm">
                @foreach (['today' => 'app.dashboard.period_today', '7d' => 'app.dashboard.period_7d', '30d' => 'app.dashboard.period_30d', '3mo' => 'app.dashboard.period_3mo', '12mo' => 'app.dashboard.period_12mo'] as $key => $labelKey)
                    <a href="{{ request()->fullUrlWithQuery(['period' => $key]) }}"
                       class="px-3 py-1.5 rounded-md text-sm font-medium transition {{ $periodKey === $key ? 'bg-brand-600 text-white' : 'text-gray-500 hover:bg-gray-50' }}">
                        {{ __($labelKey) }}
                    </a>
                @endforeach
            </div>
            <form method="GET" class="flex items-center gap-2">
                <input type="hidden" name="period" value="custom">
                @foreach(request()->except(['period', 'start', 'end']) as $key => $value)
                    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                @endforeach
                <input type="date" name="start" value="{{ $customStart }}" class="border border-gray-200 rounded-md text-sm px-2 py-1.5 text-gray-700">
                <span class="text-gray-400 text-sm">&ndash;</span>
                <input type="date" name="end" value="{{ $customEnd }}" class="border border-gray-200 rounded-md text-sm px-2 py-1.5 text-gray-700">
                <button type="submit" class="px-3 py-1.5 rounded-md text-sm font-medium transition {{ $periodKey === 'custom' ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                    {{ __('app.dashboard.period_apply') }}
                </button>
            </form>
        </div>
    @endif

    {{-- ================= Middle — trends & operational insight ================= --}}
    @if ($showRevenue)
        @php $maxTrend = max(1, ...array_values($revenueTrend)); @endphp
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6 mb-6">
            <h3 class="font-semibold text-gray-900 mb-4">{{ __('app.dashboard.revenue_trend') }}</h3>
            <div class="flex items-end gap-1 lg:gap-2 h-40 overflow-x-auto">
                @foreach ($revenueTrend as $date => $amount)
                    <div class="flex-1 min-w-[1.5rem] flex flex-col items-center gap-1 h-full justify-end">
                        <span class="text-[10px] text-gray-500 whitespace-nowrap">{{ $amount > 0 ? number_format($amount, 0) : '' }}</span>
                        <div class="w-full rounded-t-md bg-brand-500" style="height: {{ ($amount / $maxTrend) * 120 }}px; min-height: 2px;"></div>
                        <span class="text-[10px] text-gray-400">{{ \Carbon\Carbon::parse($date)->format('j M') }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($showProducts)
        @include('dashboard._products-section')
    @endif

    @if ($showWorkspace || $owner->hasFeature('booking'))
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 lg:gap-6 mb-6">
        @if ($showWorkspace && $owner->hasFeature('booking'))
            @php
                $barColors = ['blue' => 'bg-blue-500', 'purple' => 'bg-purple-500', 'green' => 'bg-green-500', 'orange' => 'bg-orange-500', 'gray' => 'bg-gray-400'];
                $maxUtil = max(1, $roomUtilization->max(fn ($r) => $r['utilization_percent'] ?? $r['hours_booked']));
            @endphp
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6">
                <h3 class="font-semibold text-gray-900 mb-4">{{ __('app.dashboard.room_utilization') }}</h3>
                @forelse ($roomUtilization as $row)
                    @php $value = $row['utilization_percent'] ?? $row['hours_booked']; @endphp
                    <div class="mb-3 last:mb-0">
                        <div class="flex items-center justify-between text-xs mb-1 gap-2">
                            <span class="font-medium text-gray-700 truncate">{{ $row['room_name'] }}</span>
                            <span class="text-gray-400 shrink-0">
                                {{ $row['utilization_percent'] !== null ? $row['utilization_percent'].'%' : $row['hours_booked'].'h' }}
                            </span>
                        </div>
                        <div class="w-full bg-gray-100 rounded-full h-2">
                            <div class="h-2 rounded-full {{ $barColors[$row['room']->typeColor()] ?? 'bg-gray-400' }}" style="width: {{ ($value / $maxUtil) * 100 }}%"></div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-400">{{ __('app.dashboard.no_room_data') }}</p>
                @endforelse
                @if ($roomUtilization->isNotEmpty() && ! $roomUtilization->first()['utilization_percent'])
                    <p class="text-[11px] text-gray-400 mt-3">{{ __('app.dashboard.working_hours_not_configured') }}</p>
                @endif
            </div>
        @endif

        @if ($owner->hasFeature('booking'))
            @php $maxPeak = max(1, ...array_values(array_merge([0], $peakHours))); @endphp
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6">
                <h3 class="font-semibold text-gray-900 mb-4">{{ __('app.dashboard.peak_hours') }}</h3>
                @if (empty($peakHours))
                    <p class="text-sm text-gray-400">{{ __('app.dashboard.no_data_for_period') }}</p>
                @else
                    <div class="flex items-end gap-0.5 h-32">
                        @for ($hour = 0; $hour < 24; $hour++)
                            @php $count = $peakHours[$hour] ?? 0; @endphp
                            <div class="flex-1 flex flex-col items-center justify-end h-full gap-1" title="{{ sprintf('%02d:00', $hour) }} — {{ $count }}">
                                <div class="w-full rounded-t bg-brand-400" style="height: {{ ($count / $maxPeak) * 100 }}px; min-height: {{ $count > 0 ? 2 : 0 }}px;"></div>
                                @if ($hour % 4 === 0)
                                    <span class="text-[9px] text-gray-400">{{ $hour }}</span>
                                @endif
                            </div>
                        @endfor
                    </div>
                @endif
            </div>

            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6">
                <h3 class="font-semibold text-gray-900 mb-4">{{ __('app.dashboard.booking_status') }}</h3>
                @php $totalStatus = array_sum($statusBreakdown); @endphp
                @if ($totalStatus === 0)
                    <p class="text-sm text-gray-400">{{ __('app.dashboard.no_data_for_period') }}</p>
                @else
                    <div class="space-y-2">
                        @foreach ($statusBreakdown as $status => $count)
                            @continue($count === 0)
                            @php $tmp = new \App\Models\Booking(['status' => $status]); @endphp
                            <div class="flex items-center justify-between text-sm">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $tmp->statusBadgeClass() }}">
                                    {{ $tmp->statusLabel() }}
                                </span>
                                <span class="text-gray-500">{{ $count }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </div>
    @endif

    @if ($owner->hasFeature('hotspot'))
    <div class="ls-kpis-wrap mb-8"><div class="ls-kpis ls-kpis--4">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs lg:text-sm font-medium text-gray-500">{{ __('app.label.total_users') }}</p>
                    <p class="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{{ $totalUsers }}</p>
                </div>
                <div class="ls-kpi-icon w-10 h-10 lg:w-12 lg:h-12 bg-blue-50 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs lg:text-sm font-medium text-gray-500">{{ __('app.label.active_users') }}</p>
                    <p class="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{{ $activeUsers }}</p>
                </div>
                <div class="ls-kpi-icon w-10 h-10 lg:w-12 lg:h-12 bg-green-50 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs lg:text-sm font-medium text-gray-500">{{ __('app.label.online_now') }}</p>
                    <p class="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{{ $activeSessions }}</p>
                    <p class="text-xs text-gray-400 mt-1">Live from MikroTik</p>
                </div>
                <div class="ls-kpi-icon w-10 h-10 lg:w-12 lg:h-12 bg-purple-50 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
                    </svg>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs lg:text-sm font-medium text-gray-500">{{ __('app.section.speed_profiles') }}</p>
                    <p class="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{{ $totalProfiles }}</p>
                </div>
                <div class="ls-kpi-icon w-10 h-10 lg:w-12 lg:h-12 bg-orange-50 rounded-xl flex items-center justify-center">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
            </div>
        </div>
    </div></div>

    <h2 class="text-lg font-semibold text-gray-700 mb-4">{{ __('app.label.quick_links') }}</h2>
    <div class="flex flex-col sm:flex-row flex-wrap gap-3 lg:gap-4 mb-8">
        <a href="/users/create" class="bg-brand-600 text-white px-5 py-2.5 rounded-lg hover:bg-brand-700 transition text-sm font-medium shadow-sm text-center">
            {{ __('app.btn.add_user') }}
        </a>
        <a href="/sessions" class="bg-purple-600 text-white px-5 py-2.5 rounded-lg hover:bg-purple-700 transition text-sm font-medium shadow-sm text-center">
            {{ __('app.label.view_active_sessions') }}
        </a>
        <a href="/speed-profiles" class="bg-orange-600 text-white px-5 py-2.5 rounded-lg hover:bg-orange-700 transition text-sm font-medium shadow-sm text-center">
            {{ __('app.label.manage_speed_profiles') }}
        </a>
    </div>
    @endif

    {{-- ================= Bottom — schedules, details, alerts ================= --}}
    @if ($owner->hasFeature('booking') || isset($newCustomers))
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 lg:gap-6 mb-6">
        @if ($owner->hasFeature('booking'))
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6 lg:col-span-2">
                <h3 class="font-semibold text-gray-900 mb-4">{{ __('app.dashboard.todays_schedule') }}</h3>
                @forelse ($todaysSchedule as $booking)
                    <div class="flex items-center justify-between gap-3 py-2.5 border-b border-gray-50 last:border-0 text-sm">
                        <div class="min-w-0">
                            <p class="font-medium text-gray-800 truncate">{{ $booking->hotspotUser?->name ?? '—' }}</p>
                            <p class="text-xs text-gray-400 truncate">{{ $booking->room?->name }} &middot; {{ $booking->timeRange() }}</p>
                        </div>
                        <span class="shrink-0 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $booking->statusBadgeClass() }}">
                            {{ $booking->statusLabel() }}
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-gray-400">{{ __('app.dashboard.no_bookings_today') }}</p>
                @endforelse
            </div>
        @endif

        @if (isset($newCustomers))
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6">
                <p class="text-xs lg:text-sm font-medium text-gray-500">{{ __('app.dashboard.new_customers') }}</p>
                <p class="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{{ $newCustomers }}</p>
                <p class="text-[11px] text-gray-400 mt-1">{{ __('app.dashboard.period_'.$periodKey) }}</p>
            </div>
        @endif
    </div>
    @endif

    @if ($showWorkspace && $owner->hasFeature('booking') && isset($roomUtilization))
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6 mb-6">
            <h3 class="font-semibold text-gray-900 mb-4">{{ __('app.dashboard.room_utilization_details') }}</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b">
                            <th class="pb-3">{{ __('app.table.th.room') }}</th>
                            <th class="pb-3 hidden sm:table-cell">{{ __('app.dashboard.hours_booked') }}</th>
                            <th class="pb-3 hidden sm:table-cell">{{ __('app.dashboard.bookings') }}</th>
                            @if ($canViewRevenue)
                                <th class="pb-3">{{ __('app.financial.revenue') }}</th>
                            @endif
                            <th class="pb-3">{{ __('app.dashboard.room_utilization') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($roomUtilization as $row)
                            <tr class="border-b last:border-0">
                                <td class="py-3 pe-3 font-medium text-gray-900">
                                    {{ $row['room_name'] }}
                                    <span class="block sm:hidden text-xs font-normal text-gray-500">{{ $row['hours_booked'] }}{{ __('app.ui.unit_h') }} · {{ $row['bookings_count'] }} {{ __('app.dashboard.bookings') }}</span>
                                </td>
                                <td class="py-3 hidden sm:table-cell">{{ $row['hours_booked'] }}</td>
                                <td class="py-3 hidden sm:table-cell">{{ $row['bookings_count'] }}</td>
                                @if ($canViewRevenue)
                                    <td class="py-3 pe-3 whitespace-nowrap">ج.م {{ number_format($row['revenue'], 2) }}</td>
                                @endif
                                <td class="py-3">{{ $row['utilization_percent'] !== null ? $row['utilization_percent'].'%' : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $canViewRevenue ? 5 : 4 }}" class="py-6 text-center text-gray-400">{{ __('app.dashboard.no_room_data') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6 mb-8">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-gray-900">{{ __('app.dashboard.needs_attention') }}</h3>
            <a href="{{ route('notifications.index') }}" class="text-xs font-medium text-brand-600 hover:text-brand-800">{{ __('app.notif.view_all') }}</a>
        </div>
        @forelse ($needsAttentionItems as $n)
            @php $c = $n->levelColor(); @endphp
            <a href="{{ route('notifications.open', $n->id) }}" class="flex items-start gap-3 py-2.5 border-b border-gray-50 last:border-0 hover:bg-gray-50 -mx-2 px-2 rounded-lg transition">
                <span class="mt-0.5 shrink-0 w-8 h-8 rounded-full flex items-center justify-center bg-{{ $c }}-100 text-{{ $c }}-600">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $n->iconPath() }}"/>
                    </svg>
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-medium text-gray-800">{{ $n->title }}</span>
                    @if ($n->body)
                        <span class="block text-xs text-gray-500">{{ $n->body }}</span>
                    @endif
                    <span class="block text-[11px] text-gray-400 mt-0.5">{{ $n->created_at->diffForHumans() }}</span>
                </span>
            </a>
        @empty
            <p class="text-sm text-gray-400">{{ __('app.dashboard.all_caught_up') }}</p>
        @endforelse
    </div>

    @unless($isStaff)
    <div class="mt-8">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">{{ __('app.label.your_features') }}</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @php $ownerFeatures = $owner->features()->where('is_active', true)->get(); @endphp
            @foreach ($ownerFeatures as $feature)
                <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 flex items-center gap-4">
                    @include('admin.features._icon', ['icon' => $feature->icon])
                    <div>
                        <p class="font-medium text-gray-900 text-sm">{{ $feature->name }}</p>
                        <p class="text-xs text-gray-500 mt-1">{{ $feature->description }}</p>
                    </div>
                </div>
            @endforeach
            @if ($ownerFeatures->isEmpty())
                <div class="col-span-3 text-center py-8 text-gray-400 text-sm">
                    {{ __('app.empty.no_features') }}
                </div>
            @endif
        </div>
    </div>
    @endunless
@endsection
