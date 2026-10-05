@extends('layouts.admin')

@section('page-title', __('app.section.bookings'))

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900">{{ __('app.section.bookings') }}</h1>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        @if ($bookings->isEmpty())
            <div class="p-12 text-center">
                <p class="text-gray-400 text-sm">{{ __('app.empty.no_bookings') }}</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 bg-gray-50 border-b border-gray-100">
                            <th class="px-4 py-3 font-medium">{{ __('app.table.th.date') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('app.table.th.user') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('app.table.th.room') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('app.table.th.workspace') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('app.table.th.owner') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('app.table.th.hours') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('app.table.th.total') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('app.table.th.status') }}</th>
                            <th class="px-4 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bookings as $booking)
                            <tr class="border-b border-gray-50">
                                <td class="px-4 py-3 font-medium whitespace-nowrap">{{ $booking->booking_date->format('M d, Y') }}</td>
                                <td class="px-4 py-3">{{ $booking->hotspotUser?->name ?? __('app.admin_biz.deleted_member') }}</td>
                                <td class="px-4 py-3">{{ $booking->room->name }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $booking->room->workspace?->name ?? '—' }}</td>
                                <td class="px-4 py-3">
                                    <a href="/admin/owners/{{ $booking->owner->id }}" class="text-blue-600 hover:underline">
                                        {{ $booking->owner->business_name }}
                                    </a>
                                </td>
                                <td class="px-4 py-3">{{ $booking->total_hours }}</td>
                                <td class="px-4 py-3 font-medium">ج.م {{ number_format($booking->total_price, 2) }}</td>
                                <td class="px-4 py-3">
                                    @php
                                        $colors = ['yellow' => 'bg-yellow-100 text-yellow-800', 'blue' => 'bg-blue-100 text-blue-800', 'green' => 'bg-green-100 text-green-800', 'red' => 'bg-red-100 text-red-800'];
                                    @endphp
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $colors[$booking->statusColor()] ?? 'bg-gray-100 text-gray-800' }}">
                                        {{ $booking->statusLabel() }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <a href="/admin/bookings/{{ $booking->id }}" class="text-blue-600 hover:underline text-xs font-medium">{{ __('app.common.view') }}</a>
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
