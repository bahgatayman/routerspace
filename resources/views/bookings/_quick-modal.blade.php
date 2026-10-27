{{--
    EXPERIMENT — Quick booking modal.
    Booking as a quick action: Who → When (+ people) → Which room → How much → Book.
    Opened from any link to /bookings/create (their ?room_id/booking_date/
    start_time/end_time/hotspot_user_id prefill is honoured); ⌘/Ctrl-click and
    "Open the full booking page" still reach the full form, which is unchanged.

    Sensible defaults do most of the work: today, the next half hour, 1 hour.
    Rooms appear with their live price for exactly that choice, unavailable
    ones say why. Payment (No / Partial / Full) and the note are always on
    screen — nothing to expand. There is one total and
    one button — no separate summary.

    Nothing is priced or decided here: prices + availability come from
    GET /bookings/room-options (RoomPricingService / AvailabilityService),
    and the booking is created by the normal POST /bookings (re-validated).
    Remove the @include in layouts/app.blade.php to switch the experiment off.
--}}
@php
    // Same 06:00–23:30 / 30-minute grid as GeneratesTimeSlots (the full form).
    $qbSlots = [];
    for ($t = \Carbon\Carbon::createFromTime(6, 0); $t->lte(\Carbon\Carbon::createFromTime(23, 30)); $t->addMinutes(30)) {
        $qbSlots[$t->format('H:i')] = $t->format('h:i A');
    }
    $qbI18n = [
        'hour1' => trans_choice('app.pricing.hours', 1, ['count' => 1]),
        'hoursN' => trans_choice('app.pricing.hours', 2, ['count' => ':count']),
        'minutes' => __('app.pricing.minutes', ['count' => ':count']),
        'seat1' => trans_choice('app.quick_booking.seats', 1, ['count' => 1]),
        'seatsN' => trans_choice('app.quick_booking.seats', 2, ['count' => ':count']),
        'bookedThen' => __('app.quick_booking.booked_then'),
        'noSeats' => __('app.quick_booking.no_seats'),
        'closed' => __('app.quick_booking.closed'),
        'loading' => __('app.quick_booking.loading'),
        'noRooms' => __('app.quick_booking.no_rooms'),
        'noResults' => __('app.quick_booking.no_results'),
        'addNamed' => __('app.quick_booking.add_customer_named', ['name' => ':name']),
        'addCustomer' => __('app.quick_booking.add_customer'),
        'needCustomer' => __('app.quick_booking.need_customer'),
        'needRoom' => __('app.quick_booking.need_room'),
        'needTime' => __('app.quick_booking.need_time'),
        'cta' => __('app.quick_booking.cta', ['total' => ':total']),
        'ctaIdle' => __('app.quick_booking.cta_idle'),
        'view' => __('app.quick_booking.view'),
        'error' => __('app.quick_booking.error'),
        'sessionLost' => __('app.quick_booking.session_lost'),
        'arrow' => ' → ',
        'standard' => __('app.plans.standard'),
        'forPeople1' => trans_choice('app.plans.for_people', 1, ['count' => 1]),
        'forPeopleN' => trans_choice('app.plans.for_people', 2, ['count' => ':count']),
        'planBooked' => __('app.plans.booked_then'),
        'planNoFit' => __('app.plans.no_fit'),
        'planClosed' => __('app.quick_booking.closed'),
        'plans1' => trans_choice('app.plans.count', 1, ['count' => 1]),
        'plansN' => trans_choice('app.plans.count', 2, ['count' => ':count']),
        'defaultLabel' => __('app.pricing_profiles.default_label'),
        'pkgNeeds' => __('app.packages.needs'),
        'pkgExpires' => __('app.packages.expires_short'),
        'pkgLeft' => __('app.packages.left'),
        'pkgCovered' => __('app.packages.covered_total'),
        'pkgCta' => __('app.packages.cta_covered'),
        'choiceRequired' => __('app.booking.payment.choice_required'),
        'partialRequired' => __('app.booking.payment.partial_required'),
        'exceedsTotal' => __('app.booking.payment.exceeds_total', ['total' => ':total']),
        'payStatus' => ['paid' => __('app.booking.payment.status_paid'), 'partial' => __('app.booking.payment.status_partial'), 'unpaid' => __('app.booking.payment.status_unpaid')],
        'repeatCta' => __('app.series.review_button'),
        'total' => __('app.quick_booking.total'),
        'perDate' => __('app.series.per_date_price'),
        'repeatEstimate' => __('app.series.series_estimate', ['count' => ':count', 'total' => ':total']),
        'repeatNoPlan' => __('app.series.errors.no_plans'),
        'repeatNoDays' => __('app.series.errors.no_dates'),
    ];
    $qbCanAddMember = ! ($actingStaff ?? null) || $actingStaff->hasPermission('members.create');
