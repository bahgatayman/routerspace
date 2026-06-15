@extends('layouts.app')

@section('page-title', 'Booking #' . str_pad($booking->id, 4, '0', STR_PAD_LEFT))

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <div>
            <a href="/bookings" class="text-sm text-gray-500 hover:text-gray-700">&larr; Back to Bookings</a>
        </div>
        @if (in_array($booking->status, ['pending', 'confirmed']))
            <a href="/bookings/{{ $booking->id }}/edit" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition text-sm font-medium">
                Edit Booking
            </a>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">Booking #{{ str_pad($booking->id, 4, '0', STR_PAD_LEFT) }}</h1>
                        <p class="text-sm text-gray-500 mt-1">Created {{ $booking->created_at->format('M d, Y h:i A') }}</p>
                    </div>
                    @php
                        $colors = ['yellow' => 'bg-yellow-100 text-yellow-800', 'blue' => 'bg-blue-100 text-blue-800', 'green' => 'bg-green-100 text-green-800', 'red' => 'bg-red-100 text-red-800'];
                    @endphp
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $colors[$booking->statusColor()] ?? 'bg-gray-100 text-gray-800' }}">
                        {{ $booking->statusLabel() }}
                    </span>
                </div>

                <dl class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-gray-500">Room</dt>
                        <dd class="text-gray-900 font-medium mt-1">
                            <a href="/workspaces/{{ $booking->room->workspace->id }}" class="text-blue-600 hover:underline">
                                {{ $booking->room->workspace?->name }}
                            </a>
                            / {{ $booking->room->name }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Customer</dt>
                        <dd class="font-medium mt-1">
                            <a href="/customers/{{ $booking->customer->id }}" class="text-blue-600 hover:underline">{{ $booking->customer->name }}</a>
                            @if ($booking->customer->phone)
                                <span class="text-gray-500"> &middot; {{ $booking->customer->phone }}</span>
                            @endif
                            @if ($booking->customer->email)
                                <span class="text-gray-500 block">{{ $booking->customer->email }}</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Date</dt>
                        <dd class="text-gray-900 font-medium mt-1">{{ $booking->booking_date->format('l, M d, Y') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Time</dt>
                        <dd class="text-gray-900 font-medium mt-1">{{ $booking->timeRange() }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Duration</dt>
                        <dd class="text-gray-900 font-medium mt-1">{{ $booking->total_hours }} hours</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Price per Hour</dt>
                        <dd class="text-gray-900 font-medium mt-1">{{ number_format($booking->price_per_hour, 2) }} EGP</dd>
                    </div>
                    <div class="md:col-span-2">
                        <dt class="text-gray-500">Total Price</dt>
                        <dd class="text-2xl font-bold text-blue-600 mt-1">{{ number_format($booking->total_price, 2) }} EGP</dd>
                    </div>
                </dl>

                @if ($booking->notes)
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <dt class="text-sm text-gray-500">Notes</dt>
                        <dd class="text-sm text-gray-900 mt-1">{{ $booking->notes }}</dd>
                    </div>
                @endif
            </div>
        </div>

        <div class="space-y-4">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="font-semibold text-gray-900 mb-4">Actions</h3>

                @if ($booking->status === 'pending')
                    <form method="POST" action="/bookings/{{ $booking->id }}/status" class="space-y-2">
                        @csrf
                        <input type="hidden" name="status" value="confirmed">
                        <button type="submit" class="w-full bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium">
                            Confirm Booking
                        </button>
                    </form>
                    <form method="POST" action="/bookings/{{ $booking->id }}/status">
                        @csrf
                        <input type="hidden" name="status" value="cancelled">
                        <button type="submit" class="w-full bg-red-100 text-red-700 px-4 py-2 rounded-lg hover:bg-red-200 transition text-sm font-medium"
                                onclick="return confirm('Cancel this booking?')">
                            Cancel Booking
                        </button>
                    </form>
                @elseif ($booking->status === 'confirmed')
                    <form method="POST" action="/bookings/{{ $booking->id }}/status" class="space-y-2">
                        @csrf
                        <input type="hidden" name="status" value="completed">
                        <button type="submit" class="w-full bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition text-sm font-medium">
                            Mark Completed
                        </button>
                    </form>
                    <form method="POST" action="/bookings/{{ $booking->id }}/status">
                        @csrf
                        <input type="hidden" name="status" value="cancelled">
                        <button type="submit" class="w-full bg-red-100 text-red-700 px-4 py-2 rounded-lg hover:bg-red-200 transition text-sm font-medium"
                                onclick="return confirm('Cancel this booking?')">
                            Cancel Booking
                        </button>
                    </form>
                @elseif ($booking->status === 'cancelled')
                    <form method="POST" action="/bookings/{{ $booking->id }}" onsubmit="return confirm('Delete this booking permanently?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="w-full bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700 transition text-sm font-medium">
                            Delete Booking
                        </button>
                    </form>
                @elseif ($booking->status === 'completed')
                    <p class="text-sm text-green-600 font-medium text-center">This booking is completed.</p>
                @endif
            </div>
        </div>
    </div>
@endsection
