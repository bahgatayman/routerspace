{{--
    Room form → Pricing. One question ("How do you charge for this room?"),
    four answers, then only the inputs that answer needs:

      Per hour           → the hourly price (+ session billing for shared rooms)
      By duration        → the owner's own durations, one price each
      By people          → the owner's own group sizes, one hourly price each
      People + duration  → both lists, edited as a price grid

    The editor only collects data. Every price is computed server-side by
    RoomPricingService, and the submitted rules are re-validated there
    (PricingRules::fromInput) — nothing here decides what a booking costs.

    Expects: $room (edit) or null (create).
--}}
@php
    $room = $room ?? null;
    $model = old('pricing_model', $room?->pricing_model ?? 'hourly');
    $rulesJson = old('pricing_rules', $room?->pricing_rules ? json_encode($room->pricing_rules) : '');
    $isRtl = app()->getLocale() === 'ar';
    $bufferI18n = [
        'h' => __('app.ui.unit_h'), 'm' => __('app.ui.unit_m'),
        'example' => __('app.workspace.billing_buffer_example'),
        'exampleNone' => __('app.workspace.billing_buffer_example_none'),
    ];
    $i18n = [
        'hour1' => trans_choice('app.pricing.hours', 1, ['count' => 1]),
        'hoursN' => trans_choice('app.pricing.hours', 2, ['count' => ':count']),
        'minutes' => __('app.pricing.minutes', ['count' => ':count']),
        'person1' => trans_choice('app.pricing.people_exact', 1, ['count' => 1]),
        'peopleN' => trans_choice('app.pricing.people_exact', 2, ['count' => ':count']),
        'peopleRange' => __('app.pricing.people_range', ['min' => ':min', 'max' => ':max']),
        'fullDay' => __('app.pricing.full_day'),
        'price' => __('app.pricing.price'),
        'pricePerHour' => __('app.pricing.price_per_hour'),
        'durations' => __('app.pricing.durations'),
        'people' => __('app.pricing.people'),
        'cell' => __('app.pricing.cell_aria', ['people' => ':people', 'duration' => ':duration']),
        'remove' => __('app.pricing.remove', ['label' => ':label']),
        'caption' => __('app.pricing.grid_caption'),
    ];
