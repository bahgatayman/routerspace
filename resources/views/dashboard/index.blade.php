@extends('layouts.app')

@section('page-title', __('app.section.dashboard'))

@section('content')
    <x-ui.page-header :eyebrow="now()->translatedFormat('l, j F')" title="{{ __('app.dashboard.welcome', ['business' => $owner->business_name]) }}" class="mb-6">
        <x-slot:actions>
            @if ($hasConfiguredWorkingHours ?? false)
                <span class="ls-status {{ $isOpenNow ? 'ls-status--ok' : 'ls-status--neutral' }}">
                    <span class="ls-dot" @unless($isOpenNow) style="background: var(--color-text-muted); box-shadow: none;" @endunless></span>
                    {{ $isOpenNow ? __('app.label.open_now') : __('app.label.closed_now') }}
                </span>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($owner->hasFeature('hotspot') && $mikrotikError)
        <x-ui.banner tone="warn" class="mb-6">{{ __('app.dashboard.mikrotik_unreachable') }}</x-ui.banner>
    @endif

    {{-- ================= Hero — revenue trend chart (visual left) + Revenue Today (visual right) =================
         ls-visual-ltr forces grid column 1 to be the physical left regardless of
         page direction (plain logical/RTL-flipping grid columns would otherwise
         swap these in Arabic — see panel.css), while each card's own content still
         reads in the page's natural direction. --}}
    @if ($showRevenue)
        <div class="ls-visual-ltr grid grid-cols-1 lg:grid-cols-2 gap-4 lg:gap-6 mb-6">
            <div>
                @include('admin.partials.chart', ['id' => 'ls-revenue-trend', 'title' => __('app.dashboard.revenue_trend'), 'spec' => $revenueTrendSpec, 'height' => 240])
            </div>
            <div class="ls-card p-4 lg:p-6">
                <div class="flex items-center justify-between">
                    <div class="min-w-0">
                        <p class="text-xs lg:text-sm font-medium text-gray-500">{{ __('app.dashboard.revenue_today') }}</p>
                        <p class="text-2xl lg:text-3xl font-bold text-green-600 mt-1 whitespace-nowrap"><x-ui.money :amount="$revenueToday" /></p>
                        <p class="text-xs text-gray-400 mt-1 truncate">{{ __('app.dashboard.revenue_this_month') }}: <x-ui.money :amount="$revenueThisMonth" /></p>
                        @if ($revenueComparison['changePercent'] !== null)
                            @php $up = $revenueComparison['change'] >= 0; @endphp
                            <p class="text-xs mt-1 font-medium {{ $up ? 'text-green-600' : 'text-red-600' }}">
                                {{ $up ? '▲' : '▼' }} {{ number_format(abs($revenueComparison['changePercent']), 1) }}% {{ __('app.dashboard.vs_previous_period') }}
                            </p>
                            <p class="text-[11px] text-gray-400 mt-1">
                                {{ __('app.dashboard.insight_revenue_change', ['direction' => __($up ? 'app.dashboard.change_up' : 'app.dashboard.change_down'), 'percent' => number_format(abs($revenueComparison['changePercent']), 1), 'period' => $periodLabel]) }}
                            </p>
                        @endif
                    </div>
                    <div class="ls-kpi-icon w-10 h-10 lg:w-12 lg:h-12 bg-green-50 rounded-xl flex items-center justify-center shrink-0">
                        <x-ui.icon name="money" class="text-green-600" />
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ================= Compact KPI row — the remaining essentials =================
         Needs Attention always renders here regardless of feature mix (a
         hotspot-only owner still needs to see subscription alerts), so this
         grid is never conditionally hidden as a whole. --}}
    <div class="ls-kpis-wrap mb-8"><div class="ls-kpis ls-kpis--4">
        @if ($owner->hasFeature('booking'))
            <div class="ls-card p-4 lg:p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs lg:text-sm font-medium text-gray-500">{{ __('app.label.today_bookings') }}</p>
                        <p class="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{{ $todayBookings }}</p>
                    </div>
                    <div class="ls-kpi-icon w-10 h-10 lg:w-12 lg:h-12 bg-orange-50 rounded-xl flex items-center justify-center shrink-0">
                        <x-ui.icon name="calendar" class="text-orange-600" />
                    </div>
                </div>
            </div>
        @endif

        @if ($showWorkspace)
            <div class="ls-card p-4 lg:p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs lg:text-sm font-medium text-gray-500">{{ __('app.dashboard.current_occupancy') }}</p>
                        <p class="text-2xl lg:text-3xl font-bold text-brand-600 mt-1">{{ $occupancy['percent'] }}%</p>
                        <p class="text-xs text-gray-400 mt-1">{{ __('app.dashboard.seats_occupied', ['occupied' => $occupancy['occupied'], 'capacity' => $occupancy['capacity']]) }}</p>
                    </div>
                    <div class="ls-kpi-icon w-10 h-10 lg:w-12 lg:h-12 bg-brand-50 rounded-xl flex items-center justify-center shrink-0">
                        <x-ui.icon name="users" class="text-brand-600" />
                    </div>
                </div>
            </div>

            <div class="ls-card p-4 lg:p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-xs lg:text-sm font-medium text-gray-500">{{ __('app.label.available_rooms') }}</p>
                        <p class="text-2xl lg:text-3xl font-bold text-green-600 mt-1">{{ $availableRoomsNow }}</p>
                    </div>
                    <div class="ls-kpi-icon w-10 h-10 lg:w-12 lg:h-12 bg-green-50 rounded-xl flex items-center justify-center shrink-0">
                        <x-ui.icon name="door" class="text-green-600" />
                    </div>
                </div>
            </div>
        @endif

        <a href="{{ route('notifications.index') }}" class="ls-card is-link p-4 lg:p-6 block">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs lg:text-sm font-medium text-gray-500">{{ __('app.dashboard.needs_attention') }}</p>
                    <p class="text-2xl lg:text-3xl font-bold {{ $needsAttentionCount > 0 ? 'text-red-600' : 'text-gray-900' }} mt-1">{{ $needsAttentionCount }}</p>
                </div>
                <div class="ls-kpi-icon w-10 h-10 lg:w-12 lg:h-12 {{ $needsAttentionCount > 0 ? 'bg-red-50' : 'bg-gray-50' }} rounded-xl flex items-center justify-center shrink-0">
                    <x-ui.icon name="bell" class="{{ $needsAttentionCount > 0 ? 'text-red-600' : 'text-gray-400' }}" />
                </div>
            </div>
        </a>
    </div></div>

    @if (! empty($smartInsights))
        @include('dashboard._smart-insights')
    @endif

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

    {{-- ================= Main analytics — bookings activity, status ================= --}}
    @if ($owner->hasFeature('booking'))
        <div class="mb-6">
            @include('admin.partials.chart', ['id' => 'ls-bookings-activity', 'title' => __('app.dashboard.bookings_activity'), 'spec' => $bookingsActivitySpec, 'height' => 240])
        </div>
    @endif

    @if ($owner->hasFeature('booking') || $showProducts)
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 lg:gap-6 mb-6">
            @if ($owner->hasFeature('booking'))
                <div class="{{ $showProducts ? '' : 'lg:col-span-2' }}">
                    @include('admin.partials.chart', ['id' => 'ls-booking-status', 'title' => __('app.dashboard.booking_status'), 'spec' => $bookingStatusSpec, 'height' => 240])
                </div>
            @endif
            @if ($showProducts)
                <div class="{{ $owner->hasFeature('booking') ? '' : 'lg:col-span-2' }}">
                    @include('admin.partials.chart', ['id' => 'ls-products-services', 'title' => __('app.dashboard.products_services'), 'spec' => $productsServicesSpec, 'height' => 240])
                </div>
            @endif
        </div>
    @endif

    @if ($showProducts)
        @include('dashboard._products-section')
    @endif

    @if ($showWorkspace && $owner->hasFeature('booking') && isset($roomUtilization))
        @php
            $barColors = ['blue' => 'bg-blue-500', 'purple' => 'bg-purple-500', 'green' => 'bg-green-500', 'orange' => 'bg-orange-500', 'gray' => 'bg-gray-400'];
            $maxUtil = max(1, $roomUtilization->max(fn ($r) => $r['utilization_percent'] ?? $r['hours_booked']));
        @endphp
        <div class="mb-6">
            <h3 class="font-semibold text-gray-900 mb-4">{{ __('app.dashboard.room_performance') }}</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @forelse ($roomUtilization as $row)
                    @php $value = $row['utilization_percent'] ?? $row['hours_booked']; @endphp
                    <div class="ls-card p-4 lg:p-6">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <span class="font-medium text-gray-900 truncate">{{ $row['room_name'] }}</span>
                            <span class="text-[11px] text-gray-400 shrink-0">{{ $row['room']->typeLabel() }}</span>
                        </div>
                        <div class="flex items-center justify-between text-xs mb-1 gap-2">
                            <span class="text-gray-500">{{ __('app.dashboard.room_utilization') }}</span>
                            <span class="text-gray-400 shrink-0">
                                {{ $row['utilization_percent'] !== null ? $row['utilization_percent'].'%' : $row['hours_booked'].__('app.ui.unit_h') }}
                            </span>
                        </div>
                        <div class="w-full bg-gray-100 rounded-full h-2 mb-3">
                            <div class="h-2 rounded-full {{ $barColors[$row['room']->typeColor()] ?? 'bg-gray-400' }}" style="width: {{ ($value / $maxUtil) * 100 }}%"></div>
                        </div>
                        @if ($canViewRevenue)
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-semibold text-gray-900 whitespace-nowrap"><x-ui.money :amount="$row['revenue']" /></span>
                                @if ($row['revenue_change_percent'] !== null)
                                    @php $rup = $row['revenue_change_percent'] >= 0; @endphp
                                    <span class="text-[11px] font-medium shrink-0 {{ $rup ? 'text-green-600' : 'text-red-600' }}">
                                        {{ $rup ? '▲' : '▼' }} {{ number_format(abs($row['revenue_change_percent']), 1) }}%
                                    </span>
                                @endif
                            </div>
                            @if ($row['revenue_per_open_hour'] !== null)
                                <p class="text-[11px] text-gray-400 mt-1"><x-ui.money :amount="$row['revenue_per_open_hour']" /> {{ __('app.dashboard.revenue_per_open_hour') }}</p>
                            @endif
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-gray-400">{{ __('app.dashboard.no_room_data') }}</p>
                @endforelse
            </div>
            @if ($roomUtilization->isNotEmpty() && ! $roomUtilization->first()['utilization_percent'])
                <p class="text-[11px] text-gray-400 mt-3">{{ __('app.dashboard.working_hours_not_configured') }}</p>
            @endif
        </div>
    @endif

    {{-- Supporting detail: *when* the business is busiest (day x hour) — a different
         question than the Bookings Activity trend above ("how many, over the period"). --}}
    @if ($owner->hasFeature('booking'))
        @php
            $heatmapDayKeys = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
            $heatmapMax = collect($peakHoursGrid)->flatten()->max() ?? 0;
        @endphp
        <div class="ls-card p-4 lg:p-6 mb-6">
            <h3 class="font-semibold text-gray-900 mb-4">{{ __('app.dashboard.peak_hours_heatmap') }}</h3>
            @if ($heatmapMax === 0)
                <p class="text-sm text-gray-400">{{ __('app.dashboard.no_heatmap_data') }}</p>
            @else
                <div class="ls-heatmap" role="img" aria-label="{{ __('app.dashboard.peak_hours_heatmap') }}">
                    <div class="ls-heatmap-row ls-heatmap-row--header">
                        <div class="ls-heatmap-cell ls-heatmap-cell--label"></div>
                        @foreach ($heatmapDayKeys as $dayKey)
                            <div class="ls-heatmap-cell ls-heatmap-cell--header">{{ mb_substr(__('app.day.'.$dayKey), 0, 3) }}</div>
                        @endforeach
                    </div>
                    @for ($hour = 0; $hour < 24; $hour++)
                        <div class="ls-heatmap-row">
                            <div class="ls-heatmap-cell ls-heatmap-cell--label">{{ sprintf('%02d', $hour) }}</div>
                            @foreach ($heatmapDayKeys as $dow => $dayKey)
                                @php
                                    $count = $peakHoursGrid[$dow][$hour] ?? 0;
                                    $intensity = $heatmapMax > 0 ? round(($count / $heatmapMax) * 100) : 0;
                                @endphp
                                <div
                                    class="ls-heatmap-cell"
                                    @if ($count > 0) style="background: color-mix(in srgb, var(--color-chart) {{ $intensity }}%, transparent);" @endif
                                    title="{{ __('app.day.'.$dayKey) }} {{ sprintf('%02d:00', $hour) }} — {{ $count }}"
                                ></div>
                            @endforeach
                        </div>
                    @endfor
                </div>
            @endif
        </div>
    @endif

    @if ($owner->hasFeature('hotspot'))
    <div class="ls-strip">
        <div>
            <span class="ls-strip-label">{{ __('app.label.total_users') }}</span>
            <span class="ls-strip-value">{{ $totalUsers }}</span>
        </div>
        <div>
            <span class="ls-strip-label">{{ __('app.label.active_users') }}</span>
            <span class="ls-strip-value">{{ $activeUsers }}</span>
        </div>
        <div>
            <span class="ls-strip-label">{{ __('app.label.online_now') }}</span>
            <span class="ls-strip-value is-brand">{{ $activeSessions }}</span>
            <span class="ls-strip-meta">{{ __('app.dashboard.live_from_mikrotik') }}</span>
        </div>
        <div>
            <span class="ls-strip-label">{{ __('app.section.speed_profiles') }}</span>
            <span class="ls-strip-value">{{ $totalProfiles }}</span>
        </div>
        <div>
            <span class="ls-strip-label">{{ __('app.dashboard.router_status') }}</span>
            <span class="ls-strip-value {{ $routerConnected ? 'is-brand' : 'is-warn' }}">
                {{ $routerConnected ? __('app.dashboard.router_connected') : __('app.dashboard.router_unreachable') }}
            </span>
        </div>
    </div>
    @endif

    {{-- ================= Bottom — schedules, details, alerts ================= --}}
    @if ($owner->hasFeature('booking') || isset($newCustomers))
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 lg:gap-6 mb-6">
        @if ($owner->hasFeature('booking'))
            <div class="ls-card p-4 lg:p-6 lg:col-span-2">
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
            <div class="ls-card p-4 lg:p-6">
                <p class="text-xs lg:text-sm font-medium text-gray-500">{{ __('app.dashboard.new_customers') }}</p>
                <p class="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{{ $newCustomers }}</p>
                <p class="text-[11px] text-gray-400 mt-1">{{ __('app.dashboard.period_'.$periodKey) }}</p>

                <div class="grid grid-cols-2 gap-3 mt-4 pt-4 border-t border-gray-50">
                    <div>
                        <p class="text-[11px] text-gray-500">{{ __('app.dashboard.returning_customers') }}</p>
                        <p class="text-lg font-semibold text-gray-900 mt-0.5">
                            {{ $returningCustomerRate ? $returningCustomerRate['percent'].'%' : __('app.dashboard.not_enough_data') }}
                        </p>
                    </div>
                    <div>
                        <p class="text-[11px] text-gray-500">{{ __('app.dashboard.avg_spend_per_customer') }}</p>
                        <p class="text-lg font-semibold text-gray-900 mt-0.5 whitespace-nowrap">
                            @if ($averageSpendPerCustomer !== null)
                                <x-ui.money :amount="$averageSpendPerCustomer" />
                            @else
                                {{ __('app.dashboard.not_enough_data') }}
                            @endif
                        </p>
                    </div>
                </div>
            </div>
        @endif
    </div>
    @endif

@endsection
