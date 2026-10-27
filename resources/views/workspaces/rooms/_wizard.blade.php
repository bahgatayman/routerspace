{{--
    The room form as a three-step wizard: 1. room details, 2. pricing,
    3. optional extras (Pricing Profiles, Custom Plans) and a review. ONE
    native form posting once, with the same fields as ever, to the endpoint
    given (RoomController::store()/storeUse()/update() stay the only
    validation and persistence); the steps only show and hide parts of it.
    Without JavaScript every step shows at once, like a plain form.

    Used by Add Room / Add another use (create.blade.php) and Edit Room
    (edit.blade.php). Expects:
      $workspace, $roomTypes
      $room          the room being edited, or null when adding
      $physicalRoom  the room a new use is added to, or null
      $action, $method ('POST'|'PUT'), $submitLabel, $busyLabel, $cancelUrl
    Editing: every step is open from the start and Save works from any step.
--}}
@php
    $room = $room ?? null;
    $physicalRoom = $physicalRoom ?? null;
    $editing = $room !== null;

    // Which step each field lives on, so a failed submission reopens the
    // first step with an error (server validation stays authoritative).
    $stepFields = [
        1 => ['name', 'type', 'capacity', 'description'],
        2 => ['pricing_model', 'price_per_hour', 'pricing_rules', 'billing_unit', 'billing_buffer_minutes'],
        3 => ['pricing_profiles', 'plans'],
    ];
    $stepHasError = collect($stepFields)->map(fn ($fields) => $errors->hasAny($fields));
    $startStep = $stepHasError->filter()->keys()->first() ?? 1;
    // Editing, or coming back with old input: every step was filled in once.
    $reached = $editing || session()->hasOldInput() ? 3 : $startStep;

    $steps = [
        1 => __('app.workspace.wizard.steps.details'),
        2 => __('app.workspace.wizard.steps.pricing'),
        3 => __('app.workspace.wizard.steps.extras'),
    ];

    // How many profiles / plans the form starts with (old input, else the room's).
    $countRows = function (string $field, int $fallback): int {
        if (! session()->hasOldInput($field)) {
            return $fallback;
        }
        $rows = json_decode((string) old($field), true);

        return is_array($rows) ? count($rows) : 0;
    };
    $profilesOpen = $countRows('pricing_profiles', $room?->pricingProfiles()->count() ?? 0) > 0 || $errors->has('pricing_profiles');
    $plansOpen = $countRows('plans', $room?->plans()->count() ?? 0) > 0 || $errors->has('plans');

    // One aria-describedby per field: its hint and/or its error message.
    $describedBy = fn (array $ids) => collect($ids)->filter()->implode(' ') ?: null;
    $nameDescribedBy = $describedBy([$physicalRoom ? 'room_name-hint' : null, $errors->has('name') ? 'room_name-error' : null]);
    $typeDescribedBy = $describedBy(['room_type_hint', $errors->has('type') ? 'room_type-error' : null]);
    $capacityDescribedBy = $describedBy([$errors->has('capacity') ? 'room_capacity-error' : null]);
    $descriptionDescribedBy = $describedBy([$errors->has('description') ? 'room_description-error' : null]);

    $isAr = app()->getLocale() === 'ar';
    $wizardI18n = [
        'stepOf' => __('app.workspace.wizard.step_of', ['current' => ':current', 'total' => ':total']),
        'titles' => array_values($steps),
        'busy' => $busyLabel,
        'submit' => $submitLabel,
        'currency' => $isAr ? 'ج.م' : 'EGP',
        'currencyAfter' => $isAr,
        'perHour' => __('app.common.slash_hr'),
        'none' => __('app.workspace.wizard.none'),
        'edit' => __('app.workspace.wizard.edit'),
        'inactive' => __('app.workspace.uses.inactive'),
        'fullDay' => __('app.pricing.full_day'),
        'hour1' => trans_choice('app.pricing.hours', 1, ['count' => 1]),
        'hoursN' => trans_choice('app.pricing.hours', 2, ['count' => ':count']),
        'person1' => trans_choice('app.pricing.people_exact', 1, ['count' => 1]),
        'peopleN' => trans_choice('app.pricing.people_exact', 2, ['count' => ':count']),
        'count' => [
            0 => __('app.workspace.wizard.added_none'),
            1 => trans_choice('app.workspace.wizard.added_count', 1, ['count' => 1]),
            'n' => trans_choice('app.workspace.wizard.added_count', 2, ['count' => ':count']),
        ],
        'sections' => [
            'details' => __('app.workspace.wizard.review_details'),
            'pricing' => __('app.workspace.wizard.review_pricing'),
            'profiles' => __('app.pricing_profiles.section'),
            'plans' => __('app.plans.section'),
        ],
        'labels' => [
            'name' => $physicalRoom ? __('app.workspace.uses.name') : __('app.workspace.room_name'),
            'type' => __('app.workspace.type'),
            'capacity' => __('app.workspace.capacity'),
            'description' => __('app.workspace.description'),
            'model' => __('app.workspace.wizard.review_model'),
            'price' => __('app.workspace.price_per_hour'),
            'billing' => __('app.workspace.billing_unit'),
            'buffer' => __('app.workspace.billing_buffer'),
            'bufferMin' => __('app.workspace.billing_buffer_min', ['count' => ':count']),
            'plan' => __('app.workspace.wizard.unnamed_plan'),
        ],
        'errors' => [
            'durations' => __('app.pricing.errors.duration_required'),
            'people' => __('app.pricing.errors.people_required'),
            'prices' => __('app.pricing.errors.price_missing'),
            'invalid' => __('app.pricing.errors.invalid'),
        ],
    ];
