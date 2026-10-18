@extends('layouts.app')

@section('page-title', __('app.booking.bookings'))

@section('content')
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <h1 class="text-2xl font-bold text-gray-900">{{ __('app.booking.bookings') }}</h1>
        <div class="flex gap-2">
            <a href="/bookings/availability" data-ls-open="availability-modal" aria-haspopup="dialog" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition text-sm font-medium">
                {{ __('app.booking.quick_availability') }}
            </a>
            <a href="/bookings/calendar" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition text-sm font-medium">
                {{ __('app.btn.calendar_view') }}
            </a>
            <a href="/bookings/create" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
                + {{ __('app.booking.new_booking') }}
            </a>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-6">
        <form method="GET" action="/bookings" class="flex flex-wrap gap-3 items-end">
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('app.common.status') }}</label>
                <select name="status" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">{{ __('app.common.all') }}</option>
                    <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>{{ __('app.status.pending') }}</option>
                    <option value="confirmed" {{ $status === 'confirmed' ? 'selected' : '' }}>{{ __('app.status.confirmed') }}</option>
                    <option value="completed" {{ $status === 'completed' ? 'selected' : '' }}>{{ __('app.status.completed') }}</option>
                    <option value="cancelled" {{ $status === 'cancelled' ? 'selected' : '' }}>{{ __('app.status.cancelled') }}</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('app.common.date') }}</label>
                <input type="date" name="date" value="{{ $date }}"
                       class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">{{ __('app.booking.room') }}</label>
                <select name="room_id" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">{{ __('app.common.all') }} {{ __('app.workspace.rooms') }}</option>
                    @foreach($rooms as $room)
                        <option value="{{ $room->id }}" {{ $roomId == $room->id ? 'selected' : '' }}>
                            {{ $room->workspace?->name }} - {{ $room->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="bg-gray-800 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition text-sm font-medium">{{ __('app.btn.filter') }}</button>
            <a href="/bookings" class="text-sm text-gray-500 hover:text-gray-700 py-2">{{ __('app.btn.clear_filters') }}</a>
        </form>
    </div>

    @php
        $counts = [
            'total' => $bookings->total(),
        ];
    @endphp

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        @if ($bookings->isEmpty() && ! $status && ! $date && ! $roomId)
            {{-- First use: explain bookings vs live sessions and point to both real workflows. --}}
            <x-ui.empty-state illustration="calendar" :title="__('app.onboarding.empty.bookings_title')" :text="__('app.onboarding.empty.bookings_text')">
                <x-ui.button variant="primary" icon="plus" href="/bookings/create">{{ __('app.booking.new_booking') }}</x-ui.button>
                <x-ui.button href="/active-sessions">{{ __('app.onboarding.empty.bookings_sessions') }}</x-ui.button>
            </x-ui.empty-state>
        @elseif ($bookings->isEmpty())
            <div class="p-12 text-center">
                <p class="text-gray-400 text-sm">{{ __('app.empty.no_bookings') }}</p>
                <a href="/bookings/create" class="text-blue-600 hover:underline text-sm font-medium mt-2 inline-block">{{ __('app.booking.new_booking') }}</a>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 bg-gray-50 border-b border-gray-100">
                            <th class="px-4 py-3 font-medium">{{ __('app.table.th.date') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('app.table.th.time') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('app.table.th.user') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('app.table.th.room') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('app.table.th.hours') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('app.table.th.total') }}</th>
                            <th class="px-4 py-3 font-medium">{{ __('app.table.th.status') }}</th>
                            <th class="px-4 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($bookings as $booking)
                            <tr class="row-link border-b border-gray-50 cursor-pointer hover:bg-gray-50 transition {{ $booking->status === 'cancelled' ? 'text-gray-400 line-through' : 'text-gray-900' }}"
                                data-href="/bookings/{{ $booking->id }}">
                                <td class="px-4 py-3 font-medium">
                                    {{-- Real link: keeps the booking keyboard-reachable and ctrl/middle-clickable. --}}
                                    <a href="/bookings/{{ $booking->id }}" class="hover:text-blue-600">{{ $booking->booking_date->format('M d, Y') }}</a>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $booking->timeRange() }}</td>
                                <td class="px-4 py-3">
                                    <a href="/users/{{ $booking->hotspotUser->id }}" class="text-blue-600 hover:underline font-medium">
                                        {{ $booking->hotspotUser->name }}
                                    </a>
                                    @if ($booking->party_size > 1)
                                        <span class="text-gray-400 text-xs">&middot; {{ __('app.session.party_of', ['count' => $booking->party_size]) }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-gray-600">{{ $booking->room->workspace?->name }} /</span>
                                    {{ $booking->room->name }}
                                </td>
                                <td class="px-4 py-3">{{ $booking->total_hours }} {{ __('app.common.hours') }}</td>
                                <td class="px-4 py-3 font-medium">ج.م {{ number_format($booking->total_price, 2) }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $booking->statusBadgeClass() }}">
                                        {{ $booking->statusLabel() }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-3">
                                        @if (in_array($booking->status, ['pending', 'confirmed']))
                                            <a href="/bookings/{{ $booking->id }}/edit" class="text-gray-600 hover:text-gray-900 hover:underline text-xs font-medium">{{ __('app.common.edit') }}</a>
                                        @endif
                                        <svg class="w-4 h-4 text-gray-300 shrink-0 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                        </svg>
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

    @include('bookings._availability-modal')
@endsection
