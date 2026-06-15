@extends('layouts.admin')

@section('page-title', 'Bookings')

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">All Bookings</h1>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        @if ($bookings->isEmpty())
            <div class="p-12 text-center">
                <p class="text-gray-400 text-sm">No bookings found.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 bg-gray-50 border-b border-gray-100">
                            <th class="px-4 py-3 font-medium">Date</th>
                            <th class="px-4 py-3 font-medium">Customer</th>
                            <th class="px-4 py-3 font-medium">Room</th>
                            <th class="px-4 py-3 font-medium">Workspace</th>
                            <th class="px-4 py-3 font-medium">Owner</th>
                            <th class="px-4 py-3 font-medium">Hours</th>
                            <th class="px-4 py-3 font-medium">Total</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bookings as $booking)
                            <tr class="border-b border-gray-50">
                                <td class="px-4 py-3 font-medium whitespace-nowrap">{{ $booking->booking_date->format('M d, Y') }}</td>
                                <td class="px-4 py-3">{{ $booking->customer->name }}</td>
                                <td class="px-4 py-3">{{ $booking->room->name }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $booking->room->workspace?->name ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <a href="/admin/owners/{{ $booking->owner->id }}" class="text-blue-600 hover:underline">
                                        {{ $booking->owner->business_name }}
                                    </a>
                                </td>
                                <td class="px-4 py-3">{{ $booking->total_hours }}</td>
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
                                    <a href="/admin/bookings/{{ $booking->id }}" class="text-blue-600 hover:underline text-xs font-medium">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3 border-t border-gray-100">
                {{ $bookings->links() }}
            </div>
        @endif
    </div>
@endsection
