@extends('layouts.app')

@section('page-title', __('app.booking.calendar_view'))

@php
    $baseQuery = fn (array $overrides = []) => http_build_query(array_filter(array_merge(
        ['view' => $view, 'date' => $date, 'room_id' => $roomId, 'workspace_id' => $workspaceId],
        $overrides
    ), fn ($v) => $v !== null && $v !== ''));
@endphp

@section('content')
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <h1 class="text-2xl font-bold text-gray-900">{{ __('app.booking.calendar_view') }}</h1>
        <div class="flex gap-2">
            <a href="/bookings/availability" data-ls-open="availability-modal" aria-haspopup="dialog" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition text-sm font-medium">
                {{ __('app.booking.quick_availability') }}
            </a>
            <a href="/bookings" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition text-sm font-medium">
                {{ __('app.btn.list_view') }}
            </a>
            <a href="/bookings/create" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
                + {{ __('app.booking.new_booking') }}
            </a>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="inline-flex rounded-lg border border-gray-200 overflow-hidden">
                @foreach (['day' => __('app.booking.day_view'), 'week' => __('app.booking.week_view'), 'month' => __('app.booking.month_view')] as $v => $label)
                    <a href="/bookings/calendar?{{ $baseQuery(['view' => $v]) }}"
                       class="px-4 py-2 text-sm font-medium transition {{ $view === $v ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            <form method="GET" action="/bookings/calendar" class="flex flex-wrap items-end gap-3">
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
                @if ($roomId || $workspaceId)
                    <a href="/bookings/calendar?{{ $baseQuery(['room_id' => null, 'workspace_id' => null]) }}" class="text-sm text-gray-500 hover:text-gray-700 py-2">{{ __('app.btn.clear_filters') }}</a>
                @endif
            </form>
        </div>
    </div>

    @include('partials.calendar-legend', ['showRoomAvailability' => $view === 'day' || $view === 'week'])

    @if ($view === 'day')
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-6">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <a href="/bookings/calendar?{{ $baseQuery(['date' => $carbon->copy()->subDay()->format('Y-m-d')]) }}"
                   class="text-sm text-gray-600 hover:text-gray-900 font-medium">&larr; {{ __('app.common.back') }}</a>
                <div class="flex items-center gap-3">
                    <h2 class="text-lg font-semibold text-gray-900">{{ $carbon->format('l, M d, Y') }}</h2>
                    <a href="/bookings/calendar?{{ $baseQuery(['date' => now()->format('Y-m-d')]) }}"
                       class="text-xs text-blue-600 hover:underline font-medium">{{ __('app.common.today') }}</a>
                </div>
                <a href="/bookings/calendar?{{ $baseQuery(['date' => $carbon->copy()->addDay()->format('Y-m-d')]) }}"
                   class="text-sm text-gray-600 hover:text-gray-900 font-medium">{{ __('app.common.back') }} &rarr;</a>
            </div>
            <div class="p-4">
                @include('partials.booking-day-rooms', ['dayRooms' => $dayRooms])
            </div>
        </div>
    @elseif ($view === 'week')
        @php
            $weekStart = $carbon->copy()->startOfWeek(\Carbon\Carbon::MONDAY);
            $weekEnd = $weekStart->copy()->addDays(6);
        @endphp
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-6">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <a href="/bookings/calendar?{{ $baseQuery(['date' => $weekStart->copy()->subWeek()->format('Y-m-d')]) }}"
                   class="text-sm text-gray-600 hover:text-gray-900 font-medium">&larr; {{ __('app.common.back') }}</a>
                <h2 class="text-lg font-semibold text-gray-900">{{ $weekStart->format('M d') }} – {{ $weekEnd->format('M d, Y') }}</h2>
                <a href="/bookings/calendar?{{ $baseQuery(['date' => $weekStart->copy()->addWeek()->format('Y-m-d')]) }}"
                   class="text-sm text-gray-600 hover:text-gray-900 font-medium">{{ __('app.common.back') }} &rarr;</a>
            </div>
        </div>

        <div class="space-y-4">
            @foreach ($days as $day)
                @php $dayStr = $day['date']->format('Y-m-d'); @endphp
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <a href="/bookings/calendar?{{ $baseQuery(['view' => 'day', 'date' => $dayStr]) }}"
                       class="flex items-center justify-between px-6 py-3 border-b border-gray-100 hover:bg-gray-50 transition">
                        <span class="font-semibold text-gray-900">
                            {{ $day['date']->format('l, M d') }}
                            @if ($dayStr === now()->format('Y-m-d'))
                                <span class="ml-2 text-xs text-blue-600 font-medium">&middot; {{ __('app.common.today') }}</span>
                            @endif
                        </span>
                        <span class="text-xs text-gray-400">{{ __('app.common.view') }} {{ __('app.booking.day_view') }} &rarr;</span>
                    </a>
                    <div class="p-4">
                        @include('partials.booking-day-rooms', ['dayRooms' => $day['rooms']])
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-6">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <a href="/bookings/calendar?{{ $baseQuery(['date' => $carbon->copy()->subMonth()->format('Y-m-d')]) }}"
                   class="text-sm text-gray-600 hover:text-gray-900 font-medium">&larr; {{ __('app.common.back') }}</a>
                <h2 class="text-lg font-semibold text-gray-900">{{ $carbon->format('F Y') }}</h2>
                <a href="/bookings/calendar?{{ $baseQuery(['date' => $carbon->copy()->addMonth()->format('Y-m-d')]) }}"
                   class="text-sm text-gray-600 hover:text-gray-900 font-medium">{{ __('app.common.back') }} &rarr;</a>
            </div>

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
