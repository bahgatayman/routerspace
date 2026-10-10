{{--
    Shared by bookings/create.blade.php and edit.blade.php. Who → When →
    Where → How much → Confirm — Customer first (the operator's natural
    starting point), then a compact Reservation row, Room cards, Payment,
    and a sticky/bottom checkout-style summary. Only Customer/Room get a
    bordered card (they're interactive selections); Reservation/Payment are
    lighter, borderless sections so the page reads as a workstation, not a
    stack of form cards.

    Reuses, unmodified: <x-ui.time-picker> (the grid popover + panel.js's
    existing data-ls-menu engine), the duration-chip pattern, and
    checkAvailability()'s replacement /bookings/room-options — the server
    stays the only source of truth for pricing/availability. Nothing here
    changes what start_time/end_time/room_id/hotspot_user_id/amount_paid
    mean to the controller: same field names, same validation.

    Expects (from create()/edit()): $rooms, $timeSlots, and either $booking
    (edit) or the old()/$selected* prefill variables (create).
--}}
@php
    $isEdit = isset($booking) && $booking;
    $initialUserId = $isEdit ? $booking->hotspot_user_id : old('hotspot_user_id', $selectedUserId ?? '');
    $initialRoomId = (string) ($isEdit ? $booking->room_id : old('room_id', $selectedRoomId ?? ''));
    $initialDate = $isEdit ? $booking->booking_date->format('Y-m-d') : old('booking_date', $selectedDate ?? '');
    $initialStart = $isEdit ? \Carbon\Carbon::parse($booking->start_time)->format('H:i') : old('start_time', $selectedStartTime ?? '');
    $initialEnd = $isEdit ? \Carbon\Carbon::parse($booking->end_time)->format('H:i') : old('end_time', $selectedEndTime ?? '');
    $initialAmountPaid = $isEdit ? old('amount_paid', (float) $booking->amount_paid) : old('amount_paid', '');
    $initialNotes = $isEdit ? old('notes', $booking->notes) : old('notes');
    // People the booking is priced for (RoomPricingService). Exclusive rooms
    // keep party_size = 1 for availability; the headcount travels as guest_count.
    $initialGuests = (int) ($isEdit ? old('guest_count', $booking->guest_count ?? $booking->party_size ?? 1) : old('guest_count', 1));
    $initialPlanId = (string) ($isEdit ? old('room_plan_id', $booking->room_plan_id) : old('room_plan_id', ''));
    $initialProfileId = (string) ($isEdit ? old('room_pricing_profile_id', $booking->room_pricing_profile_id) : old('room_pricing_profile_id', ''));
    $planI18n = [
        'standard' => __('app.plans.standard'),
        'defaultLabel' => __('app.pricing_profiles.default_label'),
        'forPeople1' => trans_choice('app.plans.for_people', 1, ['count' => 1]),
        'forPeopleN' => trans_choice('app.plans.for_people', 2, ['count' => ':count']),
        'booked' => __('app.plans.booked_then'), 'noFit' => __('app.plans.no_fit'), 'closed' => __('app.booking.outside_working_hours'),
    ];
    $actionUrl = $isEdit ? "/bookings/{$booking->id}" : '/bookings';

    // Spelled out ("30 min", "1.5 hr") rather than the compact "1h 30m"
    // suffixes used elsewhere (e.g. active-session elapsed time) — this is
    // the primary duration control, not a secondary readout, so it gets the
    // more legible form. Arabic keeps the existing numeral+suffix
    // convention (unit_h/unit_m) already used throughout the app.
    $isRtl = app()->getLocale() === 'ar';
    $durationOptions = collect([30, 60, 90, 120, 180, 240])->map(function ($mins) use ($isRtl) {
        if ($isRtl) {
            $label = $mins < 60
                ? $mins.__('app.ui.unit_m')
                : rtrim(rtrim(number_format($mins / 60, 1), '0'), '.').__('app.ui.unit_h');
        } else {
            $label = $mins < 60
                ? $mins.' '.__('app.common.min')
                : rtrim(rtrim(number_format($mins / 60, 1), '0'), '.').' '.__('app.common.hr');
        }

        return ['minutes' => $mins, 'label' => $label];
    });

    // Built as plain PHP arrays (not inline inside @json(...)) because
    // @json()'s compiler does a naive explode(',', ...) on its argument —
    // any top-level comma in an inline array literal (i.e. more than one
    // entry) silently truncates it to everything before the first comma.
    $roomStateLabels = [
        'free' => __('app.booking.rooms.state_free'),
        'partial' => __('app.booking.rooms.state_partial'),
        'unavailable' => __('app.booking.rooms.state_unavailable'),
    ];
    $pkgI18n = [
        'needs' => __('app.packages.needs'), 'expires' => __('app.packages.expires_short'),
        'covered' => __('app.packages.covered_total'), 'cta' => __('app.packages.cta_covered'),
        'left' => __('app.packages.left'), 'pickSlot' => __('app.packages.pick_slot_first'),
        'coveredLabel' => __('app.packages.covered'),
    ];
    $paymentStatusLabels = [
        'paid' => __('app.booking.payment.status_paid'),
        'partial' => __('app.booking.payment.status_partial'),
        'unpaid' => __('app.booking.payment.status_unpaid'),
    ];
@endphp
<div class="ls-booking-layout">
    <form method="POST" action="{{ $actionUrl }}" class="ls-booking-form" id="booking-form">
        @csrf
        @if ($isEdit)
            @method('PUT')
        @endif

        {{-- 1. Customer — the operator's starting point, styled as an actual selection, not a form field. --}}
        <div class="ls-card">
            <div class="ls-card-body">
                <h2 class="ls-section-label">{{ __('app.booking.customer') }}</h2>
                <p class="ls-section-hint">{{ __('app.booking.customer_hint') }}</p>
                <div class="relative">
                    @include('partials.member-picker', [
                        'label' => __('app.booking.customer'),
                        'selectedId' => $initialUserId,
                        'selectedName' => $isEdit ? $booking->hotspotUser->name : null,
                        'selectedPhone' => $isEdit ? $booking->hotspotUser->phone : null,
                        'hideLabel' => true,
                        'searchIcon' => true,
                    ])
                    @error('hotspot_user_id') <span class="ls-error"><x-ui.icon name="alert" />{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        @unless ($isEdit)
            {{-- Booking Type — Open Session skips the whole Reservation/Room/
                 Payment section below in favor of a minimal room + pricing
                 profile pick; the server generates start time and computes
                 the price at checkout. Create-only: an open booking is never
                 reachable through edit() (edit() only accepts pending/confirmed). --}}
            <section class="ls-plain-section" id="duration-type-choice">
                <h2 class="ls-section-label">{{ __('app.booking.duration_type.label') }}</h2>
                <div class="ls-plan-pick" role="radiogroup" aria-label="{{ __('app.booking.duration_type.label') }}">
                    <label class="ls-plan-opt is-selected" data-duration-opt="fixed">
                        <input type="radio" name="duration_type" value="fixed" checked>
                        <span class="ls-plan-opt-main"><b>{{ __('app.booking.duration_type.fixed') }}</b><small>{{ __('app.booking.duration_type.fixed_hint') }}</small></span>
                    </label>
                    <label class="ls-plan-opt" data-duration-opt="open">
                        <input type="radio" name="duration_type" value="open">
                        <span class="ls-plan-opt-main"><b>{{ __('app.booking.duration_type.open') }}</b><small>{{ __('app.booking.duration_type.open_hint') }}</small></span>
                    </label>
                </div>
            </section>
        @endunless

        <div id="fixed-time-sections">
        {{-- 2. Reservation — light section, no card border. --}}
        <section class="ls-plain-section">
            <h2 class="ls-section-label">{{ __('app.booking.summary.title') }}</h2>
            <div class="ls-reservation-row">
                <div class="ls-field ls-reservation-date">
                    <label class="ls-label" for="booking_date">{{ __('app.booking.date') }}</label>
                    <input type="date" name="booking_date" id="booking_date" class="ls-input"
                           @if (! $isEdit) min="{{ now()->format('Y-m-d') }}" @endif
                           value="{{ $initialDate }}" required>
                    @error('booking_date') <span class="ls-error"><x-ui.icon name="alert" />{{ $message }}</span> @enderror
                </div>

                <div class="ls-field ls-reservation-people">
                    <label class="ls-label" for="guest_count">{{ __('app.pricing.people_field') }}</label>
                    <div class="ls-stepper">
                        <button type="button" data-guest-step="-1" aria-label="{{ __('app.pricing.people_less') }}">&minus;</button>
                        <input type="number" name="guest_count" id="guest_count" min="1" max="999" step="1" inputmode="numeric" value="{{ max(1, $initialGuests) }}" aria-describedby="guest-count-hint">
                        <button type="button" data-guest-step="1" aria-label="{{ __('app.pricing.people_more') }}">+</button>
                    </div>
                    <p class="ls-hint" id="guest-count-hint">{{ __('app.pricing.people_field_hint') }}</p>
                    @error('guest_count') <span class="ls-error"><x-ui.icon name="alert" />{{ $message }}</span> @enderror
                </div>

                <div class="ls-field ls-reservation-duration">
                    <span class="ls-label">{{ __('app.booking.duration') }}</span>
                    <div class="ls-chips ls-chips-scroll" id="duration-chips" role="group" aria-label="{{ __('app.booking.duration') }}">
                        @foreach ($durationOptions as $opt)
                            <button type="button" class="ls-chip" data-duration-chip data-minutes="{{ $opt['minutes'] }}">{{ $opt['label'] }}</button>
                        @endforeach
                        <button type="button" class="ls-chip" data-duration-chip data-minutes="fullday" hidden>{{ __('app.pricing.full_day') }}</button>
                        <button type="button" class="ls-chip" data-duration-chip data-minutes="custom">{{ __('app.booking.custom') }}</button>
                    </div>
                </div>

                <div class="ls-field ls-reservation-start">
                    <label class="ls-label" id="start-stepper-label">{{ __('app.booking.stepper.start') }}</label>
                    <x-ui.time-stepper name="start_time" :value="$initialStart" :values="array_keys($timeSlots)"
                        :placeholder="__('app.common.select').' '.__('app.booking.start_time')" :aria-label="__('app.booking.start_time')" />
                    <p class="ls-hint" id="ends-at-hint"></p>
                    @error('start_time') <span class="ls-error"><x-ui.icon name="alert" />{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="ls-field" id="custom-end-field" style="margin-top: var(--space-3); display: none;">
                <label class="ls-label">{{ __('app.booking.end_time') }}</label>
                <x-ui.time-picker name="end_time" :value="$initialEnd" :values="array_keys($timeSlots)"
                    :placeholder="__('app.common.select').' '.__('app.booking.end_time')" :aria-label="__('app.booking.end_time')"
                    :disabled-up-to="$initialStart !== '' ? $initialStart : null" />
                @error('end_time') <span class="ls-error"><x-ui.icon name="alert" />{{ $message }}</span> @enderror
            </div>

            <p class="ls-reservation-result" id="reservation-result"></p>
        </section>

        {{-- 3. Room --}}
        <div class="ls-card">
            <div class="ls-card-body">
                <h2 class="ls-section-label">{{ __('app.booking.room') }}</h2>
                <div class="ls-room-grid" id="room-grid" role="radiogroup" aria-label="{{ __('app.booking.room') }}">
                    @foreach ($rooms as $r)
                        @include('bookings._room-card', ['room' => $r, 'selected' => $initialRoomId === (string) $r->id])
                    @endforeach
                </div>
                @error('room_id') <span class="ls-error"><x-ui.icon name="alert" />{{ $message }}</span> @enderror

                {{-- Standard pricing vs the selected room's Custom Plans (only when it has any). --}}
                <div class="ls-field" id="plan-choice" hidden style="margin-top: var(--space-4)">
                    <span class="ls-label" id="plan-choice-label">{{ __('app.plans.pricing') }}</span>
                    <div class="ls-plan-pick" id="plan-options" role="radiogroup" aria-labelledby="plan-choice-label"></div>
                </div>
                <input type="hidden" name="room_plan_id" id="room_plan_id" value="{{ $initialPlanId }}">
                {{-- Pricing profile (alternative hourly rate) — chosen in the same Pricing list as plans. --}}
                <input type="hidden" name="room_pricing_profile_id" id="room_pricing_profile_id" value="{{ $initialProfileId }}">
            </div>
        </div>

        {{-- 4. Payment --}}
        @include('bookings._payment', ['amountPaid' => $initialAmountPaid, 'memberPackageId' => $isEdit ? $booking->member_package_id : ''])
        </div>{{-- /#fixed-time-sections --}}

        @unless ($isEdit)
            @php $exclusiveRooms = $rooms->reject(fn ($r) => $r->isShared())->values(); @endphp
            <div id="open-session-section" hidden>
                <div class="ls-card">
                    <div class="ls-card-body">
                        <h2 class="ls-section-label">{{ __('app.booking.room') }}</h2>
                        <div class="ls-field">
                            {{-- Disabled until "Open Session" is chosen: it shares name="room_id" with the
                                 room cards, and an enabled empty copy posted after them would override the
                                 picked room ("room id field is required"). --}}
                            <select name="room_id" id="open-room-select" class="ls-select" aria-label="{{ __('app.booking.room') }}" disabled>
                                <option value="">{{ __('app.common.select') }}</option>
                                @foreach ($exclusiveRooms as $r)
                                    <option value="{{ $r->id }}">{{ $r->workspace?->name }} / {{ $r->name }} — {{ $r->pricingSummary() }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="ls-field" id="open-profile-field" style="margin-top: var(--space-3)" hidden>
                            <label class="ls-label" for="open-profile-select">{{ __('app.pricing_profiles.section') }}</label>
                            <select name="room_pricing_profile_id" id="open-profile-select" class="ls-select" disabled></select>
                        </div>
                    </div>
                </div>

                <section class="ls-plain-section">
                    <div class="ls-reservation-result" style="font-weight: var(--fw-medium)">{{ __('app.booking.duration_type.starts_now') }}</div>
                    <p class="ls-hint">{{ __('app.booking.duration_type.starts_now_note') }}</p>
                </section>

                <button type="submit" class="ls-btn ls-btn--primary ls-btn--block">{{ __('app.booking.duration_type.open') }}</button>
            </div>

            {{-- Profiles per exclusive room, for the plain select above — no new endpoint needed. --}}
            <script id="open-room-profiles-data" type="application/json">{!! $exclusiveRooms->mapWithKeys(fn ($r) => [$r->id => $r->activePricingProfiles->map(fn ($p) => ['id' => $p->id, 'label' => $p->name.' — '.$p->rateLabel()])->values()])->toJson() !!}</script>
        @endunless

        <details class="ls-plain-section ls-notes-details">
            <summary class="ls-section-label">{{ __('app.placeholder.notes_optional') }}</summary>
            <textarea name="notes" rows="2" class="ls-textarea" placeholder="{{ __('app.placeholder.special_requests') }}">{{ $initialNotes }}</textarea>
            @error('notes') <span class="ls-error"><x-ui.icon name="alert" />{{ $message }}</span> @enderror
        </details>
    </form>

    {{-- 5. Confirmation --}}
    @include('bookings._confirm-summary', ['isEdit' => $isEdit])
</div>

<script>
(function () {
    const form          = document.getElementById('booking-form');
    const dateInput     = document.getElementById('booking_date');
    const startHidden   = document.getElementById('start_time');
    const endHidden     = document.getElementById('end_time');
    const startTrigger  = document.getElementById('start_time-trigger');
    const endTrigger    = document.getElementById('end_time-trigger');
    const startText     = document.getElementById('start_time-trigger-text');
    const endText       = document.getElementById('end_time-trigger-text');
    const startGrid     = document.getElementById('start_time-grid');
    const endGrid       = document.getElementById('end_time-grid');
    const startOptions  = JSON.parse(document.getElementById('start_time-pop').dataset.options);
    const endOptions    = JSON.parse(document.getElementById('end_time-pop').dataset.options);
    const startValues   = Object.keys(startOptions);
    const customEndField = document.getElementById('custom-end-field');
    const endsAtHint    = document.getElementById('ends-at-hint');
    const reservationResult = document.getElementById('reservation-result');
    const durationChips = [...document.querySelectorAll('[data-duration-chip]')];
    const roomGrid      = document.getElementById('room-grid');
    const roomCards     = [...document.querySelectorAll('[data-room-card]')];
    const amountInput   = document.getElementById('f-amount_paid');
    const payPresets    = [...document.querySelectorAll('[data-pay-preset]')];
    const payCustomBtn  = document.getElementById('pay-custom-focus');
    const payStatusText = document.getElementById('pay-status-text');
    const paymentSection = document.getElementById('payment-section');
    const endPlaceholder = @json(__('app.common.select').' '.__('app.booking.end_time'));
    const roomStateLabels = @json($roomStateLabels);
    const paymentStatusLabels = @json($paymentStatusLabels);
    const ctaTemplate = @json($isEdit ? __('app.booking.confirm.cta_edit') : __('app.booking.confirm.cta'));
    const toHours = @json(__('app.common.hours'));
    const endsAtTemplate = @json(__('app.booking.stepper.ends_at'));
    const arrowSep = @json(' → ');

    // Selected duration, in minutes — null once the user picks an explicit
    // End time directly (Custom), so Start-stepper nudges stop silently
    // recomputing an End the user chose on purpose.
    let durationMinutes = null;
    let lastRoomOptions = [];
    let fullDayWindow = null; // {start, end} of the date's business day, from room-options
    const guestInput = document.getElementById('guest_count');
    const planInput = document.getElementById('room_plan_id');
    const planChoice = document.getElementById('plan-choice');
    const planOptionsBox = document.getElementById('plan-options');
    const profileInput = document.getElementById('room_pricing_profile_id');
    const PT = @json($planI18n);

    function addMinutes(hm, mins) {
        const [h, m] = hm.split(':').map(Number);
        const total = h * 60 + m + mins;
        return String(Math.floor(total / 60)).padStart(2, '0') + ':' + String(total % 60).padStart(2, '0');
    }

    function diffMinutes(startHm, endHm) {
        const [sh, sm] = startHm.split(':').map(Number);
        const [eh, em] = endHm.split(':').map(Number);
        return (eh * 60 + em) - (sh * 60 + sm);
    }

    function setChecked(grid, value) {
        grid.querySelectorAll('.ls-time-cell[role="menuitemradio"]').forEach(cell => {
            cell.setAttribute('aria-checked', cell.dataset.value === value ? 'true' : 'false');
        });
    }

    function rebuildEndGrid(startValue) {
        const currentEnd = endHidden.value;
        const keepEnd = currentEnd !== '' && (!startValue || currentEnd > startValue);
        endGrid.innerHTML = Object.entries(endOptions).map(([value, label]) => {
            if (startValue && value <= startValue) {
                return `<span class="ls-time-cell is-disabled" aria-hidden="true">${label}</span>`;
            }
            const checked = keepEnd && value === currentEnd ? 'true' : 'false';
            return `<button type="button" role="menuitemradio" aria-checked="${checked}" class="ls-time-cell" data-value="${value}">${label}</button>`;
        }).join('');
        if (!keepEnd && currentEnd !== '') {
            endHidden.value = '';
            endText.textContent = endPlaceholder;
            endText.classList.add('is-placeholder');
        }
    }

    function setEnd(value) {
        endHidden.value = value;
        endText.textContent = endOptions[value] ?? value;
        endText.classList.remove('is-placeholder');
        rebuildEndGrid(startHidden.value);
        setChecked(endGrid, value);
    }

    function refreshStepperState() {
        const idx = startValues.indexOf(startHidden.value);
        document.querySelectorAll('[data-stepper-for="start_time"]').forEach(btn => {
            const dir = parseInt(btn.dataset.stepperDir, 10);
            btn.disabled = dir < 0 ? idx <= 0 : (idx === -1 || idx >= startValues.length - 1);
        });
    }

    function refreshDurationChips() {
        const startValue = startHidden.value;
        const endValue = endHidden.value;
        durationChips.forEach(chip => {
            chip.classList.remove('is-active');
            if (chip.dataset.minutes === 'fullday') {
                const fd = fullDayWindow;
                const usable = !!fd && Object.prototype.hasOwnProperty.call(startOptions, fd.start) && Object.prototype.hasOwnProperty.call(endOptions, fd.end);
                chip.hidden = !usable;
                chip.disabled = !usable;
                chip.classList.toggle('is-active', usable && startValue === fd.start && endValue === fd.end);
                return;
            }
            if (chip.dataset.minutes === 'custom') {
                chip.classList.toggle('is-active', durationMinutes === null && !!endValue);
                chip.disabled = !startValue;
                return;
            }
            if (!startValue) { chip.disabled = true; return; }
            const mins = parseInt(chip.dataset.minutes, 10);
            const computedEnd = addMinutes(startValue, mins);
            const valid = Object.prototype.hasOwnProperty.call(endOptions, computedEnd);
            chip.disabled = !valid;
            if (valid && computedEnd === endValue) chip.classList.add('is-active');
        });
    }

    function refreshReservationReadouts() {
        const start = startHidden.value, end = endHidden.value;
        endsAtHint.textContent = end ? endsAtTemplate.replace(':time', endOptions[end] ?? end) : '';
        reservationResult.textContent = (start && end)
            ? `${startOptions[start] ?? start}${arrowSep}${endOptions[end] ?? end} · ${durationLabel(diffMinutes(start, end))}`
            : '';
    }

    function applyDuration(mins) {
        if (!startHidden.value) return;
        const computedEnd = addMinutes(startHidden.value, mins);
        if (!Object.prototype.hasOwnProperty.call(endOptions, computedEnd)) return;
        durationMinutes = mins;
        customEndField.style.display = 'none';
        setEnd(computedEnd);
        refreshDurationChips();
        refreshReservationReadouts();
        fetchRoomOptions();
    }

    function selectStart(value) {
        startHidden.value = value;
        startText.textContent = startOptions[value] ?? value;
        startText.classList.remove('is-placeholder');
        setChecked(startGrid, value);
        if (startTrigger.getAttribute('aria-expanded') === 'true') startTrigger.click();
        refreshStepperState();

        if (durationMinutes !== null) {
            applyDuration(durationMinutes);
            return;
        }

        rebuildEndGrid(value);
        refreshDurationChips();
        refreshReservationReadouts();
        fetchRoomOptions();
    }

    startGrid.addEventListener('click', (e) => {
        const btn = e.target.closest('.ls-time-cell');
        if (!btn || btn.tagName !== 'BUTTON') return;
        selectStart(btn.dataset.value);
    });

    endGrid.addEventListener('click', (e) => {
        const btn = e.target.closest('.ls-time-cell');
        if (!btn || btn.tagName !== 'BUTTON') return;
        durationMinutes = null; // an explicit End breaks the duration-follows-start link until a chip is picked again
        setEnd(btn.dataset.value);
        refreshDurationChips();
        refreshReservationReadouts();
        fetchRoomOptions();
        if (endTrigger.getAttribute('aria-expanded') === 'true') endTrigger.click();
    });

    document.querySelectorAll('[data-stepper-dir]').forEach(btn => {
        btn.addEventListener('click', () => {
            const idx = startValues.indexOf(startHidden.value);
            const dir = parseInt(btn.dataset.stepperDir, 10);
            const nextIdx = (idx === -1 ? 0 : idx) + dir;
            if (nextIdx < 0 || nextIdx >= startValues.length) return;
            selectStart(startValues[nextIdx]);
        });
    });

    durationChips.forEach(chip => {
        chip.addEventListener('click', () => {
            if (chip.disabled) return;
            if (chip.dataset.minutes === 'fullday') {
                // The whole business day: lands exactly on the Full Day price.
                durationMinutes = null;
                customEndField.style.display = 'none';
                startHidden.value = fullDayWindow.start;
                startText.textContent = startOptions[fullDayWindow.start] ?? fullDayWindow.start;
                startText.classList.remove('is-placeholder');
                setChecked(startGrid, fullDayWindow.start);
                refreshStepperState();
                setEnd(fullDayWindow.end);
                refreshDurationChips();
                refreshReservationReadouts();
                fetchRoomOptions();
                return;
            }
            if (chip.dataset.minutes === 'custom') {
                durationMinutes = null;
                customEndField.style.display = '';
                refreshDurationChips();
                endTrigger.click();
                return;
            }
            applyDuration(parseInt(chip.dataset.minutes, 10));
        });
    });

    // --- Room cards ---

    function selectedRoomId() {
        const checked = roomGrid.querySelector('input[name="room_id"]:checked');
        return checked ? checked.value : null;
    }

    function applyRoomOption(card, opt, durationText) {
        if (card.dataset.shared === 'true') return; // shared rooms keep their static "Open Session" note
        const priceEl = card.querySelector('[data-room-price] .ls-room-card-price-value');
        const durationEl = card.querySelector('[data-room-price] .ls-room-card-price-duration');
        const stateTextEl = card.querySelector('[data-room-state-text]');
        const checkEl = card.querySelector('[data-room-check]');
        priceEl.textContent = opt ? opt.total_price_display : ' ';
        durationEl.textContent = opt && durationText ? durationText : '';
        card.classList.toggle('is-unavailable', !!opt && opt.state === 'unavailable');
        if (opt) {
            card.dataset.state = opt.state;
            stateTextEl.textContent = roomStateLabels[opt.state] || '';
            if (checkEl) checkEl.hidden = opt.state !== 'free';
        }
    }

    function updateRoomSelectedClasses() {
        const current = selectedRoomId();
        roomCards.forEach(card => card.classList.toggle('is-selected', card.dataset.roomId === current));
    }

    roomGrid.addEventListener('change', () => {
        planInput.value = ''; // plans belong to one room
        profileInput.value = ''; // …and so do pricing profiles — never kept across rooms
        updateRoomSelectedClasses();
        renderPlanChoice();
        updateSummaryAndPayment();
    });

    // Picking a length or an end time by hand means standard pricing.
    durationChips.forEach(chip => chip.addEventListener('click', () => { if (planInput.value) { planInput.value = ''; renderPlanChoice(); updateSummaryAndPayment(); } }, true));
    endGrid.addEventListener('click', () => { if (planInput.value) { planInput.value = ''; renderPlanChoice(); updateSummaryAndPayment(); } }, true);

    // --- Custom Plans ---
    function rawRoomOption() {
        const id = selectedRoomId();
        return id ? lastRoomOptions.find(o => String(o.id) === String(id)) : null;
    }
    function currentPlan() {
        const opt = rawRoomOption();
        return opt && planInput.value ? (opt.plans || []).find(p => String(p.id) === String(planInput.value)) || null : null;
    }
    function currentProfile() {
        const opt = rawRoomOption();
        return opt && profileInput.value ? (opt.profiles || []).find(p => String(p.id) === String(profileInput.value)) || null : null;
    }
    function planWhy(p) {
        if (!p.fits_people) return p.people === 1 ? PT.forPeople1 : PT.forPeopleN.replace(':count', p.people);
        if (p.state === 'booked') return PT.booked;
        if (p.state === 'no_fit') return PT.noFit;
        if (p.state === 'outside_hours') return PT.closed;
        return '';
    }
    function applyPlanWindow(p) {
        durationMinutes = null;
        if (startHidden.value !== p.start_time) {
            startHidden.value = p.start_time;
            startText.textContent = startOptions[p.start_time] ?? p.start_time;
            startText.classList.remove('is-placeholder');
            setChecked(startGrid, p.start_time);
            refreshStepperState();
        }
        setEnd(p.end_time);
        customEndField.style.display = '';
        refreshDurationChips();
        refreshReservationReadouts();
    }
    function renderPlanChoice() {
        const opt = rawRoomOption();
        const plans = opt ? opt.plans || [] : [];
        const profiles = opt ? opt.profiles || [] : [];
        planChoice.hidden = !plans.length && !profiles.length;
        planOptionsBox.innerHTML = '';
        if (!plans.length) planInput.value = '';
        if (opt && profileInput.value && !currentProfile()) profileInput.value = ''; // inactive / another room's
        if (!plans.length && !profiles.length) return;

        const chosen = currentPlan();
        if (chosen && planWhy(chosen)) planInput.value = '';
        const keep = currentPlan();
        if (keep && (keep.start_time !== startHidden.value || keep.end_time !== endHidden.value)) { applyPlanWindow(keep); fetchRoomOptions(); }

        // One list, one choice: Default · a pricing profile · a Custom Plan.
        const add = (id, title, sub, price, why, kind = 'plan') => {
            const on = kind === 'profile'
                ? String(profileInput.value || '') === String(id)
                : !profileInput.value && String(planInput.value || '') === String(id || '');
            const el = document.createElement('label');
            el.className = 'ls-plan-opt' + (on ? ' is-selected' : '') + (why ? ' is-off' : '');
            el.innerHTML = '<input type="radio" name="plan-choice"><span class="ls-plan-opt-main"><b></b><small></small></span><span class="ls-plan-opt-price"></span>';
            const input = el.querySelector('input');
            input.checked = on; input.disabled = !!why;
            el.querySelector('b').textContent = title;
            el.querySelector('small').textContent = sub;
            if (why) { const w = document.createElement('span'); w.className = 'ls-plan-why'; w.textContent = why; el.querySelector('.ls-plan-opt-main').appendChild(w); }
            el.querySelector('.ls-plan-opt-price').textContent = price;
            input.addEventListener('change', () => {
                if (kind === 'profile') { profileInput.value = id; planInput.value = ''; renderPlanChoice(); updateSummaryAndPayment(); fetchRoomOptions(); return; }
                profileInput.value = '';
                planInput.value = id || '';
                const p = currentPlan();
                if (p) { applyPlanWindow(p); fetchRoomOptions(); }
                renderPlanChoice();
                updateSummaryAndPayment();
            });
            planOptionsBox.appendChild(el);
        };
        add(null, profiles.length ? PT.defaultLabel : PT.standard, opt.price_note || opt.price_summary || '', opt.total_price_display, '');
        profiles.forEach(p => add(p.id, p.name, p.rate_display, p.total_price_display, '', 'profile'));
        plans.forEach(p => add(p.id, p.name, p.people_label + ' · ' + p.duration_label, p.price_display, planWhy(p)));
    }
    updateRoomSelectedClasses();

    // --- Room availability/pricing (replaces the old checkAvailability() call) ---

    let roomOptionsToken = 0;

    // Date-only lookup: the business day behind the "Full day" chip.
    function fetchFullDay() {
        if (!dateInput.value) return;
        fetch(`/bookings/room-options?${new URLSearchParams({ booking_date: dateInput.value })}`)
            .then(r => r.json())
            .then(data => { fullDayWindow = data.full_day || null; refreshDurationChips(); })
            .catch(() => {});
    }

    function fetchRoomOptions() {
        const date = dateInput.value;
        const start = startHidden.value;
        const end = endHidden.value;
        if (!date || !start || !end) return;

        const params = new URLSearchParams({ booking_date: date, start_time: start, end_time: end, guest_count: guestInput.value || 1 });
        @if ($isEdit)
            params.set('booking_id', '{{ $booking->id }}');
        @endif

        const token = ++roomOptionsToken;
        fetch(`/bookings/room-options?${params.toString()}`)
            .then(r => r.json())
            .then(data => {
                if (token !== roomOptionsToken) return; // a newer request already landed
                lastRoomOptions = data.rooms || [];
                fullDayWindow = data.full_day || null;
                refreshDurationChips();
                const durationText = (start && end) ? durationLabel(diffMinutes(start, end)) : '';
                lastRoomOptions.forEach(opt => {
                    const card = roomGrid.querySelector(`[data-room-card][data-room-id="${opt.id}"]`);
                    if (card) applyRoomOption(card, opt, durationText);
                });
                renderPlanChoice();
                updateSummaryAndPayment();
            })
            .catch(() => {});
    }

    // The option the summary/payment use: a chosen Custom Plan's fixed price
    // replaces the standard quote (both come from the server's room-options).
    function currentRoomOption() {
        const opt = rawRoomOption();
        const plan = currentPlan();
        const profile = currentProfile();
        if (opt && profile) return { ...opt, total_price: profile.total_price, total_price_display: profile.total_price_display, price_note: profile.note };
        return opt && plan ? { ...opt, total_price: plan.price, total_price_display: plan.price_display, price_note: plan.note } : opt;
    }

    // --- Payment ---

    function paymentStatusFor(paid, total) {
        if (total <= 0) return paid > 0 ? 'paid' : 'unpaid';
        if (paid >= total) return 'paid';
        return paid > 0 ? 'partial' : 'unpaid';
    }

    function setBadge(el, tone, text) {
        el.textContent = text;
        el.className = el.className.replace(/\bls-badge--\S+/, '').trim();
        el.classList.add('ls-badge--' + tone);
    }

    function setStatusText(el, tone, text) {
        el.textContent = text;
        el.className = el.className.replace(/\bls-status--\S+/, '').trim();
        el.classList.add('ls-status--' + tone);
    }

    const toneFor = { paid: 'ok', partial: 'warn', unpaid: 'neutral' };

    // --- Hour packages (Normal payment · Use hour package) ---
    // The server decides eligibility (/bookings/package-options) and re-checks
    // everything on submit; this only renders the choice.
    const pkgBox = document.getElementById('pkg-pay');
    const pkgInput = document.getElementById('f-member_package_id');
    const pkgOptionsBox = document.getElementById('pkg-options');
    const pkgNeeds = document.getElementById('pkg-needs');
    const pkgCovered = document.getElementById('pkg-covered');
    const PK = @json($pkgI18n);
    let pkgList = [], pkgMinutesLabel = null, pkgToken = 0;
    const payMode = () => (document.querySelector('[data-pay-mode]:checked') || {}).value || 'normal';
    function pkgChosen() {
        return payMode() === 'package' ? pkgList.find(p => String(p.id) === String(pkgInput.value) && p.eligible) || null : null;
    }
    function fetchPackages() {
        const uid = document.getElementById('selected-user-id').value;
        if (!uid) { pkgList = []; renderPackages(); updateSummaryAndPayment(); return; }
        const params = new URLSearchParams({ hotspot_user_id: uid, guest_count: guestInput.value || 1 });
        if (selectedRoomId()) params.set('room_id', selectedRoomId());
        if (dateInput.value) params.set('booking_date', dateInput.value);
        if (startHidden.value) params.set('start_time', startHidden.value);
        if (endHidden.value) params.set('end_time', endHidden.value);
        if (planInput.value) params.set('room_plan_id', planInput.value);
        @if ($isEdit)
            params.set('booking_id', '{{ $booking->id }}');
        @endif
        const token = ++pkgToken;
        fetch(`/bookings/package-options?${params}`, { headers: { Accept: 'application/json' } })
            .then(r => r.ok ? r.json() : { packages: [] })
            .then(d => {
                if (token !== pkgToken) return;
                pkgList = d.packages || [];
                pkgMinutesLabel = d.minutes_label;
                renderPackages();
                updateSummaryAndPayment();
            })
            .catch(() => {});
    }
    function renderPackages() {
        pkgBox.hidden = !pkgList.length;
        if (!pkgList.length) { pkgInput.value = ''; return; }
        const usePkg = payMode() === 'package';
        pkgOptionsBox.hidden = !usePkg;
        pkgNeeds.hidden = !usePkg;
        pkgNeeds.textContent = pkgMinutesLabel ? PK.needs.replace(':time', pkgMinutesLabel) : PK.pickSlot;
        if (!usePkg) { pkgInput.value = ''; return; }
        // Keep the chosen package while it's still eligible; otherwise pick the first that is.
        if (!pkgChosen()) { const first = pkgList.find(p => p.eligible); pkgInput.value = first ? first.id : ''; }
        pkgOptionsBox.innerHTML = '';
        pkgList.forEach(p => {
            const el = document.createElement('label');
            el.className = 'ls-pkg-opt' + (p.eligible ? '' : ' is-disabled');
            el.innerHTML = '<input type="radio" name="pkg-choice"><span class="ls-pkg-opt-main"><span class="ls-pkg-opt-name"></span><span class="ls-pkg-opt-meta"></span></span><span class="ls-pkg-opt-left"></span>';
            const input = el.querySelector('input');
            input.disabled = !p.eligible;
            input.checked = String(pkgInput.value) === String(p.id);
            el.querySelector('.ls-pkg-opt-name').textContent = p.name;
            el.querySelector('.ls-pkg-opt-meta').textContent = PK.expires.replace(':date', p.expires_label);
            el.querySelector('.ls-pkg-opt-left').textContent = PK.left.replace(':time', p.remaining_label);
            if (p.reason) { const w = document.createElement('span'); w.className = 'ls-pkg-opt-reason'; w.textContent = p.reason; el.querySelector('.ls-pkg-opt-main').appendChild(w); }
            input.addEventListener('change', () => { pkgInput.value = p.id; updateSummaryAndPayment(); });
            pkgOptionsBox.appendChild(el);
        });
    }
    document.querySelectorAll('[data-pay-mode]').forEach(r => r.addEventListener('change', () => { renderPackages(); updateSummaryAndPayment(); }));
    let pkgTimer = null;
    const schedulePackages = () => { clearTimeout(pkgTimer); pkgTimer = setTimeout(fetchPackages, 150); };

    function updateSummaryAndPayment() {
        const opt = currentRoomOption();
        // The selected room card shows what will actually be charged (plan price when a plan is chosen).
        const selCard = roomGrid.querySelector('[data-room-card].is-selected');
        if (selCard && opt && selCard.dataset.shared !== 'true') {
            selCard.querySelector('[data-room-price] .ls-room-card-price-value').textContent = opt.total_price_display;
        }
        const total = opt ? opt.total_price : 0;
        const isShared = opt ? opt.is_shared : false;
        const pkg = opt && !isShared ? pkgChosen() : null;

        paymentSection.style.display = (opt && !isShared) ? '' : 'none';
        // A package covers the whole booking: hide the deposit inputs.
        document.getElementById('pay-deposit').hidden = !!pkg;
        pkgCovered.hidden = !pkg;
        if (pkg) pkgCovered.querySelector('span').textContent = PK.covered.replace(':time', pkgMinutesLabel || '');
        document.getElementById('pay-shared-note').hidden = !(opt && isShared);

        let paid = 0;
        if (opt && !isShared) {
            paid = Math.max(0, Math.min(parseFloat(amountInput.value || '0') || 0, total));
        }
        const remaining = Math.max(0, total - paid);
        const status = opt ? paymentStatusFor(paid, total) : 'unpaid';

        document.getElementById('pay-total').textContent = opt ? opt.total_price_display : '—';
        document.getElementById('pay-remaining').textContent = opt ? formatMoney(remaining) : '—';
        if (payStatusText) setStatusText(payStatusText, toneFor[status], opt ? paymentStatusLabels[status] : '—');

        payPresets.forEach(btn => {
            btn.disabled = !opt || isShared;
            btn.classList.toggle('is-active', !!opt && Math.abs(parseFloat(amountInput.value || '0') - Math.round(total * parseFloat(btn.dataset.payPreset) * 100) / 100) < 0.005);
        });
        if (payCustomBtn) payCustomBtn.disabled = !opt || isShared;

        // --- Confirmation summary (the one place totals/paid/remaining/status are spelled out) ---
        const customerDisplay = document.getElementById('selected-user-display');
        document.getElementById('confirm-customer').textContent = (customerDisplay && !customerDisplay.classList.contains('hidden'))
            ? document.getElementById('selected-user-name').textContent : '—';
        document.getElementById('confirm-room').textContent = opt ? roomNameFor(opt.id) : '—';
        document.getElementById('confirm-datetime').textContent = dateInput.value
            ? `${formatDate(dateInput.value)}${(startHidden.value && endHidden.value) ? ' · ' + startOptions[startHidden.value] + arrowSep + endOptions[endHidden.value] : ''}`
            : '—';
        document.getElementById('confirm-duration').textContent = (startHidden.value && endHidden.value)
            ? durationLabel(diffMinutes(startHidden.value, endHidden.value)) : '—';
        document.getElementById('confirm-total').textContent = opt ? opt.total_price_display : '—';
        const pricingRow = document.getElementById('confirm-pricing-row');
        pricingRow.hidden = !(opt && opt.price_note);
        document.getElementById('confirm-pricing').textContent = opt && opt.price_note ? opt.price_note : '';
        document.getElementById('confirm-paid').textContent = opt ? formatMoney(paid) : '—';
        document.getElementById('confirm-remaining').textContent = opt ? formatMoney(remaining) : '—';
        setBadge(document.getElementById('confirm-status-badge'), toneFor[status], paymentStatusLabels[status]);

        const cta = document.getElementById('confirm-cta');
        cta.textContent = ctaTemplate.replace(':total', opt ? opt.total_price_display : '—');

        if (pkg) {
            document.getElementById('confirm-total').textContent = PK.covered.replace(':time', pkgMinutesLabel || '');
            pricingRow.hidden = false;
            document.getElementById('confirm-pricing').textContent = pkg.name;
            document.getElementById('confirm-paid').textContent = '—';
            document.getElementById('confirm-remaining').textContent = formatMoney(0);
            setBadge(document.getElementById('confirm-status-badge'), 'info', PK.coveredLabel);
            cta.textContent = PK.cta;
        }
    }

    function roomNameFor(id) {
        const card = roomGrid.querySelector(`[data-room-card][data-room-id="${id}"] .ls-room-card-name`);
        return card ? card.textContent : '—';
    }

    function formatMoney(n) {
        const s = (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return document.documentElement.dir === 'rtl' ? `${s} ج.م` : `EGP ${s}`;
    }

    function formatDate(ymd) {
        const d = new Date(ymd + 'T00:00:00');
        return d.toLocaleDateString(document.documentElement.lang === 'ar' ? 'ar-EG' : 'en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function durationLabel(mins) {
        const h = Math.floor(mins / 60), m = mins % 60;
        const parts = [];
        if (h) parts.push(h + '{{ __('app.ui.unit_h') }}');
        if (m) parts.push(m + '{{ __('app.ui.unit_m') }}');
        return parts.join(' ') || ('0' + toHours);
    }

    amountInput.addEventListener('input', () => {
        const opt = currentRoomOption();
        if (opt) {
            const clamped = Math.max(0, Math.min(parseFloat(amountInput.value || '0') || 0, opt.total_price));
            if (String(clamped) !== amountInput.value) amountInput.value = clamped;
        }
        updateSummaryAndPayment();
    });

    payPresets.forEach(btn => {
        btn.addEventListener('click', () => {
            const opt = currentRoomOption();
            if (!opt) return;
            amountInput.value = Math.round(opt.total_price * parseFloat(btn.dataset.payPreset) * 100) / 100;
            updateSummaryAndPayment();
        });
    });

    if (payCustomBtn) {
        payCustomBtn.addEventListener('click', () => {
            if (payCustomBtn.disabled) return;
            amountInput.focus();
            amountInput.select();
        });
    }

    dateInput.addEventListener('change', () => {
        updateSummaryAndPayment();
        fetchFullDay();
        fetchRoomOptions();
    });

    // People → re-quote every room (people-based rooms change price).
    let guestTimer = null;
    function guestsChanged() {
        clearTimeout(guestTimer);
        guestTimer = setTimeout(fetchRoomOptions, 200);
    }
    guestInput.addEventListener('input', guestsChanged);
    document.querySelectorAll('[data-guest-step]').forEach(btn => {
        btn.addEventListener('click', () => {
            const next = Math.min(999, Math.max(1, (parseInt(guestInput.value, 10) || 1) + parseInt(btn.dataset.guestStep, 10)));
            guestInput.value = next;
            guestsChanged();
        });
    });

    // member-picker.blade.php defines these globals in its own inline script,
    // which runs before this one (it's included earlier in the same form) —
    // wrapped rather than edited, so the picker's own logic stays untouched.
    const baseSelectUser = window.selectUser;
    window.selectUser = function (...args) {
        baseSelectUser(...args);
        updateSummaryAndPayment();
        schedulePackages();
    };
    const baseClearUserSelection = window.clearUserSelection;
    window.clearUserSelection = function (...args) {
        baseClearUserSelection(...args);
        updateSummaryAndPayment();
        schedulePackages();
    };

    // Re-check package eligibility whenever the slot changes (room, date,
    // time, people, plan) — the needed minutes come from the server's quote.
    roomGrid.addEventListener('change', schedulePackages);
    dateInput.addEventListener('change', schedulePackages);
    guestInput.addEventListener('input', schedulePackages);
    document.querySelectorAll('[data-guest-step]').forEach(b => b.addEventListener('click', schedulePackages));
    const baseFetchRoomOptions = fetchRoomOptions;
    fetchRoomOptions = function () { baseFetchRoomOptions(); schedulePackages(); };
    planOptionsBox.addEventListener('change', schedulePackages);

    // --- Initial state ---
    refreshStepperState();
    refreshDurationChips();
    refreshReservationReadouts();
    if (startHidden.value && endHidden.value) {
        durationMinutes = diffMinutes(startHidden.value, endHidden.value);
        // Only treat it as a "known" duration chip if it actually matches one;
        // otherwise this is a Custom-picked range — show the End picker
        // directly rather than a chip that can't represent it.
        if (!durationChips.some(c => c.dataset.minutes === String(durationMinutes))) {
            durationMinutes = null;
            customEndField.style.display = '';
        }
    }
    if (dateInput.value && startHidden.value && endHidden.value) {
        fetchRoomOptions();
    } else {
        fetchFullDay();
        updateSummaryAndPayment();
        schedulePackages();
    }
})();
</script>

@unless ($isEdit)
    <script>
    (function () {
        const choice = document.getElementById('duration-type-choice');
        if (!choice) return;

        const fixedSection = document.getElementById('fixed-time-sections');
        const openSection = document.getElementById('open-session-section');
        const confirmSummary = document.getElementById('confirm-summary');
        const bookingDate = document.getElementById('booking_date');
        const roomRadios = document.querySelectorAll('#room-grid input[name="room_id"]');
        const roomSelect = document.getElementById('open-room-select');
        const profileField = document.getElementById('open-profile-field');
        const profileSelect = document.getElementById('open-profile-select');
        const profilesByRoom = JSON.parse(document.getElementById('open-room-profiles-data').textContent || '{}');

        function setMode(open) {
            fixedSection.style.display = open ? 'none' : '';
            openSection.hidden = !open;
            if (confirmSummary) confirmSummary.style.display = open ? 'none' : '';

            // display:none exempts a subtree from constraint validation, but
            // explicitly toggling required/disabled too avoids relying on
            // that alone across browsers.
            if (bookingDate) bookingDate.required = ! open;
            roomRadios.forEach(r => { r.disabled = open; if (open) r.required = false; });
            roomSelect.required = open;
            // Only the active mode's fields may be posted: these share names with the
            // fixed-time room cards / pricing-profile input.
            roomSelect.disabled = ! open;
            profileSelect.disabled = ! open;

            choice.querySelectorAll('[data-duration-opt]').forEach(label => {
                label.classList.toggle('is-selected', label.dataset.durationOpt === (open ? 'open' : 'fixed'));
            });
        }

        choice.querySelectorAll('input[name="duration_type"]').forEach(input => {
            input.addEventListener('change', () => setMode(input.value === 'open'));
        });

        roomSelect.addEventListener('change', () => {
            const options = profilesByRoom[roomSelect.value] || [];
            profileSelect.innerHTML = '<option value="">'+@json(__('app.pricing_profiles.default_option'))+'</option>'
                + options.map(p => `<option value="${p.id}">${p.label.replace(/</g, '&lt;')}</option>`).join('');
            profileField.hidden = options.length === 0;
        });
    })();
    </script>
@endunless
