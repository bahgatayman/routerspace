{{--
    Per-day timeline for every bookable room, laid out as a small Gantt: a
    fixed-width room-identity column on the start side, and ONE shared
    horizontally-scrolling area (time axis + every room's track stacked
    vertically) on the end side — so every room uses the same time scale,
    visible range, and scroll position instead of each room scrolling
    independently. Shared by the day view and each day of the week view.
    Expects: $dayRooms (array of
    ['room','blocks','slots','bookings','laneItems','laneCount','live','date']),
    $isDayView (bool, optional — gates the current-time line to Day view only,
    per spec; Week view never shows it, even for today's own day-block).

    Per-room layers, all on the SAME shared scale computed once below (never
    per-room — that was the previous gap that let rooms scroll/align
    independently):
      1. Background — closed/other-use/free/partial/full, straight from
         AvailabilityService::freeBusyForDay(). Stays authoritative even
         where a filtered-out booking (the calendar's status filter) means
         no block renders on top of it. A room whose own blocks don't reach
         the full shared range simply has nothing drawn in that margin — a
         blank track, never an invented "closed".
      2. Click-to-book overlay — a transparent hover target over each
         genuinely free gap (AvailabilityService::bookableSlots()), not a
         separate visible pill row.
      3. Real booking blocks — positioned/sized from each booking's own
         start_time/end_time (BookingController::assignTimelineLanes()).
         Lane-stacked so concurrent shared-room bookings never overlap each
         other visually; an exclusive room only ever needs one lane.

    `inset-inline-start`, not `left`, throughout — it must flip with the
    page's RTL/LTR direction, which a plain `left: X%` would not.
--}}
@php
    $toMinutes = function (string $time) {
        if (str_starts_with($time, '24:00')) {
            return 24 * 60;
        }
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    };
    $typeColors = ['blue' => 'bg-blue-100 text-blue-700', 'purple' => 'bg-purple-100 text-purple-700', 'green' => 'bg-green-100 text-green-700', 'orange' => 'bg-orange-100 text-orange-700', 'pink' => 'bg-pink-100 text-pink-700', 'gray' => 'bg-gray-100 text-gray-700'];
    // Tall enough for a 2-line block (name + time/party) — see Layer 3 below.
    $laneHeight = 46; // px per lane — exclusive rooms always render exactly one.
    $isDayView = $isDayView ?? false;

    // One consolidated empty state for the whole set, instead of repeating
    // "nothing booked" under every idle room — an idle room's track still
    // renders (an all-green bar already says "free" on its own).
    $allRoomsEmpty = collect($dayRooms)->every(fn ($entry) => $entry['bookings']->isEmpty());

    // Per-room sidebar content + row height, computed ONCE here and read by
    // both the sidebar column and the track column below — so a room's
    // identity (name / type+live / "also bookable as", up to 3 stacked
    // lines) never gets clipped by a row sized only for its lane count, and
    // the two columns can never drift out of sync with each other.
    $roomMeta = collect($dayRooms)->map(function ($entry) use ($laneHeight) {
        $room = $entry['room'];
        // Guarded by method_exists(): the multi-use-room relationships
        // aren't always present on Room (depends on migration state), and
        // calling an undefined one throws rather than returning null.
        $siblingUses = method_exists($room, 'isAdditionalUse')
            ? ($room->isAdditionalUse()
                ? collect([$room->physicalRoom])->concat($room->physicalRoom?->uses ?? collect())
                : $room->uses)
            : collect();
        $siblingUses = ($siblingUses ?? collect())->filter(fn ($r) => $r && $r->id !== $room->id);
        $laneCount = max(1, $entry['laneCount'] ?? 1);
        // 2 lines (name, type+live) always; a 3rd line only when the
        // "also bookable as" hint actually renders.
        $sidebarMinHeight = $siblingUses->isNotEmpty() ? 58 : 42;

        return [
            'siblingUses' => $siblingUses,
            'trackHeight' => max($laneCount * $laneHeight + 6, $sidebarMinHeight),
        ];
    });

    // ONE shared time scale for every room below, computed from the union of
    // their own (already-authoritative) blocks — never fabricated.
    $dayBlocks = collect($dayRooms)->flatMap(fn ($entry) => $entry['blocks']);
    $dayStartMin = $dayBlocks->isNotEmpty() ? $dayBlocks->min(fn ($b) => $toMinutes($b['start'])) : 0;
    $dayEndMin = $dayBlocks->isNotEmpty() ? $dayBlocks->max(fn ($b) => $toMinutes($b['end'])) : 0;
    $dayTotalMinutes = $dayEndMin - $dayStartMin;
    // 56px per hour gives a booking block room for a name + time without
    // truncating; the rail scrolls below that instead of cramming everything
    // on narrow viewports.
    $dayRailMinWidth = $dayTotalMinutes > 0 ? max(320, ($dayTotalMinutes / 60) * 56) : 320;
    $pct = fn (int $min) => $dayTotalMinutes > 0 ? min(100, max(0, (($min - $dayStartMin) / $dayTotalMinutes) * 100)) : 0;

    $hourTicks = [];
    if ($dayTotalMinutes > 0) {
        for ($m = (int) (ceil($dayStartMin / 60) * 60); $m <= $dayEndMin; $m += 60) {
            $hourTicks[] = $m;
        }
    }

    // Current-time line: Day view only (per spec), and only when the date
    // actually being rendered is today — never for a historical/future date,
    // and never in Week view even on today's own day-block.
    $firstEntryDate = $dayRooms[0]['date'] ?? null;
    $nowPct = null;
    if ($isDayView && $firstEntryDate === now()->format('Y-m-d') && $dayTotalMinutes > 0) {
        $nowMin = ((int) now()->format('H') * 60) + (int) now()->format('i');
        if ($nowMin >= $dayStartMin && $nowMin <= $dayEndMin) {
            $nowPct = $pct($nowMin);
        }
    }
@endphp

@if (empty($dayRooms))
    <p class="text-sm text-gray-400 text-center py-6">{{ __('app.empty.no_rooms_for_calendar') }}</p>
@else
    <div class="border border-gray-100 rounded-lg overflow-hidden">
        <div class="flex">
            {{-- Room identity column — stays visible while the timeline scrolls. --}}
            <div class="shrink-0 w-32 sm:w-44 border-e border-gray-100 divide-y divide-gray-100">
                <div class="h-5 flex items-center px-2.5">
                    <span class="text-[11px] font-semibold text-gray-500">{{ __('app.booking.rooms_count', ['count' => count($dayRooms)]) }}</span>
                </div>
                @foreach ($dayRooms as $i => $entry)
                    @php
                        $room = $entry['room'];
                        $trackHeight = $roomMeta[$i]['trackHeight'];
                        $siblingUses = $roomMeta[$i]['siblingUses'];
                        // Occupied/empty/full — same green/amber/red philosophy
                        // as the timeline background, for a quick-glance dot.
                        $liveDot = null;
                        if ($entry['live']) {
                            $occupied = $entry['live']['occupied'];
                            $capacity = $entry['live']['capacity'];
                            $liveDot = $occupied <= 0 ? 'bg-green-500' : ($occupied >= $capacity ? 'bg-red-500' : 'bg-yellow-500');
                        }
                    @endphp
                    <div class="px-2.5 py-1 flex flex-col justify-center gap-0.5 overflow-hidden" style="height: {{ $trackHeight }}px;">
                        <span class="text-sm font-medium text-gray-900 truncate" title="{{ $room->workspace?->name }} / {{ $room->name }}">{{ $room->name }}</span>
                        <div class="flex items-center gap-1 min-w-0">
                            <span class="inline-flex items-center px-1.5 py-0 rounded-full text-[10px] font-medium shrink-0 {{ $typeColors[$room->typeColor()] ?? 'bg-gray-100 text-gray-700' }}">
                                {{ $room->typeLabel() }}
                            </span>
                            @if ($entry['live'])
                                <span class="inline-flex items-center gap-1 text-[10px] font-medium text-gray-600 truncate" title="{{ __('app.booking.live_now', ['occupied' => $entry['live']['occupied'], 'capacity' => $entry['live']['capacity']]) }}">
                                    <span class="w-1.5 h-1.5 rounded-full shrink-0 {{ $liveDot }}"></span>
                                    {{ $entry['live']['occupied'] }}/{{ $entry['live']['capacity'] }}
                                </span>
                            @endif
                        </div>
                        @if ($siblingUses->isNotEmpty())
                            <span class="text-[10px] text-gray-400 truncate" title="{{ __('app.booking.also_bookable_as', ['names' => $siblingUses->pluck('name')->join(', ')]) }}">
                                {{ __('app.booking.also_bookable_as', ['names' => $siblingUses->pluck('name')->join(', ')]) }}
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Shared horizontally-scrolling timeline: one axis + every room's track, all on the same scale. --}}
            <div class="flex-1 min-w-0 overflow-x-auto">
                <div class="relative" style="min-width: {{ $dayRailMinWidth }}px;">
                    <div class="relative h-5 border-b border-gray-100">
                        @foreach ($hourTicks as $m)
                            <span class="absolute top-0.5 text-[10px] text-gray-400 ps-1" style="inset-inline-start: {{ $pct($m) }}%">
                                {{ \Carbon\Carbon::createFromTime(intdiv($m, 60) % 24, $m % 60)->format('g A') }}
                            </span>
                        @endforeach
                    </div>

                    <div class="divide-y divide-gray-100">
                        @foreach ($dayRooms as $i => $entry)
                            @php
                                $room = $entry['room'];
                                $blocks = $entry['blocks'];
                                $slots = $entry['slots'] ?? [];
                                $laneItems = $entry['laneItems'] ?? [];
                                $entryDate = $entry['date'];
                                $trackHeight = $roomMeta[$i]['trackHeight'];
                            @endphp
                            <div class="relative" style="height: {{ $trackHeight }}px;">
                                {{-- Layer 1: background availability. --}}
                                <div class="absolute inset-0 flex">
                                    @foreach ($blocks as $block)
                                        @php
                                            $bWidth = max(0, $pct($toMinutes($block['end'])) - $pct($toMinutes($block['start'])));
                                            $closed = $block['closed'] ?? false;
                                            $otherUse = $block['other_use'] ?? false;
                                            $bg = $closed ? 'bg-gray-100' : ($otherUse ? 'bg-indigo-50' : ($block['used'] === 0 ? 'bg-green-50' : ($block['available'] > 0 ? 'bg-yellow-50' : 'bg-red-50')));
                                            $tooltip = $closed
                                                ? "{$block['start']}–{$block['end']}: ".__('app.settings.working_hours.closed')
                                                : ($otherUse
                                                    ? "{$block['start']}–{$block['end']}: ".__('app.booking.legend.other_use')
                                                    : "{$block['start']}–{$block['end']}: {$block['used']}/{$block['capacity']} ".__('app.session.occupied'));
                                        @endphp
                                        <div class="{{ $bg }} h-full border-e border-white last:border-e-0" style="width: {{ $bWidth }}%" title="{{ $tooltip }}"></div>
                                    @endforeach
                                </div>

                                {{-- Subtle hour guides, on the same shared scale as the axis above. --}}
                                @foreach ($hourTicks as $m)
                                    <div class="absolute top-0 h-full w-px bg-gray-900/5 pointer-events-none" style="inset-inline-start: {{ $pct($m) }}%"></div>
                                @endforeach

                                {{--
                                    Layer 2: click-to-book overlay — a transparent
                                    hover target, not a separate visible pill row.
                                    Convenience shortcut only: the calendar's
                                    availability is never treated as authorization
                                    to book, store() always re-validates capacity
                                    server-side against live data.
                                --}}
                                @foreach ($slots as $slot)
                                    @php
                                        $sLeft = $pct($toMinutes($slot['start']));
                                        $sRight = $pct($toMinutes($slot['end']));
                                        $slotLabel = \Carbon\Carbon::createFromFormat('H:i', $slot['start'])->format('h:i A');
                                    @endphp
                                    @if ($slot['available'])
                                        <a href="/bookings/create?{{ http_build_query(['room_id' => $room->id, 'booking_date' => $entryDate, 'start_time' => $slot['start'], 'end_time' => $slot['end']]) }}"
                                           class="absolute top-0 h-full hover:bg-green-600/15 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500"
                                           style="inset-inline-start: {{ $sLeft }}%; width: {{ max(0, $sRight - $sLeft) }}%;"
                                           title="{{ __('app.booking.book_this_slot') }} — {{ $slotLabel }}"
                                           aria-label="{{ __('app.booking.book_this_slot') }} {{ $slotLabel }}">
                                        </a>
                                    @endif
                                @endforeach

                                {{-- Layer 3: real booking blocks, positioned/sized from each booking's own start/end time. --}}
                                @foreach ($laneItems as $item)
                                    @php
                                        $b = $item['booking'];
                                        $left = $pct($item['startMin']);
                                        $right = $pct($item['endMin']);
                                        $width = max(0.6, $right - $left);
                                        $top = $item['lane'] * $laneHeight + 3;
                                    @endphp
                                    <a href="/bookings/{{ $b->id }}"
                                       class="absolute flex flex-col justify-center px-2 py-1 rounded border border-black/10 shadow-sm overflow-hidden hover:brightness-95 hover:shadow transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-1 {{ $b->statusBadgeClass() }}"
                                       style="inset-inline-start: {{ $left }}%; width: {{ $width }}%; top: {{ $top }}px; height: {{ $laneHeight - 4 }}px;"
                                       title="{{ $b->hotspotUser->name }} &middot; {{ $b->timeRange() }} &middot; {{ $b->statusLabel() }}{{ $b->party_size > 1 ? ' · '.__('app.session.party_of', ['count' => $b->party_size]) : '' }}">
                                        <span class="flex items-center gap-1 min-w-0">
                                            <span class="font-semibold text-[11px] truncate leading-tight">{{ $b->hotspotUser->name }}</span>
                                            {{-- Status must never depend on color alone — a small badge backs up
                                                 the block's own background color once there's room for it. --}}
                                            <span class="hidden lg:inline-block shrink-0 px-1 rounded text-[9px] font-medium bg-white/60 leading-tight">{{ $b->statusLabel() }}</span>
                                        </span>
                                        <span class="hidden sm:flex items-center justify-between gap-1 text-[10px] opacity-80 leading-tight">
                                            <span class="truncate">{{ $b->timeRange() }}</span>
                                            <span class="inline-flex items-center gap-0.5 shrink-0">
                                                <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                                {{ $b->party_size }}
                                            </span>
                                        </span>
                                    </a>
                                @endforeach

                                {{-- Current-time line — repeated per-track (not one giant overlay) so contiguous, borderless tracks read as one continuous line. --}}
                                @if ($nowPct !== null)
                                    <div class="absolute top-0 h-full w-px bg-red-500 pointer-events-none" style="inset-inline-start: {{ $nowPct }}%" aria-hidden="true"></div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if ($allRoomsEmpty)
        <p class="text-xs text-gray-400 text-center py-2">{{ __('app.empty.no_bookings_on_day') }}</p>
    @endif
@endif