@endphp
<section class="ls-pricing" data-pricing aria-labelledby="pricing-title">
    <h2 class="ls-pricing-title" id="pricing-title">{{ __('app.pricing.section') }}</h2>
    <p class="ls-pricing-question" id="pricing-question">{{ __('app.pricing.question') }}</p>

    <div class="ls-pricing-models" role="radiogroup" aria-labelledby="pricing-question">
        @foreach (\App\Support\Pricing\PricingRules::MODELS as $m)
            <label class="ls-pricing-model">
                <input type="radio" name="pricing_model" value="{{ $m }}" {{ $model === $m ? 'checked' : '' }}>
                <span class="ls-pricing-model-title">{{ __('app.pricing.models.'.$m.'.title') }}</span>
                <span class="ls-pricing-model-desc">{{ __('app.pricing.models.'.$m.'.desc') }}</span>
            </label>
        @endforeach
    </div>
    @error('pricing_model') <p class="ls-error">{{ $message }}</p> @enderror

    {{-- Per hour --}}
    <div class="ls-pricing-panel" data-pricing-panel="hourly">
        <div class="ls-field">
            <label class="ls-label" for="price_per_hour">{{ __('app.workspace.price_per_hour') }} <span class="ls-req" aria-hidden="true">*</span></label>
            <div class="ls-input-affix">
                <span aria-hidden="true">{{ $isRtl ? 'ج.م' : 'EGP' }}</span>
                <input type="number" name="price_per_hour" id="price_per_hour" class="ls-input" step="0.01" min="0"
                       value="{{ old('price_per_hour', $room?->price_per_hour ?? '0.00') }}">
            </div>
            @error('price_per_hour') <p class="ls-error">{{ $message }}</p> @enderror
        </div>

        <div id="billing-unit-field" class="ls-field" hidden>
            <span class="ls-label">{{ __('app.workspace.billing_unit') }}</span>
            <div class="ls-pricing-units">
                @foreach (['minute' => 1, 'half_hour' => 30, 'hour' => 60] as $unitKey => $unitMinutes)
                    <label class="ls-pricing-unit">
                        <input type="radio" name="billing_unit" value="{{ $unitKey }}" class="billing-unit-radio"
                            {{ old('billing_unit', $room?->billing_unit ?? 'minute') === $unitKey ? 'checked' : '' }}>
                        <span>
                            <b>{{ __('app.billing_unit.'.$unitKey) }}</b>
                            <span class="ls-hint billing-example" data-unit-minutes="{{ $unitMinutes }}">&nbsp;</span>
                        </span>
                    </label>
                @endforeach
            </div>
            <p class="ls-hint">{{ __('app.workspace.billing_unit_block_hint') }}</p>
            @error('billing_unit') <p class="ls-error">{{ $message }}</p> @enderror

            {{-- Billing Buffer: grace minutes past each block before the next one is charged (block billing only). --}}
            @php
                $bufferValue = (int) old('billing_buffer_minutes', $room?->billing_buffer_minutes ?? 0);
                $bufferPresets = \App\Services\SharedSessionBillingService::BUFFER_PRESETS;
                $bufferIsCustom = ! in_array($bufferValue, $bufferPresets, true);
            @endphp
            <div class="ls-buffer" id="billing-buffer-field" hidden>
                <span class="ls-label" id="billing-buffer-label">{{ __('app.workspace.billing_buffer') }}</span>
                <p class="ls-hint">{{ __('app.workspace.billing_buffer_hint') }}</p>
                <input type="hidden" name="billing_buffer_minutes" id="billing_buffer_minutes" value="{{ $bufferValue }}">
                <div class="ls-chips" role="group" aria-labelledby="billing-buffer-label">
                    @foreach ($bufferPresets as $preset)
                        <button type="button" class="ls-chip {{ ! $bufferIsCustom && $bufferValue === $preset ? 'is-active' : '' }}" data-buffer="{{ $preset }}">
                            {{ $preset === 0 ? __('app.workspace.billing_buffer_none') : __('app.workspace.billing_buffer_min', ['count' => $preset]) }}
                        </button>
                    @endforeach
                    <button type="button" class="ls-chip {{ $bufferIsCustom ? 'is-active' : '' }}" data-buffer="custom">{{ __('app.workspace.billing_buffer_custom') }}</button>
                </div>
                <div class="ls-buffer-custom" id="billing-buffer-custom" @if (! $bufferIsCustom) hidden @endif>
                    <div class="ls-input-affix ls-input-affix--end">
                        <input type="number" id="billing-buffer-custom-input" class="ls-input" min="0" max="59" step="1" inputmode="numeric"
                               value="{{ $bufferIsCustom ? $bufferValue : '' }}" aria-label="{{ __('app.workspace.billing_buffer') }}">
                        <span aria-hidden="true">{{ __('app.workspace.billing_buffer_unit') }}</span>
                    </div>
                </div>
                <p class="ls-hint ls-buffer-example" id="billing-buffer-example" aria-live="polite"></p>
                @error('billing_buffer_minutes') <p class="ls-error">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    {{-- Rule-based models --}}
    <div class="ls-pricing-panel" data-pricing-panel="rules" hidden>
        <div class="ls-pricing-dim" data-dim="people">
            <span class="ls-label" id="pricing-people-label">{{ __('app.pricing.people') }}</span>
            <div class="ls-pricing-tokens" data-tokens="people" role="list" aria-labelledby="pricing-people-label"></div>
            <p class="ls-hint" data-empty="people">{{ __('app.pricing.empty_people') }}</p>
            <div class="ls-pricing-add" data-add-form="people" hidden>
                <label class="ls-label" for="pricing-add-people">{{ __('app.pricing.people_up_to') }}</label>
                <div class="ls-pricing-add-row">
                    <input type="number" id="pricing-add-people" class="ls-input" min="1" max="999" step="1" inputmode="numeric">
                    <button type="button" class="ls-btn ls-btn--tonal ls-btn--sm" data-add-confirm="people">{{ __('app.pricing.add') }}</button>
                    <button type="button" class="ls-btn ls-btn--ghost ls-btn--sm" data-add-cancel="people">{{ __('app.pricing.cancel') }}</button>
                </div>
            </div>
            <p class="ls-hint">{{ __('app.pricing.people_hint') }}</p>
        </div>

        <div class="ls-pricing-dim" data-dim="durations">
            <span class="ls-label" id="pricing-durations-label">{{ __('app.pricing.durations') }}</span>
            <div class="ls-pricing-tokens" data-tokens="durations" role="list" aria-labelledby="pricing-durations-label"></div>
            <p class="ls-hint" data-empty="durations">{{ __('app.pricing.empty_durations') }}</p>
            <div class="ls-pricing-add" data-add-form="durations" hidden>
                <label class="ls-label" for="pricing-add-hours">{{ __('app.pricing.hours_label') }}</label>
                <div class="ls-pricing-add-row">
                    <input type="number" id="pricing-add-hours" class="ls-input" min="0.25" max="24" step="0.25" inputmode="decimal">
                    <button type="button" class="ls-btn ls-btn--tonal ls-btn--sm" data-add-confirm="durations">{{ __('app.pricing.add') }}</button>
                    <button type="button" class="ls-btn ls-btn--secondary ls-btn--sm" data-add-fullday>{{ __('app.pricing.full_day') }}</button>
                    <button type="button" class="ls-btn ls-btn--ghost ls-btn--sm" data-add-cancel="durations">{{ __('app.pricing.cancel') }}</button>
                </div>
            </div>
            <p class="ls-hint">{{ __('app.pricing.duration_hint') }}</p>
        </div>

        <div class="ls-pricing-grid-wrap" data-grid-wrap></div>
        <input type="hidden" name="pricing_rules" id="pricing_rules" value="{{ $rulesJson }}">
        @error('pricing_rules')
            @foreach ($errors->get('pricing_rules') as $msg)
                <p class="ls-error">{{ $msg }}</p>
            @endforeach
        @enderror
    </div>
    {{-- Pricing Profiles and Custom Plans have their own partials
         (_pricing-profiles / _pricing-plans), placed by the room wizard. --}}
