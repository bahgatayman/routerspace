@extends('layouts.admin')

@section('page-title', __('app.booking.bookings') . ' #' . str_pad($booking->id, 4, '0', STR_PAD_LEFT))

@section('content')
    <div class="mb-6">
        <a href="/admin/bookings" class="text-sm text-gray-500 hover:text-gray-700">&larr; {{ __('app.btn.back_to_bookings') }}</a>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 max-w-2xl">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">{{ __('app.booking.bookings') }} #{{ str_pad($booking->id, 4, '0', STR_PAD_LEFT) }}</h1>
                <p class="text-sm text-gray-500 mt-1">{{ __('app.table.th.created') }} {{ $booking->created_at->format('M d, Y h:i A') }}</p>
            </div>
            @php
                $colors = ['yellow' => 'bg-yellow-100 text-yellow-800', 'blue' => 'bg-blue-100 text-blue-800', 'green' => 'bg-green-100 text-green-800', 'red' => 'bg-red-100 text-red-800'];
            @endphp
            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ $colors[$booking->statusColor()] ?? 'bg-gray-100 text-gray-800' }}">
                {{ $booking->statusLabel() }}
            </span>
        </div>

        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
            <div>
                <dt class="text-gray-500">{{ __('app.admin.owner') }}</dt>
                <dd class="text-gray-900 font-medium mt-1">
                    <a href="/admin/owners/{{ $booking->owner->id }}" class="text-blue-600 hover:underline">{{ $booking->owner->business_name }}</a>
                </dd>
            </div>
            <div>
                <dt class="text-gray-500">{{ __('app.booking.user') }}</dt>
                <dd class="text-gray-900 font-medium mt-1">{{ $booking->hotspotUser?->name ?? __('app.admin_biz.deleted_member') }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">{{ __('app.booking.room') }}</dt>
                <dd class="text-gray-900 font-medium mt-1">{{ $booking->room->workspace?->name }} / {{ $booking->room->name }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">{{ __('app.booking.date') }}</dt>
                <dd class="text-gray-900 font-medium mt-1">{{ $booking->booking_date->format('l, M d, Y') }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">{{ __('app.booking.start_time') }}</dt>
                <dd class="text-gray-900 font-medium mt-1">{{ $booking->timeRange() }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">{{ __('app.booking.duration') }}</dt>
                <dd class="text-gray-900 font-medium mt-1">{{ $booking->total_hours }} {{ __('app.common.hours') }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">{{ __('app.booking.rate') }}</dt>
                <dd class="text-gray-900 font-medium mt-1">{{ $booking->pricing_note ?: 'ج.م '.number_format($booking->price_per_hour, 2) }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">{{ __('app.booking.total') }}</dt>
                <dd class="text-xl font-bold text-blue-600 mt-1">ج.م {{ number_format($booking->total_price, 2) }}</dd>
            </div>
        </dl>

        @if ($booking->notes)
            <div class="mt-4 pt-4 border-t border-gray-100">
                <dt class="text-sm text-gray-500">{{ __('app.booking.notes') }}</dt>
                <dd class="text-sm text-gray-900 mt-1">{{ $booking->notes }}</dd>
            </div>
        @endif
    </div>
@endsection
