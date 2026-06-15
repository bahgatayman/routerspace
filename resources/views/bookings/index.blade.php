@extends('layouts.app')

@section('page-title', 'Bookings')

@section('content')
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Bookings</h1>
        <div class="flex gap-2">
            <a href="/bookings/calendar" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition text-sm font-medium">
                Calendar View
            </a>
            <a href="/bookings/create" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
                + New Booking
            </a>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-6">
        <form method="GET" action="/bookings" class="flex flex-wrap gap-3 items-end">
            <div>
                <label class="block text-xs text-gray-500 mb-1">Status</label>
                <select name="status" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">All</option>
                    <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="confirmed" {{ $status === 'confirmed' ? 'selected' : '' }}>Confirmed</option>
                    <option value="completed" {{ $status === 'completed' ? 'selected' : '' }}>Completed</option>
                    <option value="cancelled" {{ $status === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Date</label>
                <input type="date" name="date" value="{{ $date }}"
                       class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">Room</label>
                <select name="room_id" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">All Rooms</option>
                    @foreach($rooms as $room)
                        <option value="{{ $room->id }}" {{ $roomId == $room->id ? 'selected' : '' }}>
                            {{ $room->workspace?->name }} - {{ $room->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="bg-gray-800 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition text-sm font-medium">Filter</button>
            <a href="/bookings" class="text-sm text-gray-500 hover:text-gray-700 py-2">Clear Filters</a>
        </form>
    </div>

    @php
        $counts = [
            'total' => $bookings->total(),
        ];
    @endphp

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        @if ($bookings->isEmpty())
            <div class="p-12 text-center">
                <p class="text-gray-400 text-sm">No bookings found.</p>
                <a href="/bookings/create" class="text-blue-600 hover:underline text-sm font-medium mt-2 inline-block">Create your first booking</a>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 bg-gray-50 border-b border-gray-100">
                            <th class="px-4 py-3 font-medium">Date</th>
                            <th class="px-4 py-3 font-medium">Time</th>
                            <th class="px-4 py-3 font-medium">Customer</th>
                            <th class="px-4 py-3 font-medium">Room</th>
                            <th class="px-4 py-3 font-medium">Hours</th>
                            <th class="px-4 py-3 font-medium">Total</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bookings as $booking)
                            <tr class="border-b border-gray-50 {{ $booking->status === 'cancelled' ? 'text-gray-400 line-through' : 'text-gray-900' }}">
                                <td class="px-4 py-3 font-medium">{{ $booking->booking_date->format('M d, Y') }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $booking->timeRange() }}</td>
                                <td class="px-4 py-3">
                                    <a href="/customers/{{ $booking->customer->id }}" class="text-blue-600 hover:underline font-medium">
                                        {{ $booking->customer->name }}
                                    </a>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-gray-600">{{ $booking->room->workspace?->name }} /</span>
                                    {{ $booking->room->name }}
                                </td>
                                <td class="px-4 py-3">{{ $booking->total_hours }} hrs</td>
                                <td class="px-4 py-3 font-medium">{{ number_format($booking->total_price, 2) }} EGP</td>
                                <td class="px-4 py-3">
                                    @php
                                        $colors = ['yellow' => 'bg-yellow-100 text-yellow-800', 'blue' => 'bg-blue-100 text-blue-800', 'green' => 'bg-green-100 text-green-800', 'red' => 'bg-red-100 text-red-800'];
                                    @endphp
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $colors[$booking->statusColor()] ?? 'bg-gray-100 text-gray-800' }}">
                                        {{ $booking->statusLabel() }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex gap-2">
                                        <a href="/bookings/{{ $booking->id }}" class="text-blue-600 hover:underline text-xs font-medium">View</a>
                                        @if (in_array($booking->status, ['pending', 'confirmed']))
                                            <a href="/bookings/{{ $booking->id }}/edit" class="text-gray-600 hover:underline text-xs font-medium">Edit</a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-gray-100">
                {{ $bookings->appends(request()->query())->links() }}
            </div>
        @endif
    </div>
@endsection