@endphp
<x-ui.modal id="quick-book" :title="__('app.quick_booking.title')" size="wide" class="ls-qb">
    {{-- Repeating: two steps in this one dialog — the details, then review and confirm. --}}
    <ol class="ls-flow-steps ls-qb-steps" id="qb-steps" aria-label="{{ __('app.series.steps_label') }}" hidden>
        <li aria-current="step" data-qb-step="1"><span aria-hidden="true">1</span>{{ __('app.series.step_details') }}</li>
        <li data-qb-step="2"><span aria-hidden="true">2</span>{{ __('app.series.step_review') }}</li>
    </ol>
    <form id="qb-form" novalidate>
        <div class="ls-banner ls-banner--danger" id="qb-error" role="alert" hidden><x-ui.icon name="alert" /><span></span></div>

        {{-- 1 · Who --}}
        <section class="ls-qb-step" aria-labelledby="qb-who">
            <h4 class="ls-qb-label" id="qb-who"><span class="ls-qb-n" aria-hidden="true">1</span>{{ __('app.quick_booking.customer') }}</h4>

            <div class="ls-qb-picked" id="qb-picked" hidden>
                <span class="ls-avatar ls-avatar--sm" id="qb-picked-avatar" aria-hidden="true"></span>
                <span class="ls-qb-picked-who">
                    <b id="qb-picked-name"></b>
                    <bdi dir="ltr" id="qb-picked-phone" class="ls-faint"></bdi>
                </span>
                <button type="button" class="ls-link" id="qb-change">{{ __('app.quick_booking.change') }}</button>
            </div>

            <div id="qb-find">
                <div class="ls-search">
                    <x-ui.icon name="search" />
                    <input type="text" id="qb-search" class="ls-input" autocomplete="off" spellcheck="false"
                           placeholder="{{ __('app.quick_booking.search') }}" aria-label="{{ __('app.quick_booking.search') }}"
                           role="combobox" aria-expanded="false" aria-controls="qb-results" aria-autocomplete="list">
                </div>
                <ul class="ls-qb-results" id="qb-results" role="listbox" aria-label="{{ __('app.quick_booking.customer') }}" hidden></ul>

                @if ($qbCanAddMember)
                    <div class="ls-qb-newcust" id="qb-newcust" hidden>
                        <input type="text" id="qb-new-name" class="ls-input" placeholder="{{ __('app.user.name') }}" aria-label="{{ __('app.user.name') }}">
                        <input type="text" id="qb-new-phone" class="ls-input" inputmode="tel" dir="ltr" placeholder="{{ __('app.user.phone') }}" aria-label="{{ __('app.user.phone') }}">
                        <button type="button" class="ls-btn ls-btn--tonal" id="qb-new-add">{{ __('app.quick_booking.add') }}</button>
                        <p class="ls-error" id="qb-new-error" hidden></p>
                    </div>
                @endif
            </div>
        </section>

        {{-- 2 · When (+ people, when a room prices by people) --}}
        <section class="ls-qb-step" aria-labelledby="qb-when">
            <h4 class="ls-qb-label" id="qb-when"><span class="ls-qb-n" aria-hidden="true">2</span>{{ __('app.quick_booking.when') }}</h4>

            {{-- Repeating: the shortcuts step aside and the date reads as the series' start. --}}
            <label class="ls-qb-field-label" for="qb-date" id="qb-start-date-label" hidden>{{ __('app.series.start_date') }}</label>
            <div class="ls-chips" role="group" aria-label="{{ __('app.booking.date') }}">
                <button type="button" class="ls-chip" data-qb-day="0" aria-pressed="false">{{ __('app.quick_booking.today') }}</button>
                <button type="button" class="ls-chip" data-qb-day="1" aria-pressed="false">{{ __('app.quick_booking.tomorrow') }}</button>
                <label class="ls-chip ls-qb-datechip" id="qb-date-chip">
                    <input type="date" id="qb-date" min="{{ now()->format('Y-m-d') }}" aria-label="{{ __('app.quick_booking.other_date') }}">
                </label>
            </div>

            <div class="ls-qb-time">
                <label class="ls-qb-field">
                    <span class="ls-qb-field-label">{{ __('app.quick_booking.starts') }}</span>
                    <select id="qb-start" class="ls-select" dir="ltr">
                        @foreach ($qbSlots as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <div class="ls-qb-field ls-qb-grow">
                    <span class="ls-qb-field-label" id="qb-dur-label">{{ __('app.quick_booking.duration') }}</span>
                    <div class="ls-chips" id="qb-durations" role="group" aria-labelledby="qb-dur-label">
                        @foreach ([60, 120, 180, 240] as $m)
                            <button type="button" class="ls-chip" data-qb-minutes="{{ $m }}" aria-pressed="false"></button>
                        @endforeach
                        <button type="button" class="ls-chip" data-qb-minutes="fullday" aria-pressed="false" hidden>{{ __('app.pricing.full_day') }}</button>
                        <button type="button" class="ls-chip" data-qb-minutes="custom" aria-pressed="false">{{ __('app.quick_booking.custom') }}</button>
                    </div>
                </div>

                <label class="ls-qb-field" id="qb-end-wrap" hidden>
                    <span class="ls-qb-field-label">{{ __('app.quick_booking.ends') }}</span>
                    <select id="qb-end" class="ls-select" dir="ltr"></select>
                </label>

                <div class="ls-qb-field" id="qb-people-wrap" hidden>
                    <label class="ls-qb-field-label" for="qb-people">{{ __('app.quick_booking.people') }}</label>
                    <div class="ls-stepper">
                        <button type="button" data-qb-people="-1" aria-label="{{ __('app.quick_booking.people_less') }}">&minus;</button>
                        <input type="number" id="qb-people" min="1" max="999" value="1" inputmode="numeric">
                        <button type="button" data-qb-people="1" aria-label="{{ __('app.quick_booking.people_more') }}">+</button>
                    </div>
                </div>
            </div>
            <p class="ls-qb-range" id="qb-range" aria-live="polite"></p>
        </section>

        {{-- 3 · Room, priced for exactly this choice --}}
        <section class="ls-qb-step" aria-labelledby="qb-room-label">
            <h4 class="ls-qb-label" id="qb-room-label"><span class="ls-qb-n" aria-hidden="true">3</span>{{ __('app.quick_booking.room') }}</h4>
            <p class="ls-qb-msg" id="qb-room-msg" aria-live="polite"></p>
            <div class="ls-qb-rooms" id="qb-rooms" role="radiogroup" aria-labelledby="qb-room-label"></div>
        </section>

        {{-- 4 · Pricing — only when the chosen room has Custom Plans. --}}
        <section class="ls-qb-step" id="qb-pricing" aria-labelledby="qb-pricing-label" hidden>
            <h4 class="ls-qb-label" id="qb-pricing-label"><span class="ls-qb-n" aria-hidden="true">4</span>{{ __('app.plans.pricing') }}</h4>
            <div class="ls-plan-pick" id="qb-plans" role="radiogroup" aria-labelledby="qb-pricing-label"></div>
        </section>

        {{-- 5 · Payment — only when the customer has hour packages. --}}
        <section class="ls-qb-step" id="qb-pkg" aria-labelledby="qb-pkg-label" hidden>
            <h4 class="ls-qb-label" id="qb-pkg-label"><span class="ls-qb-n" aria-hidden="true" id="qb-pkg-n">5</span>{{ __('app.packages.pay_title') }}</h4>
            <div class="ls-pkg-pay">
                <div class="ls-inv-seg" role="radiogroup" aria-labelledby="qb-pkg-label">
                    <label><input type="radio" name="qb-pay-mode" value="normal" data-qb-pay-mode checked><span>{{ __('app.packages.pay_normal') }}</span></label>
                    <label><input type="radio" name="qb-pay-mode" value="package" data-qb-pay-mode><span>{{ __('app.packages.pay_package') }}</span></label>
                </div>
                <p class="ls-hint" id="qb-pkg-needs" hidden></p>
                <div class="ls-pkg-options" id="qb-pkg-options" role="radiogroup" aria-label="{{ __('app.packages.pick_package') }}" hidden></div>
            </div>
        </section>

        {{--
            Repeat (same fields as the full form). On: Book becomes "Review
            dates", which opens the recurring review step with this choice;
            payment and hour packages step aside (each date is its own unpaid
            booking), and a Custom Plan can't repeat. Off: nothing changes.
        --}}
        @include('bookings._repeat-fields', ['rp' => 'qb-repeat', 'useOld' => false, 'withReview' => false, 'class' => 'ls-qb-step'])

        {{--
            Payment + note: always on screen, nothing to expand. One click picks
            No / Partial / Full; Partial reveals the amount field. The server
            re-derives the amount from payment_choice (BookingController::
            applyPaymentChoice). Hidden for an hour package (it covers the
            booking); shared rooms are billed at checkout, so a note replaces it.
        --}}
        <div class="ls-qb-pay" id="qb-paid-wrap">
            <div class="ls-pay-choices" id="qb-pay-choices" role="radiogroup" aria-label="{{ __('app.booking.payment.choice_label') }}">
                @foreach (['none', 'partial', 'full'] as $choice)
                    <label class="ls-pay-choice">
                        <input type="radio" name="qb-payment-choice" value="{{ $choice }}" data-qb-pay-choice>
                        <span class="ls-pay-choice-body"><span class="ls-pay-choice-title">{{ __('app.booking.payment.choice_'.$choice) }}</span></span>
                    </label>
                @endforeach
            </div>
            <div class="ls-qb-field" id="qb-paid-field" hidden>
                <label class="ls-qb-field-label" for="qb-paid">{{ __('app.booking.payment.amount_received') }}</label>
                <input type="number" id="qb-paid" class="ls-input" min="0" step="0.01" inputmode="decimal" placeholder="0.00">
                <span class="ls-error" id="qb-paid-error" hidden><x-ui.icon name="alert" /><span></span></span>
            </div>
            <dl class="ls-pay-summary" aria-live="polite">
                <div><dt>{{ __('app.booking.payment.total') }}</dt><dd class="ls-num" id="qb-pay-total">&mdash;</dd></div>
                <div><dt>{{ __('app.booking.payment.paid_now') }}</dt><dd class="ls-num" id="qb-pay-paid">&mdash;</dd></div>
                <div><dt>{{ __('app.booking.payment.remaining') }}</dt><dd class="ls-num" id="qb-pay-remaining">&mdash;</dd></div>
            </dl>
            <span class="ls-status" id="qb-pay-status" hidden></span>
        </div>
        <p class="ls-hint" id="qb-pay-shared" hidden>{{ __('app.booking.payment.shared_estimate_note') }}</p>

        <div class="ls-qb-field">
            <label class="ls-qb-field-label" for="qb-notes">{{ __('app.quick_booking.note') }}</label>
            <textarea id="qb-notes" class="ls-textarea" rows="2" maxlength="500" placeholder="{{ __('app.quick_booking.note_placeholder') }}"></textarea>
        </div>

        <a href="/bookings/create" class="ls-link ls-qb-fullpage" id="qb-fullpage" data-qb-bypass>{{ __('app.quick_booking.full_page') }}</a>
    </form>

    {{-- Step 2 (bookings.series._review, fetched): step 1 above stays as it was, for Back. --}}
    <div id="qb-review" class="ls-qb-review" hidden></div>
    @include('bookings.series._review-script')

    <x-slot:footer>
        <div class="ls-qb-total" aria-live="polite">
            <span class="ls-qb-total-label" id="qb-total-label">{{ __('app.quick_booking.total') }}</span>
            <b class="ls-num" id="qb-total">—</b>
            <small id="qb-hint"></small>
        </div>
        <button type="submit" form="qb-form" class="ls-btn ls-btn--primary ls-qb-cta" id="qb-submit" disabled>{{ __('app.quick_booking.cta_idle') }}</button>
    </x-slot:footer>
</x-ui.modal>

<script>
(function () {
    const T = @json($qbI18n);
    const SLOTS = @json($qbSlots);
    const SLOT_KEYS = Object.keys(SLOTS);
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;
    const $ = (id) => document.getElementById(id);
    const fill = (s, map) => Object.entries(map).reduce((o, [k, v]) => o.split(':' + k).join(v), s);

    const S = { user: null, date: '', start: '', minutes: 60, end: '', people: 1, roomId: null, planId: null, preferRoom: null, rooms: [], fullDay: null, token: 0,
        pkgs: [], pkgId: null, pkgMinutesLabel: null, pkgKey: '', pkgToken: 0 };

    // ---------- helpers ----------
    const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const dayOffset = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return ymd(d); };
    const toMin = (hm) => { const [h, m] = hm.split(':').map(Number); return h * 60 + m; };
    const toHm = (m) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
    const durLabel = (m) => m < 60 ? fill(T.minutes, { count: m }) : (m === 60 ? T.hour1 : fill(T.hoursN, { count: String(+(m / 60).toFixed(2)) }));
    const endMinutes = () => S.minutes === 'custom' ? (S.end ? toMin(S.end) : null) : S.minutes === 'fullday' ? (S.fullDay ? toMin(S.fullDay.end) : null) : toMin(S.start) + S.minutes;

    // The grid's last slot is also the latest a booking can end (the server takes HH:MM, no "24:00").
    const LAST_SLOT = toMin(SLOT_KEYS[SLOT_KEYS.length - 1]);

    function defaultStart() {
        const now = new Date();
        const next = Math.ceil((now.getHours() * 60 + now.getMinutes() + 1) / 30) * 30;
        // Room for the default hour too — late in the evening there's none left today
        // (the caller then starts tomorrow, as when no slot is left at all).
        return SLOT_KEYS.find((k) => toMin(k) >= next && toMin(k) + 60 <= LAST_SLOT) || null;
    }

    // ---------- 1 · customer ----------
    const search = $('qb-search'), results = $('qb-results');
    let searchTimer = null, active = -1, found = [];

    function pickUser(u) {
        S.user = u;
        $('qb-find').hidden = true;
        $('qb-picked').hidden = false;
        $('qb-picked-name').textContent = u.name;
        $('qb-picked-phone').textContent = u.phone || '';
        $('qb-picked-avatar').textContent = (u.name || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase();
        closeResults();
        update();
    }
    $('qb-change').addEventListener('click', () => {
        S.user = null;
        $('qb-picked').hidden = true;
        $('qb-find').hidden = false;
        search.value = '';
        search.focus();
        update();
    });

    function closeResults() { results.hidden = true; search.setAttribute('aria-expanded', 'false'); search.removeAttribute('aria-activedescendant'); active = -1; }
    function renderResults(q) {
        results.innerHTML = '';
        found.forEach((u, i) => {
            const li = document.createElement('li');
            li.id = 'qb-opt-' + i; li.setAttribute('role', 'option'); li.className = 'ls-qb-opt';
            li.innerHTML = '<b></b><bdi dir="ltr" class="ls-faint"></bdi>';
            li.querySelector('b').textContent = u.name; li.querySelector('bdi').textContent = u.phone || '';
            li.addEventListener('mousedown', (e) => { e.preventDefault(); pickUser(u); });
            results.appendChild(li);
        });
        if (!found.length) {
            const li = document.createElement('li'); li.className = 'ls-qb-opt is-empty'; li.textContent = T.noResults; results.appendChild(li);
        }
        if ($('qb-newcust')) {
            const li = document.createElement('li');
            li.id = 'qb-opt-new'; li.setAttribute('role', 'option'); li.className = 'ls-qb-opt is-add';
            li.textContent = '+ ' + (q ? fill(T.addNamed, { name: q }) : T.addCustomer);
            li.addEventListener('mousedown', (e) => { e.preventDefault(); openNewCustomer(q); });
            results.appendChild(li);
        }
        results.hidden = false; search.setAttribute('aria-expanded', 'true');
    }
    function options() { return [...results.querySelectorAll('[role=option]')]; }
    function setActive(i) {
        const opts = options(); if (!opts.length) return;
        active = (i + opts.length) % opts.length;
        opts.forEach((o, n) => o.classList.toggle('is-active', n === active));
        search.setAttribute('aria-activedescendant', opts[active].id);
        opts[active].scrollIntoView({ block: 'nearest' });
    }
    search.addEventListener('input', () => {
        clearTimeout(searchTimer);
        const q = search.value.trim();
        if (!q) { closeResults(); return; }
        searchTimer = setTimeout(() => {
            fetch(`/users/search?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
                .then((r) => r.json()).then((list) => { found = (list || []).slice(0, 6); renderResults(q); active = -1; })
                .catch(() => {});
        }, 180);
    });
    search.addEventListener('keydown', (e) => {
        if (results.hidden) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
        else if (e.key === 'Enter') { e.preventDefault(); const o = options()[active < 0 ? 0 : active]; if (o) o.dispatchEvent(new MouseEvent('mousedown')); }
        else if (e.key === 'Escape') { e.stopPropagation(); closeResults(); }
    });
    search.addEventListener('blur', () => setTimeout(closeResults, 120));

    function openNewCustomer(q) {
        closeResults();
        const box = $('qb-newcust'); if (!box) return;
        box.hidden = false;
        const digits = /^[+\d\s-]+$/.test(q || '');
        $('qb-new-name').value = digits ? '' : (q || '');
        $('qb-new-phone').value = digits ? q : '';
        (digits || !q ? $('qb-new-name') : $('qb-new-phone')).focus();
    }
    if ($('qb-new-add')) {
        $('qb-new-add').addEventListener('click', () => {
            const err = $('qb-new-error'); err.hidden = true;
            LS.busy($('qb-new-add'));
            fetch('/users/quick', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ name: $('qb-new-name').value.trim(), phone: $('qb-new-phone').value.trim() }),
            })
                .then((r) => r.text().then((t) => { let b = null; try { b = JSON.parse(t); } catch (e) {} return { ok: r.ok, b }; }))
                .then(({ ok, b }) => {
                    if (ok && b) { $('qb-newcust').hidden = true; pickUser({ id: b.id, name: b.name, phone: b.phone }); return; }
                    err.textContent = !b ? T.sessionLost : (b.errors ? Object.values(b.errors)[0][0] : b.message) || T.error;
                    err.hidden = false;
                })
                .catch(() => { err.textContent = T.error; err.hidden = false; })
                .finally(() => LS.busy($('qb-new-add'), false));
        });
    }

    // ---------- 2 · when ----------
    const dateInput = $('qb-date'), startSel = $('qb-start'), endSel = $('qb-end');
    document.querySelectorAll('[data-qb-minutes]').forEach((b) => { if (/^\d+$/.test(b.dataset.qbMinutes)) b.textContent = durLabel(+b.dataset.qbMinutes); });

    // A new start date also moves a weekly series' default weekday (syncRepeat()).
    function setDate(v) { S.date = v; dateInput.value = v; fetchFullDay(); refreshWhen(); schedule(); if (repeatOn()) syncRepeat(); }
    document.querySelectorAll('[data-qb-day]').forEach((b) => b.addEventListener('click', () => setDate(dayOffset(+b.dataset.qbDay))));
    dateInput.addEventListener('change', () => { if (dateInput.value) setDate(dateInput.value); });

    // A chosen plan follows a new start (it keeps deciding the duration).
    startSel.addEventListener('change', () => { S.start = startSel.value; if (S.minutes === 'fullday') S.minutes = 60; refreshWhen(); schedule(); });
    endSel.addEventListener('change', () => { S.end = endSel.value; refreshWhen(); schedule(); });
    document.querySelectorAll('[data-qb-minutes]').forEach((b) => b.addEventListener('click', () => {
        const v = b.dataset.qbMinutes;
        S.planId = null; // picking a length by hand means standard pricing
        if (v === 'custom') { S.minutes = 'custom'; S.end = toHm(Math.min(toMin(SLOT_KEYS[SLOT_KEYS.length - 1]), (endMinutes() ?? toMin(S.start) + 60))); }
        else if (v === 'fullday') { S.minutes = 'fullday'; S.start = S.fullDay.start; startSel.value = S.start; }
        else S.minutes = +v;
        refreshWhen(); schedule();
        if (v === 'custom') endSel.focus();
    }));

    function refreshWhen() {
        const today = dayOffset(0), tomorrow = dayOffset(1);
        document.querySelectorAll('[data-qb-day]').forEach((b) => {
            const on = S.date === dayOffset(+b.dataset.qbDay);
            b.classList.toggle('is-active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        $('qb-date-chip').classList.toggle('is-active', !!S.date && S.date !== today && S.date !== tomorrow);

        // A length that no longer fits before the last slot (a later start) shrinks to the
        // longest one that does — or to a custom end at the last slot — never past it.
        if (typeof S.minutes === 'number' && toMin(S.start) + S.minutes > LAST_SLOT) {
            const fit = [240, 180, 120, 60].find((m) => toMin(S.start) + m <= LAST_SLOT);
            if (fit) { S.minutes = fit; } else { S.minutes = 'custom'; S.end = toMin(S.start) < LAST_SLOT ? toHm(LAST_SLOT) : ''; }
        }

        // End choices: every slot after the start.
        endSel.innerHTML = SLOT_KEYS.filter((k) => toMin(k) > toMin(S.start)).map((k) => `<option value="${k}">${SLOTS[k]}</option>`).join('');
        if (S.minutes === 'custom') {
            if (!S.end || toMin(S.end) <= toMin(S.start)) S.end = endSel.options[0]?.value || '';
            endSel.value = S.end;
        }
        $('qb-end-wrap').hidden = S.minutes !== 'custom';

        const lastSlot = toMin(SLOT_KEYS[SLOT_KEYS.length - 1]);
        document.querySelectorAll('[data-qb-minutes]').forEach((b) => {
            const v = b.dataset.qbMinutes;
            const on = String(S.minutes) === v;
            b.classList.toggle('is-active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false');
            if (/^\d+$/.test(v)) b.disabled = toMin(S.start) + +v > lastSlot;
            if (v === 'fullday') b.hidden = !(S.fullDay && SLOTS[S.fullDay.start] && SLOTS[S.fullDay.end]);
        });
        const end = endMinutes();
        // Times isolated left-to-right so Arabic doesn't reorder "04:00 PM → 07:00 PM".
        $('qb-range').textContent = end ? `⁦${SLOTS[S.start]}${T.arrow}${SLOTS[toHm(end)] || toHm(end)}⁩ · ${durLabel(end - toMin(S.start))}` : '';
    }

    function fetchFullDay() {
        const date = S.date;
        fetch(`/bookings/room-options?${new URLSearchParams({ booking_date: date })}`, { headers: { Accept: 'application/json' } })
            .then((r) => r.json()).then((d) => { if (date === S.date) { S.fullDay = d.full_day || null; refreshWhen(); } }).catch(() => {});
    }

    // People (only when some room prices by people).
    const peopleInput = $('qb-people');
    function setPeople(n) { S.people = Math.min(999, Math.max(1, n || 1)); peopleInput.value = S.people; schedule(); }
    document.querySelectorAll('[data-qb-people]').forEach((b) => b.addEventListener('click', () => setPeople(S.people + +b.dataset.qbPeople)));
    peopleInput.addEventListener('input', () => setPeople(parseInt(peopleInput.value, 10)));

    // ---------- 3 · rooms ----------
    let roomTimer = null;
    function schedule() { clearTimeout(roomTimer); roomTimer = setTimeout(fetchRooms, 140); update(); }

    function fetchRooms() {
        const end = endMinutes();
        if (!S.date || !S.start || !end) { S.rooms = []; renderRooms(); return; }
        const token = ++S.token;
        $('qb-room-msg').textContent = T.loading;
        $('qb-rooms').setAttribute('aria-busy', 'true');
        const params = new URLSearchParams({ booking_date: S.date, start_time: S.start, end_time: toHm(end), guest_count: S.people });
        fetch(`/bookings/room-options?${params}`, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((d) => {
                if (token !== S.token) return;
                S.rooms = (d.rooms || []).filter((r) => !r.is_shared);
                if (d.full_day !== undefined) S.fullDay = d.full_day;
                $('qb-people-wrap').hidden = !S.rooms.some((r) => r.uses_people);
                renderRooms();
                refreshWhen();
            })
            .catch(() => { if (token === S.token) { $('qb-room-msg').textContent = T.error; } })
            .finally(() => $('qb-rooms').removeAttribute('aria-busy'));
    }

    function renderRooms() {
        const box = $('qb-rooms'), msg = $('qb-room-msg');
        box.innerHTML = '';
        const outside = S.rooms.length && S.rooms.every((r) => r.reason === 'outside_hours');
        msg.textContent = !S.rooms.length ? (endMinutes() ? T.noRooms : '') : outside ? T.closed : '';
        msg.classList.toggle('is-warn', !!outside);
        if (outside) { S.roomId = null; update(); return; }

        // Available rooms first, then the rest with their reason.
        const sorted = [...S.rooms].sort((a, b) => (a.state === 'unavailable') - (b.state === 'unavailable'));
        if (S.preferRoom && sorted.some((r) => String(r.id) === String(S.preferRoom) && r.state !== 'unavailable')) { S.roomId = String(S.preferRoom); }
        S.preferRoom = null;
        if (S.roomId && !sorted.some((r) => String(r.id) === S.roomId && r.state !== 'unavailable')) S.roomId = null;

        sorted.forEach((r) => {
            const off = r.state === 'unavailable';
            const el = document.createElement('label');
            el.className = 'ls-qb-room' + (off ? ' is-off' : '') + (String(r.id) === S.roomId ? ' is-selected' : '');
            el.innerHTML = `<input type="radio" name="qb-room" value="${r.id}"${off ? ' disabled' : ''}${String(r.id) === S.roomId ? ' checked' : ''}>
                <span class="ls-qb-room-main"><b class="ls-trunc"></b><span class="ls-qb-room-meta ls-trunc"></span></span>
                <span class="ls-qb-room-price"><b class="ls-num"></b><small></small></span>`;
            el.querySelector('b.ls-trunc').textContent = r.name;
            const planCount = (r.plans || []).length;
            el.querySelector('.ls-qb-room-meta').textContent = `${r.type_label} · ${(r.capacity === 1 ? T.seat1 : fill(T.seatsN, { count: r.capacity }))}`
                + (planCount ? ` · ${planCount === 1 ? T.plans1 : fill(T.plansN, { count: planCount })}` : '');
            el.querySelector('.ls-qb-room-price b').textContent = off ? '' : r.total_price_display;
            el.querySelector('.ls-qb-room-price small').textContent = off ? (r.reason === 'no_seats' ? T.noSeats : T.bookedThen) : (r.price_note || '');
            el.querySelector('input').addEventListener('change', () => {
                S.roomId = String(r.id); S.planId = null; S.profileId = null; renderRooms();
                // Bring the plan choice into view when this room has plans.
                if (!$('qb-pricing').hidden) $('qb-pricing').scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            });
            box.appendChild(el);
        });
        renderPlans();
        update();
    }

    // ---------- 4 · pricing: standard vs the room's Custom Plans ----------
    function planWhy(p) {
        if (!p.fits_people) return p.people === 1 ? T.forPeople1 : fill(T.forPeopleN, { count: p.people });
        if (p.state === 'booked') return T.planBooked;
        if (p.state === 'no_fit') return T.planNoFit;
        if (p.state === 'outside_hours') return T.planClosed;
        return '';
    }
    function currentPlan() {
        const room = currentRoom();
        return room && S.planId ? (room.plans || []).find((p) => String(p.id) === S.planId) || null : null;
    }
    // Pricing profile (alternative hourly rate) — quoted server-side per slot.
    function currentProfile() {
        const room = currentRoom();
        return room && S.profileId ? (room.profiles || []).find((p) => String(p.id) === S.profileId) || null : null;
    }
    // What the booking is priced with: a profile or a plan (never both), else the room default.
    function currentChoice() {
        const pr = currentProfile();
        return pr ? { price: pr.total_price, price_display: pr.total_price_display, name: pr.name, note: pr.note } : currentPlan();
    }
    function renderPlans() {
        const room = currentRoom();
        const plans = room ? room.plans || [] : [];
        const profiles = room ? room.profiles || [] : [];
        $('qb-pricing').hidden = !plans.length && !profiles.length;
        const box = $('qb-plans');
        box.innerHTML = '';
        if (!plans.length) S.planId = null;
        if (S.profileId && !currentProfile()) S.profileId = null;
        if (!plans.length && !profiles.length) return;

        // A chosen plan that no longer fits (people/time changed) falls back to standard.
        const chosen = currentPlan();
        if (chosen && planWhy(chosen)) S.planId = null;
        // …and one that still fits keeps deciding the window.
        const keep = currentPlan();
        if (keep && (keep.start_time !== S.start || toHm(endMinutes() || 0) !== keep.end_time)) {
            S.start = keep.start_time; startSel.value = S.start; S.minutes = 'custom'; S.end = keep.end_time; refreshWhen();
        }

        const opt = (id, title, sub, price, why, kind = 'plan') => {
            const off = !!why;
            const on = kind === 'profile' ? String(S.profileId || '') === String(id) : !S.profileId && String(S.planId || '') === String(id || '');
            const el = document.createElement('label');
            el.className = 'ls-plan-opt' + (on ? ' is-selected' : '') + (off ? ' is-off' : '');
            el.innerHTML = '<input type="radio" name="qb-plan"><span class="ls-plan-opt-main"><b></b><small></small></span><span class="ls-plan-opt-price"></span>';
            const input = el.querySelector('input');
            input.value = id || ''; input.checked = on; input.disabled = off;
            el.querySelector('b').textContent = title;
            el.querySelector('small').textContent = sub;
            if (why) { const w = document.createElement('span'); w.className = 'ls-plan-why'; w.textContent = why; el.querySelector('.ls-plan-opt-main').appendChild(w); }
            el.querySelector('.ls-plan-opt-price').textContent = price;
            input.addEventListener('change', () => {
                if (kind === 'profile') { S.profileId = String(id); S.planId = null; renderPlans(); update(); return; }
                S.profileId = null;
                S.planId = id ? String(id) : null;
                const p = currentPlan();
                if (p) { S.start = p.start_time; startSel.value = S.start; S.minutes = 'custom'; S.end = p.end_time; refreshWhen(); schedule(); }
                renderPlans(); update();
            });
            box.appendChild(el);
        };
        opt(null, profiles.length ? T.defaultLabel : T.standard, room.price_note || '', room.total_price_display, '');
        profiles.forEach((p) => opt(p.id, p.name, p.rate_display, p.total_price_display, '', 'profile'));
        plans.forEach((p) => opt(p.id, p.name, `${p.people_label} · ${p.duration_label}`, p.price_display, planWhy(p)));
    }

    // ---------- 5 · hour packages (server decides eligibility; POST re-checks) ----------
    let pkgTimer = null;
    const pkgMode = () => (document.querySelector('[data-qb-pay-mode]:checked') || {}).value || 'normal';
    function currentPkg() { return pkgMode() === 'package' ? S.pkgs.find((p) => String(p.id) === String(S.pkgId) && p.eligible) || null : null; }
    function refreshPackages() {
        const room = currentRoom(), end = endMinutes();
        const key = S.user && room && end ? [S.user.id, room.id, S.date, S.start, toHm(end), S.people, S.planId || ''].join('|') : '';
        if (key === S.pkgKey) return;
        S.pkgKey = key;
        clearTimeout(pkgTimer);
        if (!key) { S.pkgs = []; renderPackages(); return; }
        pkgTimer = setTimeout(() => {
            const params = new URLSearchParams({ hotspot_user_id: S.user.id, room_id: room.id, booking_date: S.date, start_time: S.start, end_time: toHm(end), guest_count: S.people });
            if (S.planId) params.set('room_plan_id', S.planId);
            const token = ++S.pkgToken;
            fetch(`/bookings/package-options?${params}`, { headers: { Accept: 'application/json' } })
                .then((r) => r.ok ? r.json() : { packages: [] })
                .then((d) => { if (token !== S.pkgToken) return; S.pkgs = d.packages || []; S.pkgMinutesLabel = d.minutes_label; renderPackages(); update(); })
                .catch(() => {});
        }, 160);
    }
    function renderPackages() {
        $('qb-pkg').hidden = !S.pkgs.length;
        $('qb-pkg-n').textContent = $('qb-pricing').hidden ? '4' : '5'; // follows the visible steps
        const use = pkgMode() === 'package';
        $('qb-pkg-options').hidden = !use || !S.pkgs.length;
        $('qb-pkg-needs').hidden = !use || !S.pkgMinutesLabel;
        $('qb-pkg-needs').textContent = S.pkgMinutesLabel ? fill(T.pkgNeeds, { time: S.pkgMinutesLabel }) : '';
        const box = $('qb-pkg-options');
        box.innerHTML = '';
        if (!use) return;
        if (!currentPkg()) { const first = S.pkgs.find((p) => p.eligible); S.pkgId = first ? String(first.id) : null; }
        S.pkgs.forEach((p) => {
            const el = document.createElement('label');
            el.className = 'ls-pkg-opt' + (p.eligible ? '' : ' is-disabled');
            el.innerHTML = '<input type="radio" name="qb-pkg"><span class="ls-pkg-opt-main"><span class="ls-pkg-opt-name"></span><span class="ls-pkg-opt-meta"></span></span><span class="ls-pkg-opt-left"></span>';
            const input = el.querySelector('input');
            input.disabled = !p.eligible; input.checked = String(p.id) === String(S.pkgId);
            el.querySelector('.ls-pkg-opt-name').textContent = p.name;
            el.querySelector('.ls-pkg-opt-meta').textContent = fill(T.pkgExpires, { date: p.expires_label });
            el.querySelector('.ls-pkg-opt-left').textContent = fill(T.pkgLeft, { time: p.remaining_label });
            if (p.reason) { const w = document.createElement('span'); w.className = 'ls-pkg-opt-reason'; w.textContent = p.reason; el.querySelector('.ls-pkg-opt-main').appendChild(w); }
            input.addEventListener('change', () => { S.pkgId = String(p.id); update(); });
            box.appendChild(el);
        });
    }
    document.querySelectorAll('[data-qb-pay-mode]').forEach((r) => r.addEventListener('change', () => { renderPackages(); update(); }));

    // ---------- price + CTA (the one summary) ----------
    const submit = $('qb-submit');
    function currentRoom() { return S.rooms.find((r) => String(r.id) === S.roomId) || null; }
    function update() {
        refreshPackages();
        const room = currentRoom();
        const plan = currentChoice();
        const missing = !S.user ? T.needCustomer : !endMinutes() ? T.needTime : !room ? T.needRoom : '';
        $('qb-total').textContent = plan ? plan.price_display : room ? room.total_price_display : '—';
        // The chosen room's row shows what will actually be charged (one price on screen, not two).
        const selRow = document.querySelector('.ls-qb-room.is-selected');
        if (selRow && room) {
            selRow.querySelector('.ls-qb-room-price b').textContent = plan ? plan.price_display : room.total_price_display;
            selRow.querySelector('.ls-qb-room-price small').textContent = plan ? plan.name : (room.price_note || '');
        }
        $('qb-hint').textContent = missing || (plan ? plan.note : room && room.price_note) || '';
        submit.disabled = !!missing;
        submit.textContent = room ? fill(T.cta, { total: plan ? plan.price_display : room.total_price_display }) : T.ctaIdle;
        // Covered by an hour package: no deposit, one clear total.
        const pkg = room && !missing && !repeatOn() ? currentPkg() : null;
        updatePayment(room, plan, pkg);
        // Repeating: the review step books (and prices) every date; no payment or package here.
        // The footer shows one date's price — never as the total — and an estimate for the series.
        const dates = repeatSummary();
        $('qb-total-label').textContent = repeatOn() ? T.perDate : T.total;
        if (repeatOn()) {
            $('qb-paid-wrap').hidden = true;
            $('qb-pay-shared').hidden = true;
            $('qb-pkg').hidden = true;
            const why = missing || (currentPlan() ? T.repeatNoPlan : '') || (dates ? '' : T.repeatNoDays);
            submit.disabled = !!why;
            submit.textContent = T.repeatCta;
            const each = room ? Number(plan ? plan.price : room.total_price) || 0 : 0;
            $('qb-hint').textContent = why || fill(T.repeatEstimate, { count: dates, total: money(each * dates) });
            return;
        }
        // Payment is required (no default) — Book waits for a choice, the hint says why.
        if (!missing && !pkg && !room.is_shared && !payChoice()) {
            submit.disabled = true;
            $('qb-hint').textContent = T.choiceRequired;
        }
        if (pkg) {
            $('qb-total').textContent = fill(T.pkgCovered, { time: S.pkgMinutesLabel || '' });
            $('qb-hint').textContent = pkg.name;
            submit.textContent = T.pkgCta;
        }
    }

    // ---------- payment: No / Partial / Full, always visible ----------
    const payChoice = () => (document.querySelector('[data-qb-pay-choice]:checked') || {}).value || '';
    const money = (n) => {
        const s = (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        return document.documentElement.dir === 'rtl' ? `${s} ج.م` : `EGP ${s}`;
    };
    let payError = '';
    // No payment → 0 · Full → the live total (follows every price change) ·
    // Partial → what was typed, never silently clamped: out of range blocks Book.
    function updatePayment(room, plan, pkg) {
        const shared = !!(room && room.is_shared);
        const total = room ? Number(plan ? plan.price : room.total_price) || 0 : null;
        $('qb-paid-wrap').hidden = !!pkg || shared;
        $('qb-pay-shared').hidden = !shared || !!pkg;
        const fullRadio = document.querySelector('[data-qb-pay-choice][value="full"]');
        fullRadio.disabled = total === null;
        if (fullRadio.checked && fullRadio.disabled) fullRadio.checked = false;

        const choice = payChoice(), input = $('qb-paid');
        $('qb-paid-field').hidden = choice !== 'partial';
        if (choice === 'none') input.value = '';
        if (choice === 'full') input.value = total !== null ? Math.round(total * 100) / 100 : '';
        const typed = parseFloat(input.value || '0') || 0;
        payError = '';
        if (choice === 'partial' && total !== null && !pkg && !shared) {
            if (typed <= 0) payError = T.partialRequired;
            else if (typed > total + 0.004) payError = fill(T.exceedsTotal, { total: money(total) });
        }
        const showErr = !!payError && input.value !== '';
        $('qb-paid-error').hidden = !showErr;
        $('qb-paid-error').querySelector('span').textContent = payError;
        input.classList.toggle('is-invalid', showErr);

        const pending = !choice || !!payError; // required: nothing settled until one is picked
        const paid = total === null ? 0 : Math.max(0, Math.min(choice === 'none' ? 0 : typed, total));
        $('qb-pay-total').textContent = total === null ? '—' : money(total);
        $('qb-pay-paid').textContent = total === null || !choice ? '—' : money(payError ? typed : paid);
        $('qb-pay-remaining').textContent = total === null || pending ? '—' : money(total - paid);
        const status = paid <= 0 ? 'unpaid' : (paid >= total ? 'paid' : 'partial');
        const st = $('qb-pay-status');
        st.hidden = total === null || pending;
        st.textContent = T.payStatus[status];
        st.className = 'ls-status ls-status--' + ({ paid: 'ok', partial: 'warn', unpaid: 'neutral' })[status];
    }
    document.querySelectorAll('[data-qb-pay-choice]').forEach((r) => r.addEventListener('change', () => {
        if (payChoice() === 'partial') $('qb-paid').value = '';
        update();
        if (payChoice() === 'partial') $('qb-paid').focus();
    }));
    $('qb-paid').addEventListener('input', update);

    // ---------- repeat: hand this choice to the recurring review step ----------
    const repeatBox = $('qb-repeat-section'), repeatToggle = $('qb-repeat-toggle'), repeatDays = $('qb-repeat-weekdays');
    function repeatOn() { return repeatToggle.checked; }
    const repeatWeekly = () => (repeatBox.querySelector('input[name="repeat_frequency"]:checked') || {}).value === 'weekly';
    // Weekly starts on the start date's weekday and follows that date until
    // the owner picks days themselves (then their days stand, and the summary
    // says which date comes first — RecurrenceRule's own rule).
    let repeatDaysChosen = false;
    function syncRepeat() {
        $('qb-repeat-fields').hidden = !repeatOn();
        repeatDays.hidden = !repeatWeekly();
        // Today/Tomorrow don't fit a repeating schedule: a clear start date instead.
        document.querySelectorAll('[data-qb-day]').forEach((b) => { b.hidden = repeatOn(); });
        $('qb-start-date-label').hidden = !repeatOn();
        $('qb-steps').hidden = !repeatOn(); // two steps only when repeating
        if (repeatOn() && repeatWeekly() && !repeatDaysChosen && S.date) {
            const day = new Date(S.date + 'T00:00:00').getDay();
            repeatDays.querySelectorAll('input').forEach((i) => { i.checked = +i.value === day; });
        }
        renderPackages(); // back on screen when repeat is switched off
        update();
    }
    /** The pattern in words; also how many dates the series makes (0 when off/incomplete). */
    function repeatSummary() {
        const s = repeatOn() ? LSRepeat.summary(repeatBox, S.date) : { html: '', count: 0 };
        $('qb-repeat-summary').innerHTML = s.html;
        return s.count;
    }
    repeatToggle.addEventListener('change', syncRepeat);
    repeatBox.querySelectorAll('input[name="repeat_frequency"]').forEach((r) => r.addEventListener('change', syncRepeat));
    repeatDays.addEventListener('change', () => { repeatDaysChosen = true; syncRepeat(); });
    repeatBox.addEventListener('input', (e) => { if (e.target.matches('[name="repeat_count"], [name="repeat_until"]')) update(); });
    repeatBox.addEventListener('change', (e) => { if (e.target.matches('[name="repeat_end"], [name="repeat_until"]')) update(); });

    // ---------- step 2: review and confirm, in this same dialog ----------
    // The review is the server's (bookings.series.review, as a fragment):
    // dates, conflicts, prices, payment plan, package — nothing is booked or
    // held until its confirm button. Back shows step 1 exactly as it was;
    // the plan / package / per-date choices made on step 2 come back too.
    const reviewBox = $('qb-review'), steps = $('qb-steps'), dialog = $('quick-book').querySelector('.ls-dialog');
    const footer = dialog.querySelector('.ls-dialog-foot');
    let reviewKeep = null; // {plan, pkg, choices: {date: choice}} from the last review

    function showStep(n) {
        const review = n === 2;
        $('qb-form').hidden = review;
        reviewBox.hidden = !review;
        footer.hidden = review; // step 2 has its own footer (amount + confirm)
        dialog.classList.toggle('is-review', review);
        steps.hidden = !repeatOn();
        steps.querySelectorAll('[data-qb-step]').forEach((li) => {
            const on = li.dataset.qbStep === String(n);
            on ? li.setAttribute('aria-current', 'step') : li.removeAttribute('aria-current');
            li.classList.toggle('is-done', review && li.dataset.qbStep === '1');
        });
        dialog.querySelector('.ls-dialog-body').scrollTop = 0;
        (review ? reviewBox.querySelector('h3, [data-confirm]') : $('qb-repeat-toggle'))?.focus({ preventScroll: true });
    }

    function keepReviewChoices() {
        const f = reviewBox.querySelector('[data-series-review]');
        if (!f) return;
        reviewKeep = {
            user: S.user && S.user.id, // a package belongs to one customer
            plan: (f.querySelector('[name="payment_plan"]:checked') || {}).value,
            pkg: (f.querySelector('[name="series_package_id"]') || {}).value || '',
            choices: Object.fromEntries([...f.querySelectorAll('[data-occurrence]')].map((s) => [s.closest('li').querySelector('input[name$="[date]"]').value, s.value])),
        };
    }

    async function submitRepeat(room) {
        const err = $('qb-error');
        const fail = (msg) => { err.querySelector('span').textContent = msg; err.hidden = false; err.scrollIntoView({ block: 'nearest' }); };
        if (repeatWeekly() && !repeatDays.querySelector('input:checked')) { fail(T.repeatNoDays); return; }
        const body = new FormData();
        const add = (k, v) => body.append(k, v);
        add('_token', CSRF);
        add('from_quick', '1');
        add('repeat', '1');
        add('hotspot_user_id', S.user.id);
        add('booking_date', S.date);
        add('start_time', S.start);
        add('end_time', toHm(endMinutes()));
        add('room_id', room.id);
        add('guest_count', S.people);
        if (currentProfile()) add('room_pricing_profile_id', S.profileId);
        if ($('qb-notes').value.trim()) add('notes', $('qb-notes').value.trim());
        repeatBox.querySelectorAll('input:checked, input[type="number"], input[type="date"]').forEach((i) => {
            // Weekdays only mean something for a weekly series.
            if (i.name && i.name !== 'repeat' && (repeatWeekly() || i.name !== 'repeat_weekdays[]')) add(i.name, i.value);
        });
        if (reviewKeep) {
            if (reviewKeep.plan) add('payment_plan', reviewKeep.plan);
            if (reviewKeep.pkg && reviewKeep.user === S.user.id) add('series_package_id', reviewKeep.pkg);
        }

        LS.busy(submit);
        try {
            const r = await fetch(@json(route('bookings.series.review')), { method: 'POST', body, headers: { 'X-LS-Fragment': 'series-review', Accept: 'text/html' } });
            if (!r.ok) {
                let msg = T.error;
                try { msg = (await r.json()).message || msg; } catch (x) { if (r.status === 419) msg = T.sessionLost; }
                fail(msg);
                return;
            }
            reviewBox.innerHTML = await r.text();
            const root = reviewBox.querySelector('[data-series-review-root]');
            if (reviewKeep) {
                root.querySelectorAll('[data-occurrence]').forEach((s) => {
                    const v = reviewKeep.choices[s.closest('li').querySelector('input[name$="[date]"]').value];
                    if (v && [...s.options].some((o) => o.value === v)) s.value = v;
                });
            }
            LSSeriesReview.init(root, { container: reviewBox, onBack: () => { keepReviewChoices(); showStep(1); } });
            showStep(2);
        } catch (x) {
            fail(T.error);
        } finally {
            LS.busy(submit, false);
        }
    }

    $('qb-form').addEventListener('submit', (e) => {
        e.preventDefault();
        const room = currentRoom(); if (submit.disabled || !room) return;
        const err = $('qb-error'); err.hidden = true;
        if (repeatOn()) { submitRepeat(room); return; }
        if (payError && !currentPkg()) {
            $('qb-paid-error').querySelector('span').textContent = payError;
            $('qb-paid-error').hidden = false;
            $('qb-paid').classList.add('is-invalid');
            $('qb-paid').focus();
            return;
        }
        const body = new FormData();
        body.append('_token', CSRF);
        body.append('hotspot_user_id', S.user.id);
        body.append('booking_date', S.date);
        body.append('start_time', S.start);
        body.append('end_time', toHm(endMinutes()));
        body.append('room_id', room.id);
        body.append('guest_count', S.people);
        if (currentPlan()) body.append('room_plan_id', S.planId);
        if (currentProfile()) body.append('room_pricing_profile_id', S.profileId);
        if (currentPkg()) body.append('member_package_id', S.pkgId);
        else if (!room.is_shared) {
            body.append('payment_choice', payChoice());
            if (payChoice() === 'partial') body.append('amount_paid', $('qb-paid').value);
        }
        if ($('qb-notes').value.trim()) body.append('notes', $('qb-notes').value.trim());
        LS.busy(submit);
        fetch('/bookings', { method: 'POST', body, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': CSRF } })
            .then((r) => r.text().then((t) => { let b = null; try { b = JSON.parse(t); } catch (x) {} return { ok: r.ok, b }; }))
            .then(({ ok, b }) => {
                if (ok && b && b.success) {
                    LS.close('quick-book');
                    // Pages that list bookings refresh to show it; elsewhere, offer a way there.
                    if (/^\/(bookings(\/calendar)?|dashboard|active-sessions)\/?$/.test(location.pathname)) LS.reloadWithToast(b.message);
                    else LS.toast(b.message, { action: { label: T.view, onClick: () => { location.href = b.url; } } });
                    return;
                }
                err.querySelector('span').textContent = !b ? T.sessionLost : (b.errors ? Object.values(b.errors)[0][0] : b.message) || T.error;
                err.hidden = false;
                err.scrollIntoView({ block: 'nearest' });
                fetchRooms(); // availability may have changed underneath us
            })
            .catch(() => { err.querySelector('span').textContent = T.error; err.hidden = false; })
            .finally(() => LS.busy(submit, false));
    });

    // ---------- open with prefill ----------
    function open(prefill = {}) {
        $('qb-error').hidden = true;
        $('qb-paid').value = ''; $('qb-notes').value = '';
        document.querySelectorAll('[data-qb-pay-choice]').forEach((r) => { r.checked = false; });
        if ($('qb-newcust')) $('qb-newcust').hidden = true;
        $('qb-picked-name').textContent = ''; $('qb-picked-phone').textContent = '';
        S.user = null; S.roomId = null; S.planId = null; S.profileId = null; S.rooms = []; S.people = 1; peopleInput.value = 1;
        S.pkgs = []; S.pkgId = null; S.pkgKey = ''; S.pkgMinutesLabel = null; $('qb-pkg').hidden = true;
        document.querySelector('[data-qb-pay-mode][value="normal"]').checked = true;
        // Every open starts as a one-time booking.
        repeatToggle.checked = false; $('qb-repeat-fields').hidden = true;
        document.querySelectorAll('[data-qb-day]').forEach((b) => { b.hidden = false; }); $('qb-start-date-label').hidden = true;
        repeatDays.querySelectorAll('input').forEach((i) => { i.checked = false; });
        repeatDaysChosen = false;
        // …on step 1, with no review left from last time.
        reviewKeep = null; reviewBox.innerHTML = ''; showStep(1);
        repeatBox.classList.remove('is-collapsed');
        const fold = repeatBox.querySelector('[data-repeat-collapse]');
        if (fold) { fold.setAttribute('aria-expanded', 'true'); fold.textContent = fold.dataset.done; }
        S.preferRoom = prefill.room_id || null;
        $('qb-picked').hidden = true; $('qb-find').hidden = false; search.value = '';

        let date = prefill.booking_date || dayOffset(0);
        let start = prefill.start_time && SLOTS[prefill.start_time] ? prefill.start_time : null;
        if (!start) { start = date === dayOffset(0) ? defaultStart() : null; }
        if (!start) { if (date === dayOffset(0) && !prefill.booking_date) date = dayOffset(1); start = SLOT_KEYS.includes('10:00') ? '10:00' : SLOT_KEYS[0]; }
        S.date = date; dateInput.value = date; S.start = start; startSel.value = start;
        S.minutes = 60; S.end = '';
        if (prefill.end_time) {
            const m = toMin(prefill.end_time) - toMin(start);
            if ([60, 120, 180, 240].includes(m)) S.minutes = m; else if (m > 0) { S.minutes = 'custom'; S.end = prefill.end_time; }
        }
        $('qb-fullpage').href = '/bookings/create' + (prefill.query ? '?' + prefill.query : '');

        if (prefill.hotspot_user_id && prefill.user_name) pickUser({ id: prefill.hotspot_user_id, name: prefill.user_name, phone: prefill.user_phone || '' });
        refreshWhen(); fetchFullDay(); fetchRooms(); update();
        LS.open('quick-book');
        if (!S.user) setTimeout(() => search.focus(), 60);
    }
    window.LSQuickBook = { open };

    // Any "New booking" link in the app opens the quick flow instead (plain click only).
    document.addEventListener('click', (e) => {
        const a = e.target.closest('a[href]');
        if (!a || a.hasAttribute('data-qb-bypass') || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        const url = new URL(a.href, location.origin);
        if (url.origin !== location.origin || url.pathname !== '/bookings/create') return;
        e.preventDefault();
        const p = Object.fromEntries(url.searchParams);
        open({ ...p, query: url.search.slice(1), user_name: a.dataset.bookName, user_phone: a.dataset.bookPhone });
    });
})();
</script>
