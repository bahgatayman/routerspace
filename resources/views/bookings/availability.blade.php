@extends('layouts.app')

@section('page-title', __('app.availability.title'))

@php
    // Labels the result renderer needs (plural forms resolved here, server-side).
    $avI18n = [
        'summary' => __('app.availability.summary', ['from' => ':from', 'to' => ':to', 'days' => ':days']),
        'day1' => trans_choice('app.availability.days', 1, ['count' => 1]),
        'dayN' => trans_choice('app.availability.days', 2, ['count' => ':count']),
        'eachDay' => __('app.availability.each_day', ['start' => ':start', 'end' => ':end']),
        'allAvailable' => __('app.availability.all_available', ['days' => ':days']),
        'availableCount' => __('app.availability.available_count', ['n' => ':n', 'total' => ':total']),
        'conflict1' => trans_choice('app.availability.conflicts', 1, ['count' => 1]),
        'conflictN' => trans_choice('app.availability.conflicts', 2, ['count' => ':count']),
        'noneAll' => __('app.availability.none_all_period'),
        'roomsAll1' => trans_choice('app.availability.rooms_all_period', 1, ['count' => 1]),
        'roomsAllN' => trans_choice('app.availability.rooms_all_period', 2, ['count' => ':count']),
        'available' => __('app.availability.available'),
        'limited' => __('app.availability.limited'),
        'unavailable' => __('app.availability.unavailable'),
        'closed' => __('app.availability.closed'),
        'past' => __('app.availability.past'),
        'free' => __('app.availability.free'),
        'booked' => __('app.availability.booked'),
        'seatsFree' => __('app.availability.seats_free', ['free' => ':free', 'capacity' => ':capacity']),
        'seatBooked1' => trans_choice('app.availability.seats_booked', 1, ['count' => 1]),
        'seatBookedN' => trans_choice('app.availability.seats_booked', 2, ['count' => ':count']),
        'openEnded' => __('app.availability.open_ended'),
        'overlap' => __('app.availability.overlap', ['time' => ':time']),
        'people1' => trans_choice('app.availability.people_count', 1, ['count' => 1]),
        'peopleN' => trans_choice('app.availability.people_count', 2, ['count' => ':count']),
        'status' => __('app.availability.status'),
        'pickTimes' => __('app.availability.pick_times'),
        'seats' => __('app.availability.seats', ['count' => ':count']),
        'error' => __('app.quick_booking.error'),
        'h' => __('app.ui.unit_h'), 'm' => __('app.ui.unit_m'),
    ];
    $today = now()->format('Y-m-d');
    $prefRoom = request('room_id');
    $prefDate = request('date', $today);
@endphp

