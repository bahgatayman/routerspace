@extends('layouts.app')

@section('page-title', __('app.booking.calendar_view'))

@php
    $baseQuery = fn (array $overrides = []) => http_build_query(array_filter(array_merge(
        ['view' => $view, 'date' => $date, 'room_id' => $roomId, 'workspace_id' => $workspaceId, 'status' => $status],
        $overrides
    ), fn ($v) => $v !== null && $v !== ''));

    // Prev/next/range-label depend on which grain is active — computed once
    // here so the toolbar below doesn't repeat this per view.
    $weekStart = $carbon->copy()->startOfWeek(\Carbon\Carbon::MONDAY);
    $weekEnd = $weekStart->copy()->addDays(6);
    [$prevDate, $nextDate, $rangeLabel] = match ($view) {
        'week' => [$weekStart->copy()->subWeek()->format('Y-m-d'), $weekStart->copy()->addWeek()->format('Y-m-d'), $weekStart->format('M d').' – '.$weekEnd->format('M d, Y')],
        'month' => [$carbon->copy()->subMonth()->format('Y-m-d'), $carbon->copy()->addMonth()->format('Y-m-d'), $carbon->format('F Y')],
        default => [$carbon->copy()->subDay()->format('Y-m-d'), $carbon->copy()->addDay()->format('Y-m-d'), $carbon->format('l, M d, Y')],
    };
@endphp

