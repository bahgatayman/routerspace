@extends('layouts.app')

@section('page-title', $user->name)

@php
    $initials = collect(preg_split('/\s+/', trim($user->name)))
        ->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
    $isActive = $user->status === 'active';
@endphp

@section('content')
    @if (session('success'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-4">{{ session('success') }}</div>
    @endif

    @if (session('error'))
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">{{ session('error') }}</div>
    @endif

    {{-- ── Identity header ─────────────────────────────────────────────── --}}
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 lg:p-6 mb-6">
        <div class="flex flex-col sm:flex-row sm:items-center gap-4">
            <div class="w-16 h-16 shrink-0 rounded-full bg-gradient-to-br from-blue-600 to-blue-400 text-white flex items-center justify-center text-xl font-bold">
                {{ $initials ?: '?' }}
            </div>

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl lg:text-2xl font-bold text-gray-900 truncate">{{ $user->name }}</h1>
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $isActive ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                        {{ $isActive ? __('app.status.active') : __('app.status.inactive') }}
                    </span>
                    @if ($owner->hasFeature('hotspot') && $user->speedProfile)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700">
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            {{ $user->speedProfile->name }}
                        </span>
                    @endif
                    @if ($openSession)
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-orange-50 text-orange-700">
                            <span class="w-1.5 h-1.5 rounded-full bg-orange-500 animate-pulse"></span>
                            {{ __('app.user.session_in_progress') }}
                        </span>
                    @endif
                </div>
                <p class="text-sm text-gray-500 mt-1">
                    {{ __('app.user.member_since') }} {{ $user->created_at->translatedFormat('F Y') }}
                </p>
            </div>

            <div class="flex items-center gap-2 shrink-0">
                <a href="/users/{{ $user->id }}/edit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
                    {{ __('app.common.edit') }}
                </a>

                <details class="relative group">
                    <summary class="list-none cursor-pointer w-9 h-9 rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zm0 6a2 2 0 110-4 2 2 0 010 4zm0 6a2 2 0 110-4 2 2 0 010 4z"/></svg>
                    </summary>
                    <div class="absolute end-0 mt-2 w-52 bg-white rounded-lg shadow-lg border border-gray-100 py-1 z-20">
                        <form method="POST" action="/users/{{ $user->id }}/toggle-status">
                            @csrf
                            <button type="submit" class="w-full text-start px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                {{ $isActive ? __('app.btn.deactivate') : __('app.btn.activate') }}
                            </button>
                        </form>
                        @if ($owner->hasFeature('booking'))
                            <a href="/bookings/create?hotspot_user_id={{ $user->id }}" data-book-name="{{ $user->name }}" data-book-phone="{{ $user->phone }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                {{ __('app.booking.new_booking') }}
                            </a>
                        @endif
                        <div class="border-t border-gray-100 my-1"></div>
                        <form method="POST" action="/users/{{ $user->id }}" onsubmit="return confirm('Delete this user?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="w-full text-start px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                                {{ __('app.common.delete') }}
                            </button>
                        </form>
                    </div>
                </details>
            </div>
        </div>
    </div>

    @if ($showPackages)
        @include('users._package-summary')
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- ── Left + centre ───────────────────────────────────────────── --}}
        <div class="lg:col-span-2 space-y-6">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                {{-- Contact information --}}
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 lg:p-6">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-900 mb-4">
                        <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        {{ __('app.user.contact_information') }}
                    </h2>

                    <ul class="space-y-3.5">
                        <li class="flex items-start gap-3">
                            <svg class="w-4 h-4 mt-0.5 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                            <div class="min-w-0">
                                <p class="text-sm text-gray-900 font-medium">{{ $user->phone }}</p>
                                @if ($owner->hasFeature('hotspot'))
                                    <p class="text-xs text-gray-400">{{ __('app.user.router_username') }}</p>
                                @endif
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <svg class="w-4 h-4 mt-0.5 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            <p class="text-sm {{ $user->email ? 'text-gray-900' : 'text-gray-400 italic' }} break-all">
                                {{ $user->email ?: __('app.user.no_email') }}
                            </p>
                        </li>
                        <li class="flex items-start gap-3">
                            <svg class="w-4 h-4 mt-0.5 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            <p class="text-sm text-gray-900">{{ $user->created_at->format('M d, Y') }}</p>
                        </li>
                    </ul>
                </div>

                {{-- Internet plan (hotspot) — the member's speed profile --}}
                @if ($owner->hasFeature('hotspot'))
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 lg:p-6">
                        <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-900 mb-4">
                            <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/></svg>
                            {{ __('app.user.internet_plan') }}
                            @if ($user->router_sync_status !== 'synced')
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $user->router_sync_status === 'pending' ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700' }}" title="{{ $user->router_sync_error }}">
                                    {{ __('app.mikrotik.sync_status_'.$user->router_sync_status) }}
                                </span>
                            @endif
                        </h2>

                        <div class="rounded-xl bg-gradient-to-br from-blue-800 to-blue-600 text-white p-4">
                            <p class="font-semibold">{{ $user->speedProfile->name ?? __('app.user.no_speed_profile') }}</p>
                            <div class="grid grid-cols-2 gap-3 mt-4">
                                <div class="rounded-lg bg-white/10 px-3 py-2">
                                    <p class="text-[0.65rem] uppercase tracking-wide text-blue-100/80">{{ __('app.user.download_speed') }}</p>
                                    <p class="text-sm font-semibold">&darr; {{ $user->speed_download }}</p>
                                </div>
                                <div class="rounded-lg bg-white/10 px-3 py-2">
                                    <p class="text-[0.65rem] uppercase tracking-wide text-blue-100/80">{{ __('app.user.upload_speed') }}</p>
                                    <p class="text-sm font-semibold">&uarr; {{ $user->speed_upload }}</p>
                                </div>
                            </div>
                        </div>

                        <form method="POST" action="/users/{{ $user->id }}/speed" class="mt-4">
                            @csrf
                            <label for="speed_profile_id" class="block text-xs font-medium text-gray-500 mb-1.5">{{ __('app.user.select_speed_profile') }}</label>
                            <div class="flex gap-2">
                                <select name="speed_profile_id" id="speed_profile_id" required
                                        class="flex-1 min-w-0 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                    <option value="">{{ __('app.placeholder.select_profile') }}</option>
                                    @foreach ($speedProfiles as $profile)
                                        <option value="{{ $profile->id }}" {{ $user->speed_profile_id == $profile->id ? 'selected' : '' }}>
                                            {{ $profile->name }} (&darr;{{ $profile->speed_download }} / &uarr;{{ $profile->speed_upload }})
                                        </option>
                                    @endforeach
                                </select>
                                <button type="submit" class="shrink-0 bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
                                    {{ __('app.common.save') }}
                                </button>
                            </div>
                            <p class="text-xs text-gray-400 mt-2">{{ __('app.user.speed_change_hint') }}</p>
                        </form>
                    </div>
                @endif

                {{-- Notes --}}
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 lg:p-6 {{ $owner->hasFeature('hotspot') ? 'md:col-span-2' : '' }}">
                    <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-900 mb-3">
                        <svg class="w-4 h-4 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        {{ __('app.user.notes') }}
                    </h2>
                    <p class="text-sm {{ $user->notes ? 'text-gray-600' : 'text-gray-400 italic' }}">
                        {{ $user->notes ?: __('app.user.no_notes') }}
                    </p>
                </div>
            </div>

            @if ($showPackages)
                @include('users._packages')
            @endif

            {{-- Booking history --}}
            @if ($owner->hasFeature('booking'))
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="flex items-center justify-between px-5 lg:px-6 py-4 border-b border-gray-100">
                        <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-900">
                            <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            {{ __('app.user.booking_history') }}
                        </h2>
                        @if ($recentBookings->isNotEmpty())
                            <a href="/bookings?hotspot_user_id={{ $user->id }}" class="text-xs font-semibold text-blue-600 hover:text-blue-700">
                                {{ __('app.user.view_all_bookings') }} &rarr;
                            </a>
                        @endif
                    </div>

                    @if ($recentBookings->isEmpty())
                        <div class="px-5 lg:px-6 py-8 text-center">
                            <p class="text-sm text-gray-400">{{ __('app.empty.no_bookings') }}</p>
                            <a href="/bookings/create?hotspot_user_id={{ $user->id }}" data-book-name="{{ $user->name }}" data-book-phone="{{ $user->phone }}" class="text-blue-600 hover:underline text-sm font-medium mt-2 inline-block">
                                {{ __('app.booking.new_booking') }}
                            </a>
                        </div>
                    @else
                        @php $colors = ['yellow' => 'bg-yellow-100 text-yellow-800', 'blue' => 'bg-blue-100 text-blue-800', 'green' => 'bg-green-100 text-green-800', 'red' => 'bg-red-100 text-red-800']; @endphp
                        <ul class="divide-y divide-gray-50">
                            @foreach ($recentBookings as $booking)
                                <li>
                                    <a href="/bookings/{{ $booking->id }}" class="flex items-center gap-4 px-5 lg:px-6 py-3.5 hover:bg-gray-50 transition">
                                        <span class="w-11 h-11 shrink-0 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center">
                                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-medium text-gray-900 truncate">
                                                {{ $booking->room?->workspace?->name }} / {{ $booking->room?->name }}
                                            </p>
                                            <p class="text-xs text-gray-400 mt-0.5">
                                                {{ $booking->booking_date->format('d M Y') }} · {{ $booking->total_hours }} {{ __('app.table.th.hours') }}
                                            </p>
                                        </div>
                                        <span class="hidden sm:inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $colors[$booking->statusColor()] ?? 'bg-gray-100 text-gray-800' }}">
                                            {{ $booking->statusLabel() }}
                                        </span>
                                        <span class="text-sm font-semibold text-gray-900 shrink-0">ج.م {{ number_format($booking->total_price, 2) }}</span>
                                        <svg class="w-4 h-4 text-gray-300 shrink-0 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </div>

        {{-- ── Activity timeline ───────────────────────────────────────── --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden self-start">
            <div class="px-5 lg:px-6 py-4 border-b border-gray-100">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-gray-900">
                    <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    {{ __('app.user.activity') }}
                </h2>
            </div>

            <ol class="px-5 lg:px-6 py-5 space-y-5">
                @foreach ($activity as $item)
                    @php
                        [$icon, $tone, $label] = match ($item['type']) {
                            'booking'         => ['M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z', 'bg-blue-100 text-blue-600',       __('app.user.activity_booking')],
                            'session_open'    => ['M13 10V3L4 14h7v7l9-11h-7z',                                                        'bg-orange-100 text-orange-600',   __('app.user.activity_session_open')],
                            'session_closed'  => ['M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',                                     'bg-green-100 text-green-600',     __('app.user.activity_session_closed')],
                            default           => ['M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',               'bg-gray-100 text-gray-500',       __('app.user.activity_account_created')],
                        };
                    @endphp
                    <li class="relative flex gap-3 {{ $loop->last ? '' : 'pb-5' }}">
                        @unless ($loop->last)
                            <span class="absolute top-9 start-[0.9rem] bottom-0 w-px bg-gray-100"></span>
                        @endunless
                        <span class="relative z-10 w-7 h-7 shrink-0 rounded-full flex items-center justify-center {{ $tone }}">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icon }}"/></svg>
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-gray-800 leading-snug">
                                {{ $label }}
                                @if (!empty($item['room']))
                                    <span class="font-medium">{{ $item['room'] }}</span>
                                @endif
                            </p>
                            @if (($item['price'] ?? 0) > 0)
                                <p class="text-xs text-gray-500 mt-0.5">
                                    ج.م {{ number_format($item['price'], 2) }}
                                    @if (!empty($item['minutes'])) · {{ (int) $item['minutes'] }} {{ __('app.user.minutes') }} @endif
                                </p>
                            @endif
                            <p class="text-xs text-gray-400 mt-0.5">{{ $item['at']?->diffForHumans() }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>
    </div>

    {{-- ── Lifetime figures ────────────────────────────────────────────── --}}
    @if ($stats)
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 mt-6 grid grid-cols-1 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x rtl:sm:divide-x-reverse divide-gray-100">
            <div class="px-6 py-5 text-center">
                <p class="text-xs font-medium text-gray-500">{{ __('app.user.total_bookings') }}</p>
                <p class="text-2xl font-bold text-gray-900 mt-1">{{ $stats['bookings'] }}</p>
            </div>
            <div class="px-6 py-5 text-center">
                <p class="text-xs font-medium text-gray-500">{{ __('app.user.total_spent') }}</p>
                <p class="text-2xl font-bold text-gray-900 mt-1">ج.م {{ number_format($stats['spent'], 2) }}</p>
                @if ($stats['minutes'] > 0)
                    <p class="text-xs text-gray-400 mt-0.5">{{ (int) $stats['minutes'] }} {{ __('app.user.shared_minutes') }}</p>
                @endif
            </div>
            <div class="px-6 py-5 text-center">
                <p class="text-xs font-medium text-gray-500">{{ __('app.user.last_booking') }}</p>
                <p class="text-2xl font-bold text-gray-900 mt-1">
                    {{ $stats['last'] ? $stats['last']->format('d M Y') : '—' }}
                </p>
            </div>
        </div>
    @endif
@endsection