@endphp

<form method="POST" novalidate class="ls-wizard" action="{{ $action }}"
      data-room-wizard data-start-step="{{ $startStep }}" data-reached="{{ $reached }}"
      @if ($editing) data-editing="1" @endif @if ($errors->any()) data-has-errors="1" @endif>
    @csrf
    @if ($method !== 'POST') @method($method) @endif

    <nav class="ls-wizard-progress" aria-label="{{ __('app.workspace.wizard.progress') }}" data-wizard-progress hidden>
        <ol class="ls-wizard-steps">
            @foreach ($steps as $n => $title)
                <li class="ls-wizard-step {{ $stepHasError[$n] ? 'has-error' : '' }}">
                    <button type="button" class="ls-wizard-step-btn" data-wizard-goto="{{ $n }}">
                        <span class="ls-wizard-step-num" aria-hidden="true"><span>{{ $n }}</span><x-ui.icon name="check" /></span>
                        <span class="ls-wizard-step-text">
                            <span class="ls-wizard-step-kicker">{{ __('app.workspace.wizard.step_of', ['current' => $n, 'total' => count($steps)]) }}</span>
                            <span class="ls-wizard-step-title">{{ $title }}</span>
                            @if ($stepHasError[$n])
                                <span class="ls-wizard-step-flag">{{ __('app.workspace.wizard.needs_attention') }}</span>
                            @endif
                        </span>
                    </button>
                </li>
            @endforeach
        </ol>
    </nav>
    <p class="ls-sr" aria-live="polite" data-wizard-live></p>

    <div class="ls-card ls-wizard-card">
        {{-- Step 1 — Room details --}}
        <section class="ls-wizard-panel" data-wizard-step="1" aria-labelledby="wizard-step-1-title" tabindex="-1">
            <header class="ls-wizard-panel-head">
                <h2 class="ls-wizard-panel-title" id="wizard-step-1-title">{{ $steps[1] }}</h2>
                <p class="ls-hint">{{ __('app.workspace.wizard.details_intro') }}</p>
            </header>
            <div class="ls-wizard-alert" data-wizard-alert role="alert" hidden></div>

            <div class="ls-wizard-fields">
                <div class="ls-field">
                    <label class="ls-label" for="room_name">{{ $physicalRoom ? __('app.workspace.uses.name') : __('app.workspace.room_name') }} <span class="ls-req" aria-hidden="true">*</span></label>
                    <input type="text" name="name" id="room_name" value="{{ old('name', $room?->name) }}" required maxlength="255"
                           class="ls-input @error('name') is-invalid @enderror"
                           @if ($physicalRoom) placeholder="{{ __('app.workspace.uses.name_placeholder') }}" @endif
                           @if ($nameDescribedBy) aria-describedby="{{ $nameDescribedBy }}" @endif
                           @error('name') aria-invalid="true" @enderror>
                    @if ($physicalRoom) <p class="ls-hint" id="room_name-hint">{{ __('app.workspace.uses.name_hint') }}</p> @endif
                    @error('name') <p class="ls-error" id="room_name-error">{{ $message }}</p> @enderror
                </div>

                <div class="ls-wizard-row">
                    <div class="ls-field">
                        <label class="ls-label" for="room_type">{{ __('app.workspace.type') }} <span class="ls-req" aria-hidden="true">*</span></label>
                        <select name="type" id="room_type" required aria-describedby="{{ $typeDescribedBy }}"
                                class="ls-select @error('type') is-invalid @enderror" @error('type') aria-invalid="true" @enderror>
                            <option value="">{{ __('app.placeholder.select_type') }}</option>
                            @foreach ($roomTypes as $key => $label)
                                <option value="{{ $key }}" {{ old('type', $room?->type) === $key ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="ls-hint" id="room_type_hint">
                            {{ old('type', $room?->type) === 'shared' ? __('app.workspace.shared_room_hint') : __('app.workspace.exclusive_room_hint') }}
                        </p>
                        @error('type') <p class="ls-error" id="room_type-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="ls-field">
                        <label class="ls-label" for="room_capacity">{{ __('app.workspace.capacity') }} <span class="ls-req" aria-hidden="true">*</span></label>
                        <input type="number" name="capacity" id="room_capacity" min="1" max="999" step="1" inputmode="numeric" required
                               value="{{ old('capacity', $room?->capacity ?? $physicalRoom?->capacity ?? 1) }}"
                               class="ls-input @error('capacity') is-invalid @enderror"
                               @if ($capacityDescribedBy) aria-describedby="{{ $capacityDescribedBy }}" @endif
                               @error('capacity') aria-invalid="true" @enderror>
                        @error('capacity') <p class="ls-error" id="room_capacity-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="ls-field">
                    <label class="ls-label" for="room_description">{{ __('app.workspace.description') }} <span class="ls-opt">({{ __('app.ui.optional') }})</span></label>
                    <textarea name="description" id="room_description" rows="4" maxlength="1000"
                              class="ls-textarea @error('description') is-invalid @enderror"
                              @if ($descriptionDescribedBy) aria-describedby="{{ $descriptionDescribedBy }}" @endif
                              @error('description') aria-invalid="true" @enderror>{{ old('description', $room?->description) }}</textarea>
                    @error('description') <p class="ls-error" id="room_description-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        {{-- Step 2 — Pricing (the existing editor: model cards + their inputs) --}}
        <section class="ls-wizard-panel" data-wizard-step="2" aria-labelledby="pricing-title" tabindex="-1">
            <div class="ls-wizard-alert" data-wizard-alert role="alert" hidden></div>
            @include('workspaces.rooms._pricing', ['room' => $room])
        </section>

        {{-- Step 3 — optional extras, then a review of everything --}}
        <section class="ls-wizard-panel" data-wizard-step="3" aria-labelledby="wizard-step-3-title" tabindex="-1">
            <header class="ls-wizard-panel-head">
                <h2 class="ls-wizard-panel-title" id="wizard-step-3-title">{{ $steps[3] }}</h2>
                <p class="ls-hint">{{ __('app.workspace.wizard.extras_intro') }}</p>
            </header>
            <div class="ls-wizard-alert" data-wizard-alert role="alert" hidden></div>

            <details class="ls-wizard-extra" data-wizard-extra="profiles" @if ($profilesOpen) open @endif>
                <summary>
                    <span class="ls-wizard-extra-title"><span id="profiles-title">{{ __('app.pricing_profiles.section') }}</span> <span class="ls-opt">({{ __('app.ui.optional') }})</span></span>
                    <span class="ls-wizard-extra-count" data-extra-count="pricing_profiles"></span>
                    <x-ui.icon name="chevron-right" />
                </summary>
                <div class="ls-wizard-extra-body">
                    @include('workspaces.rooms._pricing-profiles', ['room' => $room, 'showHeading' => false])
                </div>
            </details>

            <details class="ls-wizard-extra" data-wizard-extra="plans" @if ($plansOpen) open @endif>
                <summary>
                    <span class="ls-wizard-extra-title"><span id="plans-title">{{ __('app.plans.section') }}</span> <span class="ls-opt">({{ __('app.ui.optional') }})</span></span>
                    <span class="ls-wizard-extra-count" data-extra-count="plans"></span>
                    <x-ui.icon name="chevron-right" />
                </summary>
                <div class="ls-wizard-extra-body">
                    @include('workspaces.rooms._pricing-plans', ['room' => $room, 'showHeading' => false])
                </div>
            </details>

            <section class="ls-wizard-review" aria-labelledby="wizard-review-title" data-wizard-review-wrap hidden>
                <h3 class="ls-wizard-review-title" id="wizard-review-title">{{ __('app.workspace.wizard.review') }}</h3>
                <div data-wizard-review></div>
            </section>
        </section>

        <div class="ls-wizard-foot">
            <a href="{{ $cancelUrl }}" class="ls-btn ls-btn--ghost">{{ __('app.common.cancel') }}</a>
            <span class="ls-wizard-foot-spacer"></span>
            <x-ui.button variant="secondary" data-wizard-back hidden>{{ __('app.common.back') }}</x-ui.button>
            {{-- Editing: Save is the one primary action on every step, Continue is secondary. --}}
            <x-ui.button :variant="$editing ? 'secondary' : 'primary'" data-wizard-next hidden arrow>{{ __('app.workspace.wizard.continue') }}</x-ui.button>
            <x-ui.button type="submit" variant="primary" icon="check" data-wizard-submit>{{ $submitLabel }}</x-ui.button>
        </div>
    </div>
</form>

<script>
    (function () {
        var select = document.getElementById('room_type');
        var hint = document.getElementById('room_type_hint');
        if (!select || !hint) return;
        var sharedHint = @json(__('app.workspace.shared_room_hint'));
        var exclusiveHint = @json(__('app.workspace.exclusive_room_hint'));
        select.addEventListener('change', function () {
            hint.textContent = select.value === 'shared' ? sharedHint : exclusiveHint;
        });
    })();
</script>

<script>
(function () {
    // The wizard only shows/hides parts of one form and checks the visible
    // step before moving on. The browser's own constraint validation reads
    // the same limits as the server (required, min/max, maxlength); the
    // server re-validates everything on the single final submit.
    const form = document.querySelector('[data-room-wizard]');
    const panels = Array.from(form.querySelectorAll('[data-wizard-step]'));
    const total = panels.length;
    const tabs = Array.from(form.querySelectorAll('[data-wizard-goto]'));
    const backBtn = form.querySelector('[data-wizard-back]');
    const nextBtn = form.querySelector('[data-wizard-next]');
    const submitBtn = form.querySelector('[data-wizard-submit]');
    const live = form.querySelector('[data-wizard-live]');
    const editing = form.dataset.editing === '1';
    const T = @json($wizardI18n);
    const fill = (s, map) => Object.entries(map).reduce((out, [k, v]) => out.split(':' + k).join(v), s);
    const reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    let current = parseInt(form.dataset.startStep, 10) || 1;
    let reached = Math.max(current, parseInt(form.dataset.reached, 10) || 1);
    let submitting = false;

    form.classList.add('is-ready');
    form.querySelector('[data-wizard-progress]').hidden = false;

    function show(n, moveFocus) {
        current = n;
        reached = Math.max(reached, n);
        panels.forEach((p) => { p.hidden = parseInt(p.dataset.wizardStep, 10) !== n; });
        tabs.forEach((t) => {
            const s = parseInt(t.dataset.wizardGoto, 10);
            const li = t.closest('li');
            li.classList.toggle('is-current', s === n);
            li.classList.toggle('is-done', s < n);
            t.disabled = s > reached;
            if (s === n) t.setAttribute('aria-current', 'step'); else t.removeAttribute('aria-current');
        });
        backBtn.hidden = n === 1;
        nextBtn.hidden = n === total;
        submitBtn.hidden = !(editing || n === total);
        if (n === total) renderReview();
        live.textContent = fill(T.stepOf, { current: n, total: total }) + ': ' + T.titles[n - 1];
        if (moveFocus) {
            form.scrollIntoView({ block: 'start', behavior: reduceMotion ? 'auto' : 'smooth' });
            panels[n - 1].focus({ preventScroll: true });
        }
    }

    // --- Step checks -------------------------------------------------------
    function alertBox(n) { return panels[n - 1].querySelector('[data-wizard-alert]'); }
    function clearAlert(n) { const a = alertBox(n); a.hidden = true; a.textContent = ''; }

    function pricingProblem() {
        const model = (form.querySelector('input[name="pricing_model"]:checked') || {}).value || 'hourly';
        if (model === 'hourly') return null; // its price input is `required`, checked natively
        let rules;
        try { rules = JSON.parse(document.getElementById('pricing_rules').value || 'null'); } catch (e) { rules = null; }
        if (!rules) return T.errors.invalid;
        const usesDurations = model === 'duration' || model === 'people_duration';
        const usesPeople = model === 'people' || model === 'people_duration';
        if (usesDurations && !(rules.durations || []).length) return T.errors.durations;
        if (usesPeople && !(rules.people || []).length) return T.errors.people;
        const cells = [].concat(...(rules.prices || []).map((row) => row || []));
        if (!cells.length || cells.some((v) => v === '' || v === null || isNaN(Number(v)) || Number(v) < 0)) return T.errors.prices;
        return null;
    }

    // Returns null when step n is fine, else { field } or { message }.
    function problemIn(n) {
        const fields = Array.from(panels[n - 1].querySelectorAll('input, select, textarea')).filter((el) => el.willValidate);
        const bad = fields.find((el) => !el.checkValidity());
        if (bad) return { field: bad };
        if (n === 2) { const msg = pricingProblem(); if (msg) return { message: msg }; }
        return null;
    }

    function report(n, problem) {
        if (current !== n) show(n, false);
        if (problem.field) {
            problem.field.classList.add('is-invalid');
            problem.field.setAttribute('aria-invalid', 'true');
            problem.field.focus();
            problem.field.reportValidity();
        } else {
            const a = alertBox(n);
            a.textContent = problem.message;
            a.hidden = false;
            a.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
        }
    }

    // Check steps from..to-1; go to `to` only when they all pass.
    function goTo(to) {
        if (to <= current) { clearAlert(current); show(to, true); return; }
        for (let n = current; n < to; n++) {
            clearAlert(n);
            const problem = problemIn(n);
            if (problem) { report(n, problem); return; }
        }
        show(to, true);
    }

    nextBtn.addEventListener('click', () => goTo(current + 1));
    backBtn.addEventListener('click', () => goTo(current - 1));
    tabs.forEach((t) => t.addEventListener('click', () => goTo(parseInt(t.dataset.wizardGoto, 10))));

    form.addEventListener('input', (e) => {
        if (e.target.classList && e.target.classList.contains('is-invalid') && e.target.checkValidity()) {
            e.target.classList.remove('is-invalid');
            e.target.removeAttribute('aria-invalid');
        }
    });

    // A pricing message goes away (or changes) as soon as the owner fixes
    // what it pointed at, not only on the next Continue.
    ['input', 'change', 'click'].forEach((type) => panels[1].addEventListener(type, () => {
        const a = alertBox(2);
        if (a.hidden) return;
        setTimeout(() => {
            const msg = pricingProblem();
            if (!msg) clearAlert(2); else a.textContent = msg;
        }, 0);
    }));

    // Enter in a field never submits a half-filled wizard: it moves on a step
    // (steps 1–2) or does nothing (step 3 — only the submit button submits).
    // Editors that use Enter themselves (pricing "Add" boxes) handle it first.
    form.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' || e.defaultPrevented) return;
        const t = e.target;
        if (t.tagName !== 'INPUT' && t.tagName !== 'SELECT') return;
        if (t.tagName === 'INPUT' && (t.type === 'submit' || t.type === 'button')) return;
        e.preventDefault();
        if (current < total) goTo(current + 1);
    });

    form.addEventListener('submit', (e) => {
        // Adding: the last step submits. Editing: Save works from any step.
        if (!editing && current < total) { e.preventDefault(); goTo(current + 1); return; }
        if (submitting) { e.preventDefault(); return; }
        for (let n = 1; n <= total; n++) {
            const problem = problemIn(n);
            if (problem) { e.preventDefault(); report(n, problem); return; }
        }
        // One request only: the button stays busy until the page is replaced.
        submitting = true;
        submitBtn.setAttribute('aria-disabled', 'true');
        submitBtn.classList.add('is-busy');
        form.setAttribute('aria-busy', 'true');
        submitBtn.querySelector('span').textContent = T.busy;
    });
    // Back/forward cache: a page restored after navigating away is usable again.
    window.addEventListener('pageshow', (e) => {
        if (!e.persisted) return;
        submitting = false;
        submitBtn.removeAttribute('aria-disabled');
        submitBtn.classList.remove('is-busy');
        form.removeAttribute('aria-busy');
        submitBtn.querySelector('span').textContent = T.submit;
    });

    // --- Extras: "N added" on each collapsed section --------------------------
    function rowsOf(field) {
        try { const v = JSON.parse(document.getElementById(field).value || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; }
    }
    function countLabel(n) { return n === 0 ? T.count[0] : (n === 1 ? T.count[1] : fill(T.count.n, { count: n })); }
    function refreshCounts() {
        form.querySelectorAll('[data-extra-count]').forEach((el) => { el.textContent = countLabel(rowsOf(el.dataset.extraCount).length); });
    }
    let pending = null;
    const refreshSoon = () => { clearTimeout(pending); pending = setTimeout(() => { refreshCounts(); if (current === total) renderReview(); }, 50); };
    panels[total - 1].addEventListener('input', refreshSoon);
    panels[total - 1].addEventListener('change', refreshSoon);
    panels[total - 1].addEventListener('click', refreshSoon);

    // --- Review ----------------------------------------------------------------
    const money = (v) => {
        const n = Number(v);
        if (v === '' || v === null || isNaN(n)) return '—';
        return T.currencyAfter ? n.toFixed(2) + ' ' + T.currency : T.currency + ' ' + n.toFixed(2);
    };
    const el = (tag, cls, text) => { const x = document.createElement(tag); if (cls) x.className = cls; if (text !== undefined) x.textContent = text; return x; };

    function section(title, step, body) {
        const s = el('div', 'ls-wizard-review-section');
        const head = el('div', 'ls-wizard-review-head');
        head.append(el('h4', null, title));
        const edit = el('button', 'ls-link ls-wizard-review-edit', T.edit);
        edit.type = 'button';
        edit.setAttribute('aria-label', T.edit + ': ' + title);
        edit.addEventListener('click', () => goTo(step));
        head.append(edit);
        s.append(head, body);
        return s;
    }
    function pairs(rows) {
        const dl = el('dl', 'ls-wizard-review-list');
        rows.forEach(([k, v]) => { const d = el('div'); d.append(el('dt', null, k), el('dd', null, v)); dl.append(d); });
        return dl;
    }
    function list(items) {
        if (!items.length) return el('p', 'ls-hint', T.none);
        const ul = el('ul', 'ls-wizard-review-items');
        items.forEach((t) => ul.append(el('li', null, t)));
        return ul;
    }

    function renderReview() {
        const box = form.querySelector('[data-wizard-review]');
        box.innerHTML = '';

        const type = document.getElementById('room_type');
        const details = [
            [T.labels.name, document.getElementById('room_name').value.trim() || '—'],
            [T.labels.type, type.value ? type.options[type.selectedIndex].text : '—'],
            [T.labels.capacity, document.getElementById('room_capacity').value || '—'],
        ];
        const desc = document.getElementById('room_description').value.trim();
        if (desc) details.push([T.labels.description, desc]);
        box.append(section(T.sections.details, 1, pairs(details)));

        // Pricing: the chosen model, then exactly what step 2 holds.
        const modelInput = form.querySelector('input[name="pricing_model"]:checked');
        const model = modelInput ? modelInput.value : 'hourly';
        const modelTitle = modelInput ? modelInput.closest('label').querySelector('.ls-pricing-model-title').textContent.trim() : '';
        const pricing = el('div');
        const rows = [[T.labels.model, modelTitle]];
        if (model === 'hourly') {
            rows.push([T.labels.price, money(document.getElementById('price_per_hour').value) + ' ' + T.perHour]);
            if (type.value === 'shared') {
                const unit = form.querySelector('.billing-unit-radio:checked');
                if (unit) rows.push([T.labels.billing, unit.closest('label').querySelector('b').textContent.trim()]);
                const buffer = parseInt(document.getElementById('billing_buffer_minutes').value, 10) || 0;
                if (unit && unit.value !== 'minute' && buffer > 0) rows.push([T.labels.buffer, fill(T.labels.bufferMin, { count: buffer })]);
            }
            pricing.append(pairs(rows));
        } else {
            pricing.append(pairs(rows));
            // The step-2 price grid, read-only: same labels, typed prices as
            // text — in the same sideways-scrolling frame, so a wide grid is
            // never cut off on a phone.
            const grid = form.querySelector('[data-grid-wrap] table');
            if (grid) {
                const copy = grid.cloneNode(true);
                copy.classList.add('ls-wizard-review-grid');
                copy.querySelectorAll('input').forEach((i) => i.replaceWith(el('span', 'ls-num', money(i.value))));
                const frame = el('div', 'ls-pricing-grid-wrap ls-wizard-review-grid-wrap');
                frame.append(copy);
                pricing.append(frame);
            }
        }
        box.append(section(T.sections.pricing, 2, pricing));

        box.append(section(T.sections.profiles, 3, list(rowsOf('pricing_profiles').map((p) =>
            [p.name || '—', money(p.price_per_hour) + ' ' + T.perHour].concat(p.is_active === false ? [T.inactive] : []).join(' · ')))));

        box.append(section(T.sections.plans, 3, list(rowsOf('plans').map((p) => {
            const people = Number(p.people);
            const who = p.people === '' || isNaN(people) ? '—' : (people === 1 ? T.person1 : fill(T.peopleN, { count: people }));
            const hours = p.minutes ? +(Number(p.minutes) / 60).toFixed(2) : null;
            const dur = p.full_day ? T.fullDay : (hours === null ? '—' : (hours === 1 ? T.hour1 : fill(T.hoursN, { count: hours })));
            return [p.name || T.labels.plan, who, dur, money(p.price)].join(' · ');
        }))));

        form.querySelector('[data-wizard-review-wrap]').hidden = false;
    }

    refreshCounts();
    show(current, false);

    // Back from a failed submit: the step with the first error is open —
    // bring its first message into view and put focus on its field.
    if (form.dataset.hasErrors) {
        const panel = panels[current - 1];
        const field = panel.querySelector('[aria-invalid="true"]');
        const message = panel.querySelector('.ls-error');
        (field || message || panel).scrollIntoView({ block: 'center' });
        (field || panel).focus({ preventScroll: true });
    }
})();
</script>