</section>

<script>
(function () {
    const root = document.querySelector('[data-pricing]');
    const T = @json($i18n);
    const hidden = document.getElementById('pricing_rules');
    const typeSelect = document.getElementById('room_type');
    const priceInput = document.getElementById('price_per_hour');
    const billingField = document.getElementById('billing-unit-field');
    const exampleTemplate = @json(__('app.workspace.billing_example'));
    const panels = { hourly: root.querySelector('[data-pricing-panel="hourly"]'), rules: root.querySelector('[data-pricing-panel="rules"]') };
    const gridWrap = root.querySelector('[data-grid-wrap]');
    const fill = (s, map) => Object.entries(map).reduce((out, [k, v]) => out.split(':' + k).join(v), s);

    // --- State: owner-defined options + prices kept by option key, so adding
    // or removing an option never scrambles prices already typed in. ---
    let durations = [], people = [], prices = {};
    const dKey = d => d.full_day ? 'fd' : String(d.minutes);
    const pKey = p => String(p.max);
    const cellKey = (r, c) => r + '|' + c;

    try {
        const saved = hidden.value ? JSON.parse(hidden.value) : null;
        if (saved) {
            durations = (saved.durations || []).map(d => d.full_day ? { full_day: true } : { minutes: +d.minutes });
            people = (saved.people || []).map(p => ({ max: +p.max }));
            const rows = people.length ? people.map(pKey) : ['*'];
            const cols = durations.length ? durations.map(dKey) : ['*'];
            (saved.prices || []).forEach((row, r) => (row || []).forEach((v, c) => {
                if (rows[r] !== undefined && cols[c] !== undefined && v !== null && v !== '') prices[cellKey(rows[r], cols[c])] = String(v);
            }));
        }
    } catch (e) { /* unreadable old value: start clean, the server re-validates anyway */ }

    const model = () => root.querySelector('input[name="pricing_model"]:checked')?.value || 'hourly';
    const usesDurations = () => ['duration', 'people_duration'].includes(model());
    const usesPeople = () => ['people', 'people_duration'].includes(model());

    function sortAll() {
        durations.sort((a, b) => (a.full_day ? 1e9 : a.minutes) - (b.full_day ? 1e9 : b.minutes));
        people.sort((a, b) => a.max - b.max);
    }

    function durationLabel(d) {
        if (d.full_day) return T.fullDay;
        if (d.minutes < 60) return fill(T.minutes, { count: d.minutes });
        if (d.minutes === 60) return T.hour1;
        return fill(T.hoursN, { count: String(+(d.minutes / 60).toFixed(2)) });
    }

    function peopleLabel(i) {
        const min = i === 0 ? 1 : people[i - 1].max + 1, max = people[i].max;
        if (min === max) return max === 1 ? T.person1 : fill(T.peopleN, { count: max });
        return fill(T.peopleRange, { min, max });
    }

    // --- Serialise to the exact grid PricingRules::fromInput() expects. ---
    function serialise() {
        if (model() === 'hourly') { hidden.value = ''; return; }
        const rows = usesPeople() ? people.map(pKey) : ['*'];
        const cols = usesDurations() ? durations.map(dKey) : ['*'];
        hidden.value = JSON.stringify({
            durations: usesDurations() ? durations : [],
            people: usesPeople() ? people : [],
            prices: rows.map(r => cols.map(c => prices[cellKey(r, c)] ?? '')),
        });
    }

    function tokens(kind) {
        const list = kind === 'people' ? people : durations;
        const box = root.querySelector(`[data-tokens="${kind}"]`);
        box.innerHTML = '';
        list.forEach((item, i) => {
            const label = kind === 'people' ? peopleLabel(i) : durationLabel(item);
            const el = document.createElement('span');
            el.className = 'ls-token';
            el.setAttribute('role', 'listitem');
            el.innerHTML = `<span></span><button type="button" class="ls-token-x"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>`;
            el.firstChild.textContent = label;
            const x = el.querySelector('button');
            x.setAttribute('aria-label', fill(T.remove, { label }));
            x.addEventListener('click', () => { list.splice(i, 1); render(); root.querySelector(`[data-add-open="${kind}"]`).focus(); });
            box.appendChild(el);
        });
        const add = document.createElement('button');
        add.type = 'button';
        add.className = 'ls-token ls-token--add';
        add.dataset.addOpen = kind;
        add.textContent = '+ ' + (kind === 'people' ? @json(__('app.pricing.add_people')) : @json(__('app.pricing.add_duration')));
        add.addEventListener('click', () => openAdd(kind));
        box.appendChild(add);
        root.querySelector(`[data-empty="${kind}"]`).hidden = list.length > 0;
    }

    function priceInputEl(r, c, aria) {
        const input = document.createElement('input');
        input.type = 'number'; input.min = '0'; input.step = '0.01'; input.inputMode = 'decimal';
        input.className = 'ls-input ls-price-input';
        input.value = prices[cellKey(r, c)] ?? '';
        input.setAttribute('aria-label', aria);
        input.addEventListener('input', () => { prices[cellKey(r, c)] = input.value; serialise(); });
        return input;
    }

    function grid() {
        gridWrap.innerHTML = '';
        const m = model();
        if (m === 'hourly') return;
        const rows = usesPeople() ? people.map((p, i) => ({ key: pKey(p), label: peopleLabel(i) })) : [{ key: '*', label: '' }];
        const cols = usesDurations() ? durations.map(d => ({ key: dKey(d), label: durationLabel(d) })) : [{ key: '*', label: T.pricePerHour }];
        if ((usesPeople() && !people.length) || (usesDurations() && !durations.length)) return;

        const table = document.createElement('table');
        table.className = 'ls-price-grid';
        const cap = document.createElement('caption'); cap.className = 'ls-sr'; cap.textContent = T.caption; table.appendChild(cap);

        if (m === 'duration') {
            // One price per duration: a simple two-column list reads best, even on a phone.
            table.innerHTML += `<thead><tr><th scope="col">${esc(T.durations)}</th><th scope="col">${esc(T.price)}</th></tr></thead>`;
            const tb = document.createElement('tbody');
            cols.forEach(c => {
                const tr = document.createElement('tr');
                tr.innerHTML = `<th scope="row">${esc(c.label)}</th><td></td>`;
                tr.lastChild.appendChild(priceInputEl('*', c.key, `${T.price} — ${c.label}`));
                tb.appendChild(tr);
            });
            table.appendChild(tb);
        } else {
            const head = `<thead><tr><th scope="col">${esc(T.people)}</th>${cols.map(c => `<th scope="col">${esc(c.label)}</th>`).join('')}</tr></thead>`;
            table.innerHTML += head;
            const tb = document.createElement('tbody');
            rows.forEach(r => {
                const tr = document.createElement('tr');
                const th = document.createElement('th'); th.scope = 'row'; th.textContent = r.label; tr.appendChild(th);
                cols.forEach(c => {
                    const td = document.createElement('td');
                    td.appendChild(priceInputEl(r.key, c.key, fill(T.cell, { people: r.label, duration: c.label })));
                    tr.appendChild(td);
                });
                tb.appendChild(tr);
            });
            table.appendChild(tb);
        }
        gridWrap.appendChild(table);
    }

    function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function openAdd(kind) {
        const form = root.querySelector(`[data-add-form="${kind}"]`);
        form.hidden = false;
        form.querySelector('input').value = '';
        form.querySelector('input').focus();
    }
    function closeAdd(kind, refocus = true) {
        root.querySelector(`[data-add-form="${kind}"]`).hidden = true;
        if (refocus) root.querySelector(`[data-add-open="${kind}"]`)?.focus();
    }
    function confirmAdd(kind) {
        const input = root.querySelector(`[data-add-form="${kind}"] input`);
        if (kind === 'people') {
            const max = parseInt(input.value, 10);
            if (!(max >= 1 && max <= 999)) { input.focus(); return; }
            if (!people.some(p => p.max === max)) people.push({ max });
        } else {
            const minutes = Math.round(parseFloat(input.value) * 60);
            if (!(minutes >= 15 && minutes <= 1440)) { input.focus(); return; }
            if (!durations.some(d => !d.full_day && d.minutes === minutes)) durations.push({ minutes });
        }
        render();
        closeAdd(kind);
    }
    ['people', 'durations'].forEach(kind => {
        root.querySelector(`[data-add-confirm="${kind}"]`).addEventListener('click', () => confirmAdd(kind));
        root.querySelector(`[data-add-cancel="${kind}"]`).addEventListener('click', () => closeAdd(kind));
        root.querySelector(`[data-add-form="${kind}"] input`).addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); confirmAdd(kind); }
            if (e.key === 'Escape') { e.preventDefault(); closeAdd(kind); }
        });
    });
    root.querySelector('[data-add-fullday]').addEventListener('click', () => {
        if (!durations.some(d => d.full_day)) durations.push({ full_day: true });
        render();
        closeAdd('durations');
    });

    function toggleBillingField() {
        billingField.hidden = !(typeSelect && typeSelect.value === 'shared' && model() === 'hourly');
        syncBuffer();
    }

    // --- Billing Buffer (grace period) — shown for block billing only; the
    // example mirrors SharedSessionBillingService (whole-minute grace). ---
    const bufferField = document.getElementById('billing-buffer-field');
    const bufferInput = document.getElementById('billing_buffer_minutes');
    const bufferCustom = document.getElementById('billing-buffer-custom');
    const bufferCustomInput = document.getElementById('billing-buffer-custom-input');
    const BT = @json($bufferI18n);
    const unitMinutes = () => {
        const r = root.querySelector('.billing-unit-radio:checked');
        return r ? ({ minute: 1, half_hour: 30, hour: 60 })[r.value] || 1 : 1;
    };
    const fmt = (m) => { const h = Math.floor(m / 60), r = m % 60; return (h ? h + BT.h : '') + (h && r ? ' ' : '') + (r || !h ? r + BT.m : ''); };
    function syncBuffer() {
        const unit = unitMinutes();
        bufferField.hidden = billingField.hidden || unit === 1;
        bufferCustomInput.max = unit - 1;
        bufferField.querySelectorAll('[data-buffer]').forEach(b => {
            if (b.dataset.buffer !== 'custom') b.disabled = parseInt(b.dataset.buffer, 10) >= unit;
        });
        let v = parseInt(bufferInput.value, 10) || 0;
        if (v >= unit) { v = 0; bufferInput.value = 0; }
        const ex = document.getElementById('billing-buffer-example');
        ex.textContent = v > 0
            ? BT.example.replace(':limit', fmt(unit + v)).replace(':one', fmt(unit)).replace(':next', fmt(unit + v + 1)).replace(':two', fmt(unit * 2))
            : BT.exampleNone;
    }
    bufferField.querySelectorAll('[data-buffer]').forEach(b => b.addEventListener('click', () => {
        bufferField.querySelectorAll('[data-buffer]').forEach(x => x.classList.toggle('is-active', x === b));
        const custom = b.dataset.buffer === 'custom';
        bufferCustom.hidden = !custom;
        if (custom) { bufferCustomInput.focus(); bufferInput.value = parseInt(bufferCustomInput.value, 10) || 0; }
        else bufferInput.value = b.dataset.buffer;
        syncBuffer();
    }));
    bufferCustomInput.addEventListener('input', () => {
        const v = Math.max(0, Math.min(unitMinutes() - 1, parseInt(bufferCustomInput.value, 10) || 0));
        bufferInput.value = v;
        syncBuffer();
    });
    root.querySelectorAll('.billing-unit-radio').forEach(r => r.addEventListener('change', syncBuffer));

    // Every started block, rounded up — mirrors SharedSessionBillingService for
    // a fixed 10-minute example so the owner sees each option's consequence.
    function updateExamples() {
        const price = parseFloat(priceInput.value) || 100;
        root.querySelectorAll('.billing-example').forEach(el => {
            const unitMinutes = parseInt(el.dataset.unitMinutes, 10);
            const total = (Math.ceil(10 / unitMinutes) * unitMinutes / 60) * price;
            el.textContent = exampleTemplate.replace(':minutes', 10).replace(':price', total.toFixed(2));
        });
    }

    function render() {
        sortAll();
        const m = model();
        panels.hourly.hidden = m !== 'hourly';
        panels.rules.hidden = m === 'hourly';
        priceInput.required = m === 'hourly';
        priceInput.disabled = m !== 'hourly';
        root.querySelector('[data-dim="people"]').hidden = !usesPeople();
        root.querySelector('[data-dim="durations"]').hidden = !usesDurations();
        tokens('people');
        tokens('durations');
        grid();
        serialise();
        toggleBillingField();
    }

    root.querySelectorAll('input[name="pricing_model"]').forEach(r => r.addEventListener('change', render));
    if (typeSelect) typeSelect.addEventListener('change', toggleBillingField);
    priceInput.addEventListener('input', updateExamples);
    updateExamples();
    render();
})();
</script>
