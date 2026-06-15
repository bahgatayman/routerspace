@extends('layouts.app')

@section('page-title', $customer->name)

@section('content')
    <div class="mb-6">
        <a href="/customers" class="text-sm text-gray-500 hover:text-gray-700">&larr; Back to Customers</a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-1">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h1 class="text-xl font-bold text-gray-900">{{ $customer->name }}</h1>

                <dl class="mt-4 space-y-3 text-sm">
                    @if ($customer->phone)
                        <div>
                            <dt class="text-gray-500">Phone</dt>
                            <dd class="text-gray-900 font-medium mt-1">{{ $customer->phone }}</dd>
                        </div>
                    @endif
                    @if ($customer->email)
                        <div>
                            <dt class="text-gray-500">Email</dt>
                            <dd class="text-gray-900 font-medium mt-1">{{ $customer->email }}</dd>
                        </div>
                    @endif
                    @if ($customer->notes)
                        <div>
                            <dt class="text-gray-500">Notes</dt>
                            <dd class="text-gray-600 mt-1">{{ $customer->notes }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-gray-500">Customer Since</dt>
                        <dd class="text-gray-900 font-medium mt-1">{{ $customer->created_at->format('M d, Y') }}</dd>
                    </div>
                </dl>

                <div class="mt-6 flex gap-2">
                    <a href="/customers/{{ $customer->id }}/edit" class="flex-1 text-center bg-gray-800 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition text-sm font-medium">
                        Edit
                    </a>
                    <a href="/bookings/create?customer_id={{ $customer->id }}" class="flex-1 text-center bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium">
                        New Booking
                    </a>
                </div>
            </div>
        </div>

        <div class="lg:col-span-2">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h2 class="text-lg font-semibold text-gray-900 mb-4">Recent Bookings</h2>

                @if ($bookings->isEmpty())
                    <p class="text-sm text-gray-400">No bookings yet.</p>
                    <a href="/bookings/create?customer_id={{ $customer->id }}" class="text-blue-600 hover:underline text-sm font-medium mt-2 inline-block">
                        Create a booking for {{ $customer->name }}
                    </a>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-500 bg-gray-50 border-b border-gray-100">
                                    <th class="px-4 py-3 font-medium">Date</th>
                                    <th class="px-4 py-3 font-medium">Time</th>
                                    <th class="px-4 py-3 font-medium">Room</th>
                                    <th class="px-4 py-3 font-medium">Total</th>
                                    <th class="px-4 py-3 font-medium">Status</th>
                                    <th class="px-4 py-3 font-medium"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($bookings as $booking)
                                    <tr class="border-b border-gray-50">
                                        <td class="px-4 py-3 font-medium">{{ $booking->booking_date->format('M d, Y') }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap">{{ $booking->timeRange() }}</td>
                                        <td class="px-4 py-3">{{ $booking->room->workspace?->name }} / {{ $booking->room->name }}</td>
                                        <td class="px-4 py-3 font-medium">{{ number_format($booking->total_price, 2) }} EGP</td>
                                        <td class="px-4 py-3">
                                            @php $colors = ['yellow' => 'bg-yellow-100 text-yellow-800', 'blue' => 'bg-blue-100 text-blue-800', 'green' => 'bg-green-100 text-green-800', 'red' => 'bg-red-100 text-red-800']; @endphp
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $colors[$booking->statusColor()] ?? 'bg-gray-100 text-gray-800' }}">
                                                {{ $booking->statusLabel() }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <a href="/bookings/{{ $booking->id }}" class="text-blue-600 hover:underline text-xs font-medium">View</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