@section('content')
    <x-ui.page-header :title="__('app.availability.title')" :subtitle="__('app.availability.subtitle')" />

    {{-- One request for the whole range: GET /bookings/availability-range → AvailabilityService::rangeReport(). --}}
    <form class="ls-card ls-av-form" id="av-form" novalidate>
        <div class="ls-card-body">
            <div class="ls-inv-seg ls-av-mode" role="radiogroup" aria-label="{{ __('app.availability.mode_range') }}">
                <label><input type="radio" name="av-mode" value="single" checked><span>{{ __('app.availability.mode_single') }}</span></label>
                <label><input type="radio" name="av-mode" value="range"><span>{{ __('app.availability.mode_range') }}</span></label>
            </div>

            <div class="ls-av-grid">
                <div class="ls-field">
                    <label class="ls-label" for="av-from" data-label-single="{{ __('app.availability.date') }}" data-label-range="{{ __('app.availability.from') }}">{{ __('app.availability.date') }}</label>
                    <input type="date" id="av-from" class="ls-input" value="{{ $prefDate }}" required>
                </div>
                <div class="ls-field" id="av-to-field" hidden>
                    <label class="ls-label" for="av-to">{{ __('app.availability.to') }}</label>
                    <input type="date" id="av-to" class="ls-input" value="{{ $prefDate }}">
                </div>
                <div class="ls-field">
                    <label class="ls-label" for="av-start">{{ __('app.availability.start') }}</label>
                    <select id="av-start" class="ls-select" dir="ltr">
                        @foreach ($timeSlots as $value => $label)
                            <option value="{{ $value }}" @selected($value === '10:00')>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="ls-field">
                    <label class="ls-label" for="av-end">{{ __('app.availability.end') }}</label>
                    <select id="av-end" class="ls-select" dir="ltr">
                        @foreach ($timeSlots as $value => $label)
                            <option value="{{ $value }}" @selected($value === '18:00')>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="ls-field">
                    <label class="ls-label" for="av-room">{{ __('app.availability.room') }}</label>
                    <select id="av-room" class="ls-select">
                        <option value="">{{ __('app.availability.all_rooms') }}</option>
                        @foreach ($rooms->groupBy(fn ($r) => $r->workspace?->name ?? '') as $ws => $group)
                            <optgroup label="{{ $ws }}">
                                @foreach ($group as $room)
                                    <option value="{{ $room->id }}" @selected((string) $prefRoom === (string) $room->id)>{{ $room->name }} — {{ $room->typeLabel() }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <div class="ls-field">
                    <label class="ls-label" for="av-people">{{ __('app.availability.people') }}</label>
                    <input type="number" id="av-people" class="ls-input" min="1" max="999" value="1" inputmode="numeric">
                </div>
            </div>

            <div class="ls-av-actions">
                <x-ui.button type="submit" variant="primary" icon="search" id="av-submit">{{ __('app.availability.check') }}</x-ui.button>
                <p class="ls-error" id="av-error" role="alert" hidden></p>
            </div>
        </div>
    </form>

    <section id="av-results" class="ls-av-results" aria-live="polite" hidden></section>

    @if ($rooms->isEmpty())
        <div class="ls-card"><x-ui.empty-state :title="__('app.availability.no_rooms')" /></div>
    @endif

    <script>
    (function () {
        const T = @json($avI18n);
        const SLOTS = @json($timeSlots);
        const $ = (id) => document.getElementById(id);
        const fill = (s, map) => Object.entries(map).reduce((o, [k, v]) => o.split(':' + k).join(v), s);
        const lang = document.documentElement.lang === 'ar' ? 'ar-EG' : 'en-GB';
        const mode = () => document.querySelector('[name="av-mode"]:checked').value;
        const fmtTime = (hm) => SLOTS[hm] || hm;
        const fmtDate = (ymd, opts = { weekday: 'short', day: 'numeric', month: 'short' }) =>
            new Date(ymd + 'T00:00:00').toLocaleDateString(lang, opts);
        const fmtMins = (mins) => { const h = Math.floor(mins / 60), m = mins % 60; return (h ? h + T.h : '') + (h && m ? ' ' : '') + (m || !h ? m + T.m : ''); };
        const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };

        // Single day ⇄ date range
        function syncMode() {
            const range = mode() === 'range';
            $('av-to-field').hidden = !range;
            const label = document.querySelector('label[for="av-from"]');
            label.textContent = range ? label.dataset.labelRange : label.dataset.labelSingle;
            if (range && $('av-to').value < $('av-from').value) $('av-to').value = $('av-from').value;
        }
        document.querySelectorAll('[name="av-mode"]').forEach((r) => r.addEventListener('change', syncMode));
        $('av-from').addEventListener('change', () => { $('av-to').min = $('av-from').value; if ($('av-to').value < $('av-from').value) $('av-to').value = $('av-from').value; });

        function showError(msg) { $('av-error').textContent = msg; $('av-error').hidden = !msg; }

        $('av-form').addEventListener('submit', (e) => {
            e.preventDefault();
            showError('');
            const from = $('av-from').value;
            const to = mode() === 'range' ? $('av-to').value : from;
            if (!from || !$('av-start').value || !$('av-end').value) { showError(T.pickTimes); return; }
            const params = new URLSearchParams({ from, to, start_time: $('av-start').value, end_time: $('av-end').value, party_size: $('av-people').value || 1 });
            if ($('av-room').value) params.set('room_id', $('av-room').value);

            const btn = $('av-submit');
            LS.busy(btn, true);
            fetch(`/bookings/availability-range?${params}`, { headers: { Accept: 'application/json' } })
                .then((r) => r.json().then((b) => ({ ok: r.ok, b })))
                .then(({ ok, b }) => { if (!ok || !b.success) { showError((b && b.message) || T.error); return; } render(b); })
                .catch(() => showError(T.error))
                .finally(() => LS.busy(btn, false));
        });

        function render(data) {
            const box = $('av-results');
            box.innerHTML = '';
            box.hidden = false;

            // Summary: the period, the window, and which rooms cover all of it.
            const head = el('div', 'ls-av-summary');
            const days = data.days === 1 ? T.day1 : fill(T.dayN, { count: data.days });
            head.append(el('h2', 'ls-av-summary-title', data.days === 1 ? fmtDate(data.from, { weekday: 'long', day: 'numeric', month: 'long' }) + ' · ' + days
                : fill(T.summary, { from: fmtDate(data.from), to: fmtDate(data.to), days })));
            head.append(el('p', 'ls-av-summary-sub', fill(T.eachDay, { start: fmtTime(data.start_time), end: fmtTime(data.end_time) })));
            const whole = data.rooms.filter((r) => r.all_available).length;
            const verdict = el('p', 'ls-av-verdict ' + (whole ? 'is-ok' : 'is-none'),
                whole ? (whole === 1 ? T.roomsAll1 : fill(T.roomsAllN, { count: whole })) : T.noneAll);
            head.append(verdict);
            box.append(head);

            // Rooms fully available first, then by fewest conflicts.
            const rooms = [...data.rooms].sort((a, b) => (b.days_available - a.days_available) || a.room_name.localeCompare(b.room_name));
            const grid = el('div', 'ls-av-rooms');
            rooms.forEach((room) => grid.append(roomCard(room, data)));
            box.append(grid);
            box.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function roomCard(room, data) {
            const card = el('article', 'ls-card ls-av-room' + (room.all_available ? ' is-all' : ''));
            const head = el('div', 'ls-av-room-head');
            const who = el('div', 'ls-av-room-who');
            who.append(el('h3', 'ls-av-room-name', room.room_name));
            who.append(el('span', 'ls-av-room-meta', [room.workspace, room.type_label, room.is_shared ? fill(T.seats, { count: room.capacity }) : null].filter(Boolean).join(' · ')));
            head.append(who);
            const badge = el('span', 'ls-badge ls-badge--' + (room.all_available ? 'ok' : room.days_available ? 'warn' : 'danger'));
            badge.append(el('span', 'ls-dot'));
            const total = room.days_total === 1 ? T.day1 : fill(T.dayN, { count: room.days_total });
            badge.append(document.createTextNode(room.all_available ? fill(T.allAvailable, { days: total })
                : fill(T.availableCount, { n: room.days_available, total: room.days_total })
                  + (room.conflict_days ? ' · ' + (room.conflict_days === 1 ? T.conflict1 : fill(T.conflictN, { count: room.conflict_days })) : '')));
            head.append(badge);
            card.append(head);

            const list = el('ul', 'ls-av-days');
            room.days.forEach((day) => list.append(dayRow(room, day)));
            card.append(list);
            return card;
        }

        function dayRow(room, day) {
            const li = el('li', 'ls-av-day is-' + day.status);
            const top = el('div', 'ls-av-day-top');
            top.append(el('span', 'ls-av-day-date', fmtDate(day.date)));
            const st = el('span', 'ls-av-day-status');
            st.append(el('i', 'ls-av-dot', ''));
            let text = T[day.status] || day.status;
            if (room.is_shared && ['available', 'limited', 'unavailable'].includes(day.status)) {
                text = fill(T.seatsFree, { free: day.remaining, capacity: day.capacity })
                    + (day.used ? ' · ' + (day.used === 1 ? T.seatBooked1 : fill(T.seatBookedN, { count: day.used })) : '');
            }
            st.append(document.createTextNode(text));
            top.append(st);
            li.append(top);

            if (!['past', 'closed'].includes(day.status) && day.conflicts.length) {
                // The requested window, split: free / booked pieces (exclusive rooms).
                if (!room.is_shared) {
                    const segs = el('div', 'ls-av-segs');
                    // A private room is simply booked or free: join neighbouring
                    // pieces in the same state (overlapping bookings split them).
                    const merged = [];
                    day.segments.forEach((s) => {
                        const busy = s.available <= 0, last = merged[merged.length - 1];
                        if (last && last.busy === busy && last.end === s.start) last.end = s.end;
                        else merged.push({ start: s.start, end: s.end, busy });
                    });
                    merged.forEach((s) => {
                        segs.append(el('span', 'ls-av-seg ' + (s.busy ? 'is-booked' : 'is-free'),
                            fmtTime(s.start) + ' – ' + fmtTime(s.end) + ' · ' + (s.busy ? T.booked : T.free)));
                    });
                    li.append(segs);
                }
                // Who / what blocks it.
                const ul = el('ul', 'ls-av-conflicts');
                day.conflicts.forEach((c) => {
                    const item = el('li', 'ls-av-conflict');
                    const when = c.end ? fmtTime(c.start) + ' – ' + fmtTime(c.end) : fmtTime(c.start) + ' → ' + T.openEnded;
                    item.append(el('b', null, when));
                    const bits = [c.customer, (T.status && T.status[c.status]) || c.status];
                    if (room.is_shared) bits.push(c.party_size === 1 ? T.people1 : fill(T.peopleN, { count: c.party_size }));
                    else if (c.overlap_minutes > 0) bits.push(fill(T.overlap, { time: fmtMins(c.overlap_minutes) }));
                    item.append(el('span', null, bits.filter(Boolean).join(' · ')));
                    if (c.booking_id) { const a = el('a', 'ls-link', '#' + c.booking_id); a.href = '/bookings/' + c.booking_id; item.append(a); }
                    ul.append(item);
                });
                li.append(ul);
            }
            return li;
        }

        syncMode();
    })();
    </script>
@endsection
