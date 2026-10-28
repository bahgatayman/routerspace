{{--
    Compact, single-row legend covering both color systems the calendar uses:
    the room timeline (occupancy, per time-block) and booking status (per
    booking). They can reuse the same color for different reasons (e.g. red
    = fully booked on the timeline, but red = cancelled on a status pill) —
    each dot carries its own adjacent text label, so that's never ambiguous
    even compressed into one row.

    Collapsed by default so the calendar itself comes first; a real <details>
    the owner opens when they need the key.

    Expects: $showRoomAvailability (bool) — the timeline/slot pills only make
    sense on the day/week views, which are the only ones that render
    partials.booking-day-rooms.
--}}
@php
    $statusPreview = collect(\App\Models\Booking::STATUSES)
        ->map(fn ($status) => new \App\Models\Booking(['status' => $status]));
    // Booking::statusColor() returns a plain color name (e.g. 'yellow') —
    // mapped here to a literal, complete Tailwind class per the app's
    // existing convention (see e.g. booking-day-rooms.blade.php's
    // $typeColors) rather than interpolating the name into a class string.
    $statusDotColors = [
        'yellow' => 'bg-yellow-500', 'blue' => 'bg-blue-500', 'teal' => 'bg-teal-500',
        'cyan' => 'bg-cyan-500', 'green' => 'bg-green-500', 'red' => 'bg-red-500',
        'orange' => 'bg-orange-500', 'gray' => 'bg-gray-400',
    ];
@endphp

<details class="bg-white rounded-xl shadow-sm border border-gray-100 mb-4 group">
    <summary class="cursor-pointer select-none list-none px-4 py-2.5 flex items-center justify-between text-sm font-medium text-gray-700 hover:bg-gray-50 rounded-xl transition">
        <span class="flex items-center gap-2">
            <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            {{ __('app.booking.legend.title') }}
        </span>
        <svg class="w-4 h-4 text-gray-400 transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
        </svg>
    </summary>

    <div class="px-4 pb-3 pt-1 border-t border-gray-100 flex flex-wrap items-center gap-x-5 gap-y-2">
        @foreach ($statusPreview as $preview)
            <span class="inline-flex items-center gap-1.5 text-xs text-gray-600">
                <span class="w-2 h-2 rounded-full {{ $statusDotColors[$preview->statusColor()] ?? 'bg-gray-400' }}"></span>{{ $preview->statusLabel() }}
            </span>
        @endforeach

        @if ($showRoomAvailability ?? false)
            <span class="w-px h-4 bg-gray-200 hidden sm:inline-block"></span>
            <span class="inline-flex items-center gap-1.5 text-xs text-gray-600">
                <span class="w-2 h-2 rounded-full bg-green-500"></span>{{ __('app.booking.legend.free') }}
            </span>
            <span class="inline-flex items-center gap-1.5 text-xs text-gray-600">
                <span class="w-2 h-2 rounded-full bg-yellow-400"></span>{{ __('app.booking.legend.partial') }}
            </span>
            <span class="inline-flex items-center gap-1.5 text-xs text-gray-600">
                <span class="w-2 h-2 rounded-full bg-red-400"></span>{{ __('app.booking.legend.full') }}
            </span>
            <span class="inline-flex items-center gap-1.5 text-xs text-gray-600">
                <span class="w-2 h-2 rounded-full bg-gray-300"></span>{{ __('app.booking.legend.closed') }}
            </span>
            <span class="inline-flex items-center gap-1.5 text-xs text-gray-600">
                <span class="w-2 h-2 rounded-full bg-indigo-400"></span>{{ __('app.booking.legend.other_use') }}
            </span>
        @endif
    </div>
</details>
