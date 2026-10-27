{{--
    Room form → Custom Plans editor (fixed-price packages beside any pricing
    model). Rows <-> the hidden plans JSON; RoomController re-validates it
    (PlanInput). Placed on the room wizard's last step (_wizard.blade.php)
    with $showHeading = false — its section title is the heading.
    Expects: $room (edit) or null (create).
--}}
@php
    $room = $room ?? null;
    $showHeading = $showHeading ?? true;
    // Custom Plans: fixed-price packages that sit beside whichever pricing model is chosen.
    $plansJson = old('plans', json_encode(($room?->plans ?? collect())->map(fn ($p) => [
        'id' => $p->id, 'name' => $p->name, 'people' => $p->people,
        'minutes' => $p->duration_minutes, 'full_day' => $p->is_full_day, 'price' => (float) $p->price,
    ])->values()));
    $planI18n = [
        'name' => __('app.plans.name'), 'namePh' => __('app.plans.name_placeholder'), 'people' => __('app.plans.people'),
        'hours' => __('app.plans.hours'), 'fullDay' => __('app.pricing.full_day'), 'price' => __('app.plans.price'),
        'remove' => __('app.plans.remove', ['n' => ':n']), 'currency' => app()->getLocale() === 'ar' ? 'ج.م' : 'EGP',
    ];
@endphp
    {{-- Custom Plans — shown for every pricing model; they never replace it. --}}
    <div class="ls-plans" data-plans aria-labelledby="plans-title">
        <div class="ls-plans-head">
            @if ($showHeading)
                <h3 class="ls-pricing-title" id="plans-title">{{ __('app.plans.section') }}</h3>
            @endif
            <button type="button" class="ls-btn ls-btn--tonal ls-btn--sm" data-plan-add><x-ui.icon name="plus" />{{ __('app.plans.add') }}</button>
        </div>
        <p class="ls-hint">{{ __('app.plans.hint') }}</p>
        <div class="ls-plans-cols" aria-hidden="true">
            <span>{{ __('app.plans.name') }}</span><span>{{ __('app.plans.people') }}</span><span>{{ __('app.plans.duration') }}</span><span>{{ __('app.plans.price') }}</span><span></span>
        </div>
        <div class="ls-plans-list" data-plans-list role="list" aria-labelledby="plans-title"></div>
        <p class="ls-hint ls-plans-empty" data-plans-empty>{{ __('app.plans.empty') }}</p>
        <input type="hidden" name="plans" id="plans" value="{{ $plansJson }}">
        @error('plans')
            @foreach ($errors->get('plans') as $msg)
                <p class="ls-error">{{ $msg }}</p>
            @endforeach
        @enderror
    </div>

<script>
(function () {
    // Custom Plans editor: rows <-> hidden JSON. The server re-validates everything (PlanInput).
    const box = document.querySelector('[data-plans]');
    const list = box.querySelector('[data-plans-list]');
    const hidden = document.getElementById('plans');
    const T = @json($planI18n);
    let plans = [];
    try { plans = JSON.parse(hidden.value || '[]') || []; } catch (e) { plans = []; }

    const serialise = () => {
        hidden.value = JSON.stringify(plans.map((p) => ({
            id: p.id || null, name: p.name || '', people: p.people === '' ? '' : Number(p.people),
            full_day: !!p.full_day, minutes: p.full_day ? null : (p.minutes === '' || p.minutes == null ? '' : Number(p.minutes)),
            price: p.price === '' ? '' : Number(p.price),
        })));
    };

    function field(labelText, control) {
        const wrap = document.createElement('div');
        wrap.className = 'ls-plan-field';
        const l = document.createElement('span'); l.className = 'ls-plan-label'; l.setAttribute('aria-hidden', 'true'); l.textContent = labelText;
        wrap.append(l, control);
        return wrap;
    }
    function input(type, value, attrs, onInput) {
        const el = document.createElement('input');
        el.type = type; el.className = 'ls-input'; el.value = value ?? '';
        Object.entries(attrs).forEach(([k, v]) => el.setAttribute(k, v));
        el.addEventListener('input', () => { onInput(el.value); serialise(); });
        return el;
    }

    function render() {
        list.innerHTML = '';
        plans.forEach((p, i) => {
            const n = i + 1;
            const row = document.createElement('div');
            row.className = 'ls-plan-row'; row.setAttribute('role', 'listitem');

            const name = input('text', p.name, { maxlength: 80, placeholder: T.namePh, 'aria-label': T.name + ' ' + n }, (v) => { p.name = v; });
            const people = input('number', p.people, { min: 1, max: 999, step: 1, inputmode: 'numeric', 'aria-label': T.people + ' ' + n }, (v) => { p.people = v; });

            const hours = input('number', p.full_day ? '' : (p.minutes ? +(p.minutes / 60).toFixed(2) : ''),
                { min: 0.25, max: 24, step: 0.25, inputmode: 'decimal', 'aria-label': T.hours + ' ' + n },
                (v) => { p.minutes = v === '' ? '' : Math.round(parseFloat(v) * 60); });
            hours.disabled = !!p.full_day;
            const fdLabel = document.createElement('label'); fdLabel.className = 'ls-plan-fullday';
            const fd = document.createElement('input'); fd.type = 'checkbox'; fd.checked = !!p.full_day;
            fd.setAttribute('aria-label', T.fullDay + ' ' + n);
            fd.addEventListener('change', () => { p.full_day = fd.checked; hours.disabled = fd.checked; if (fd.checked) hours.value = ''; serialise(); });
            const fdText = document.createElement('span'); fdText.textContent = T.fullDay; fdText.setAttribute('aria-hidden', 'true');
            fdLabel.append(fd, fdText);
            const dur = document.createElement('div'); dur.className = 'ls-plan-dur'; dur.append(hours, fdLabel);

            const priceWrap = document.createElement('div'); priceWrap.className = 'ls-input-affix';
            const cur = document.createElement('span'); cur.setAttribute('aria-hidden', 'true'); cur.textContent = T.currency;
            priceWrap.append(cur, input('number', p.price, { min: 0, step: 0.01, inputmode: 'decimal', 'aria-label': T.price + ' ' + n }, (v) => { p.price = v; }));

            const rm = document.createElement('button');
            rm.type = 'button'; rm.className = 'ls-btn ls-btn--danger-quiet ls-btn--sm ls-btn--icon ls-plan-remove';
            rm.setAttribute('aria-label', T.remove.replace(':n', n)); rm.title = T.remove.replace(':n', n);
            rm.innerHTML = '<svg class="ls-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
            rm.addEventListener('click', () => { plans.splice(i, 1); render(); box.querySelector('[data-plan-add]').focus(); });

            row.append(field(T.name, name), field(T.people, people), field(T.hours, dur), field(T.price, priceWrap), rm);
            list.appendChild(row);
        });
        box.querySelector('[data-plans-empty]').hidden = plans.length > 0;
        box.querySelector('.ls-plans-cols').hidden = plans.length === 0;
        serialise();
    }

    box.querySelector('[data-plan-add]').addEventListener('click', () => {
        plans.push({ id: null, name: '', people: '', minutes: '', full_day: false, price: '' });
        render();
        list.lastElementChild.querySelector('input').focus();
    });
    render();
})();
</script>
