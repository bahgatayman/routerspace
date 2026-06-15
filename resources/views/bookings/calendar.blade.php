@extends('layouts.app')

@section('page-title', 'Bookings Calendar')

@section('content')
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Bookings Calendar</h1>
        <div class="flex gap-2">
            <a href="/bookings" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition text-sm font-medium">
                List View
            </a>
            <a href="/bookings/create" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
                + New Booking
            </a>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-6">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
            <a href="/bookings/calendar?date={{ $carbon->copy()->subMonth()->format('Y-m-d') }}"
               class="text-sm text-gray-600 hover:text-gray-900 font-medium">&larr; Prev</a>
            <h2 class="text-lg font-semibold text-gray-900">{{ $carbon->format('F Y') }}</h2>
            <a href="/bookings/calendar?date={{ $carbon->copy()->addMonth()->format('Y-m-d') }}"
               class="text-sm text-gray-600 hover:text-gray-900 font-medium">Next &rarr;</a>
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
                <a href="/bookings/calendar?date={{ $dateStr }}"
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
            Bookings for {{ \Carbon\Carbon::parse($selectedDate)->format('l, M d, Y') }}
        </h3>

        @if (isset($bookings[$selectedDate]) && $bookings[$selectedDate]->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 bg-gray-50 border-b border-gray-100">
                            <th class="px-4 py-3 font-medium">Time</th>
                            <th class="px-4 py-3 font-medium">Customer</th>
                            <th class="px-4 py-3 font-medium">Room</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Total</th>
                            <th class="px-4 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bookings[$selectedDate]->sortBy('start_time') as $booking)
                            <tr class="border-b border-gray-50">
                                <td class="px-4 py-3 whitespace-nowrap font-medium">{{ $booking->timeRange() }}</td>
                                <td class="px-4 py-3">{{ $booking->customer->name }}</td>
                                <td class="px-4 py-3">{{ $booking->room->workspace?->name }} / {{ $booking->room->name }}</td>
                                <td class="px-4 py-3">
                                    @php
                                        $colors = ['yellow' => 'bg-yellow-100 text-yellow-800', 'blue' => 'bg-blue-100 text-blue-800', 'green' => 'bg-green-100 text-green-800', 'red' => 'bg-red-100 text-red-800'];
                                    @endphp
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $colors[$booking->statusColor()] ?? 'bg-gray-100 text-gray-800' }}">
                                        {{ $booking->statusLabel() }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 font-medium">{{ number_format($booking->total_price, 2) }} EGP</td>
                                <td class="px-4 py-3">
                                    <a href="/bookings/{{ $booking->id }}" class="text-blue-600 hover:underline text-xs font-medium">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="text-center py-8">
                <p class="text-gray-400 text-sm">No bookings on this day.</p>
                <a href="/bookings/create" class="text-blue-600 hover:underline text-sm font-medium mt-2 inline-block">+ Add Booking</a>
            </div>
        @endif
    </div>
@endsection