@section('content')
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">{{ __('app.booking.calendar_view') }}</h1>
            <p class="text-sm text-gray-500 mt-0.5">{{ __('app.booking.calendar_subtitle') }}</p>
        </div>
        <div class="flex gap-2">
            <a href="/bookings/availability" data-ls-open="availability-modal" aria-haspopup="dialog" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition text-sm font-medium focus:outline-none focus:ring-2 focus:ring-blue-500">
                {{ __('app.booking.quick_availability') }}
            </a>
            <a href="/bookings" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition text-sm font-medium focus:outline-none focus:ring-2 focus:ring-blue-500">
                {{ __('app.btn.list_view') }}
            </a>
            <a href="/bookings/create" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                + {{ __('app.booking.new_booking') }}
            </a>
        </div>
    </div>

    {{--
        Daily summary — always "today", independent of whichever date the
        grid below is browsing. Icon + caption only (no trend %, no
        sparkline): a trend would need a real previous-period comparison
        this page doesn't compute, and a sparkline with no real time-series
        behind it would just be decoration pretending to be data.
    --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 px-4 py-3 flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </div>
            <div class="min-w-0">
                <p class="text-xs text-gray-500">{{ __('app.booking.daily_summary.today_bookings') }}</p>
                <p class="text-xl font-bold text-gray-900 leading-tight">{{ $summary['todayBookings'] }}</p>
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 px-4 py-3 flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-teal-50 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-teal-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            <div class="min-w-0">
                <p class="text-xs text-gray-500">{{ __('app.booking.daily_summary.active_sessions') }}</p>
                <p class="text-xl font-bold leading-tight {{ $summary['activeSessions'] > 0 ? 'text-teal-600' : 'text-gray-900' }}">{{ $summary['activeSessions'] }}</p>
                @if ($summary['activeSessions'] > 0)
                    <p class="text-[11px] text-teal-600 font-medium flex items-center gap-1 mt-0.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-teal-500"></span>{{ __('app.booking.daily_summary.live_now') }}
                    </p>
                @endif
            </div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 px-4 py-3 flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-yellow-50 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-yellow-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="min-w-0">
                <p class="text-xs text-gray-500">{{ __('app.booking.daily_summary.pending_requests') }}</p>
                <p class="text-xl font-bold leading-tight {{ $summary['pendingRequests'] > 0 ? 'text-yellow-600' : 'text-gray-900' }}">{{ $summary['pendingRequests'] }}</p>
                @if ($summary['pendingRequests'] > 0)
                    <p class="text-[11px] text-yellow-600 font-medium mt-0.5">{{ __('app.booking.daily_summary.needs_review') }}</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Unified toolbar: view switcher + date navigation + filters, all in one place. --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="inline-flex rounded-lg border border-gray-200 overflow-hidden shrink-0">
                @foreach (['day' => __('app.booking.day_view'), 'week' => __('app.booking.week_view'), 'month' => __('app.booking.month_view')] as $v => $label)
                    <a href="/bookings/calendar?{{ $baseQuery(['view' => $v]) }}"
                       class="px-4 py-2 text-sm font-medium transition {{ $view === $v ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            <div class="flex items-center gap-3">
                <a href="/bookings/calendar?{{ $baseQuery(['date' => $prevDate]) }}" aria-label="{{ __('app.common.previous') }}"
                   class="p-2 rounded-lg text-gray-500 hover:text-gray-900 hover:bg-gray-50 transition focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </a>
                <span class="text-sm font-semibold text-gray-900 min-w-[11rem] text-center">{{ $rangeLabel }}</span>
                <a href="/bookings/calendar?{{ $baseQuery(['date' => $nextDate]) }}" aria-label="{{ __('app.common.next') }}"
                   class="p-2 rounded-lg text-gray-500 hover:text-gray-900 hover:bg-gray-50 transition focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
                <a href="/bookings/calendar?{{ $baseQuery(['date' => now()->format('Y-m-d')]) }}"
                   class="text-xs text-blue-600 hover:underline font-medium">{{ __('app.common.today') }}</a>
            </div>

            @if ($workingHoursLabel)
                <div class="flex items-center gap-1.5 text-xs text-gray-500 ms-auto">
                    <svg class="w-3.5 h-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>{{ __('app.settings.working_hours.title') }}:</span>
                    <span class="font-medium text-gray-700">{{ $workingHoursLabel }}</span>
                </div>
            @endif
        </div>

        <form method="GET" action="/bookings/calendar" class="flex flex-wrap items-end gap-3 pt-3 border-t border-gray-100">
            <input type="hidden" name="view" value="{{ $view }}">
            <input type="hidden" name="date" value="{{ $date }}">
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('app.session.workspace') }}</label>
                <select name="workspace_id" onchange="this.form.submit()" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">{{ __('app.common.all') }}</option>
                    @foreach ($workspaces as $ws)
                        <option value="{{ $ws->id }}" {{ (string) $workspaceId === (string) $ws->id ? 'selected' : '' }}>{{ $ws->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('app.booking.room') }}</label>
                <select name="room_id" onchange="this.form.submit()" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">{{ __('app.common.all') }}</option>
                    @foreach ($allRooms as $r)
                        <option value="{{ $r->id }}" {{ (string) $roomId === (string) $r->id ? 'selected' : '' }}>{{ $r->workspace?->name }} - {{ $r->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('app.table.th.status') }}</label>
                <select name="status" onchange="this.form.submit()" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">{{ __('app.common.all') }}</option>
                    @foreach (\App\Models\Booking::STATUSES as $s)
                        @php $statusPreview = new \App\Models\Booking(['status' => $s]); @endphp
                        <option value="{{ $s }}" {{ $status === $s ? 'selected' : '' }}>{{ $statusPreview->statusLabel() }}</option>
                    @endforeach
                </select>
            </div>
            @if ($roomId || $workspaceId || $status)
                <a href="/bookings/calendar?{{ $baseQuery(['room_id' => null, 'workspace_id' => null, 'status' => null]) }}" class="text-sm text-gray-500 hover:text-gray-700 py-2">{{ __('app.btn.clear_filters') }}</a>
            @endif
        </form>
    </div>

    @include('partials.calendar-legend', ['showRoomAvailability' => $view === 'day' || $view === 'week'])

    @if ($view === 'day')
        <div class="mb-6">
            @include('partials.booking-day-rooms', ['dayRooms' => $dayRooms, 'isDayView' => true])
        </div>
    @elseif ($view === 'week')
        <div class="space-y-3">
            @foreach ($days as $day)
                @php $dayStr = $day['date']->format('Y-m-d'); @endphp
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <a href="/bookings/calendar?{{ $baseQuery(['view' => 'day', 'date' => $dayStr]) }}"
                       class="flex items-center justify-between px-4 py-2.5 border-b border-gray-100 hover:bg-gray-50 transition">
                        <span class="font-semibold text-gray-900 text-sm">
                            {{ $day['date']->format('l, M d') }}
                            @if ($dayStr === now()->format('Y-m-d'))
                                <span class="ms-2 text-xs text-blue-600 font-medium">&middot; {{ __('app.common.today') }}</span>
                            @endif
                        </span>
                        <span class="text-xs text-gray-400">{{ __('app.common.view') }} {{ __('app.booking.day_view') }} &rarr;</span>
                    </a>
                    <div class="p-2">
                        @include('partials.booking-day-rooms', ['dayRooms' => $day['rooms'], 'isDayView' => false])
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-6">
            @php
                $startOfMonth = $carbon->copy()->startOfMonth();
                $endOfMonth   = $carbon->copy()->endOfMonth();
                $startOfGrid  = $startOfMonth->copy()->startOfWeek(\Carbon\Carbon::MONDAY);
                $endOfGrid    = $endOfMonth->copy()->endOfWeek(\Carbon\Carbon::SUNDAY);
                $today        = now()->format('Y-m-d');
                $selectedDate = $date;
            @endphp

            <div class="grid grid-cols-7 text-center">
                @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day)
                    <div class="py-2 text-xs font-medium text-gray-500 bg-gray-50 border-b border-gray-100">{{ $day }}</div>
                @endforeach

                @for ($d = $startOfGrid->copy(); $d->lte($endOfGrid); $d->addDay())
                    @php
                        $dateStr   = $d->format('Y-m-d');
                        $dayHasBookings = isset($bookings[$dateStr]);
                        $isToday   = $dateStr === $today;
                        $isSelected = $dateStr === $selectedDate;
                        $isCurrentMonth = $d->month === $carbon->month;
                        $dayCount  = $dayHasBookings ? $bookings[$dateStr]->count() : 0;
                    @endphp
                    <a href="/bookings/calendar?{{ $baseQuery(['view' => 'day', 'date' => $dateStr]) }}"
                       class="relative p-2 sm:p-3 text-sm border-b border-r border-gray-50 transition
                              {{ !$isCurrentMonth ? 'text-gray-300' : 'text-gray-700 hover:bg-blue-50' }}
                              {{ $isToday ? 'bg-blue-50' : '' }}
                              {{ $isSelected ? 'ring-2 ring-blue-500 bg-blue-50' : '' }}">
                        <span class="font-medium">{{ $d->format('j') }}</span>
                        @if ($dayHasBookings)
                            <span class="block mt-1 mx-auto w-5 h-5 rounded-full text-[10px] font-bold
                                {{ $dayCount > 3 ? 'bg-red-500 text-white' : 'bg-blue-100 text-blue-700' }}">
                                {{ $dayCount }}
                            </span>
                        @endif
                    </a>
                @endfor
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">
                {{ __('app.booking.bookings') }} {{ \Carbon\Carbon::parse($selectedDate)->format('l, M d, Y') }}
            </h3>

            @if (isset($bookings[$selectedDate]) && $bookings[$selectedDate]->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 bg-gray-50 border-b border-gray-100">
                                <th class="px-4 py-3 font-medium">{{ __('app.table.th.time') }}</th>
                                <th class="px-4 py-3 font-medium">{{ __('app.table.th.user') }}</th>
                                <th class="px-4 py-3 font-medium">{{ __('app.table.th.room') }}</th>
                                <th class="px-4 py-3 font-medium">{{ __('app.table.th.status') }}</th>
                                <th class="px-4 py-3 font-medium">{{ __('app.table.th.total') }}</th>
                                <th class="px-4 py-3 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($bookings[$selectedDate]->sortBy('start_time') as $booking)
                                <tr class="border-b border-gray-50">
                                    <td class="px-4 py-3 whitespace-nowrap font-medium">{{ $booking->timeRange() }}</td>
                                    <td class="px-4 py-3">
                                        {{ $booking->hotspotUser->name }}
                                        @if ($booking->party_size > 1)
                                            <span class="text-gray-400 text-xs">&middot; {{ __('app.session.party_of', ['count' => $booking->party_size]) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">{{ $booking->room->workspace?->name }} / {{ $booking->room->name }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $booking->statusBadgeClass() }}">
                                            {{ $booking->statusLabel() }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 font-medium">ج.م {{ number_format($booking->total_price, 2) }}</td>
                                    <td class="px-4 py-3">
                                        <a href="/bookings/{{ $booking->id }}" class="text-blue-600 hover:underline text-xs font-medium">{{ __('app.common.view') }}</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-center py-8">
                    <p class="text-gray-400 text-sm">{{ __('app.empty.no_bookings_on_day') }}</p>
                    <a href="/bookings/create" class="text-blue-600 hover:underline text-sm font-medium mt-2 inline-block">+ {{ __('app.booking.new_booking') }}</a>
                </div>
            @endif
        </div>
    @endif

    @include('bookings._availability-modal')
@endsection
