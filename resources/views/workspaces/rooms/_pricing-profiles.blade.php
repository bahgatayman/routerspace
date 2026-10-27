{{--
    Room form → Pricing Profiles editor (optional alternative hourly rates).
    Rows <-> the hidden pricing_profiles JSON; RoomController re-validates it
    (PricingProfileInput). Placed on the room wizard's last step
    (_wizard.blade.php) with $showHeading = false — its section title is the heading.
    Expects: $room (edit) or null (create).
--}}
@php
    $room = $room ?? null;
    $showHeading = $showHeading ?? true;
    // Pricing Profiles: optional named hourly rates ("Photography — 700/hr"), active and inactive.
    $profilesJson = old('pricing_profiles', json_encode(($room?->pricingProfiles ?? collect())->map(fn ($p) => [
        'id' => $p->id, 'name' => $p->name, 'price_per_hour' => (float) $p->price_per_hour, 'is_active' => (bool) $p->is_active,
    ])->values()));
    $profileI18n = [
        'name' => __('app.pricing_profiles.name'), 'namePh' => __('app.pricing_profiles.name_placeholder'),
        'price' => __('app.pricing_profiles.rate'), 'active' => __('app.pricing_profiles.active'),
        'remove' => __('app.pricing_profiles.remove', ['n' => ':n']), 'currency' => app()->getLocale() === 'ar' ? 'ج.م' : 'EGP',
        'perHour' => __('app.common.slash_hr'),
    ];
@endphp
    {{-- Pricing Profiles — optional alternative hourly rates, picked per booking/session. --}}
    <div class="ls-plans ls-profiles" data-profiles aria-labelledby="profiles-title">
        <div class="ls-plans-head">
            @if ($showHeading)
                <h3 class="ls-pricing-title" id="profiles-title">{{ __('app.pricing_profiles.section') }} <span class="ls-opt">({{ __('app.ui.optional') }})</span></h3>
            @endif
            <button type="button" class="ls-btn ls-btn--tonal ls-btn--sm" data-profile-add><x-ui.icon name="plus" />{{ __('app.pricing_profiles.add') }}</button>
        </div>
        <p class="ls-hint">{{ __('app.pricing_profiles.hint') }}</p>
        <div class="ls-profiles-list" data-profiles-list role="list" aria-labelledby="profiles-title"></div>
        <input type="hidden" name="pricing_profiles" id="pricing_profiles" value="{{ $profilesJson }}">
        @error('pricing_profiles')
            @foreach ($errors->get('pricing_profiles') as $msg)
                <p class="ls-error">{{ $msg }}</p>
            @endforeach
        @enderror
    </div>

<script>
(function () {
    // Pricing Profiles editor: rows <-> hidden JSON. The server re-validates
    // everything (PricingProfileInput); a removed profile that bookings used is
    // only deactivated there, never deleted.
    const box = document.querySelector('[data-profiles]');
    const list = box.querySelector('[data-profiles-list]');
    const hidden = document.getElementById('pricing_profiles');
    const T = @json($profileI18n);
    let rows = [];
    try { rows = JSON.parse(hidden.value || '[]') || []; } catch (e) { rows = []; }

    const serialise = () => {
        hidden.value = JSON.stringify(rows.map((p) => ({
            id: p.id || null, name: p.name || '', price_per_hour: p.price_per_hour === '' ? '' : Number(p.price_per_hour), is_active: p.is_active !== false,
        })));
    };

    function render() {
        list.innerHTML = '';
        rows.forEach((p, i) => {
            const n = i + 1;
            const row = document.createElement('div');
            row.className = 'ls-profile-row' + (p.is_active === false ? ' is-inactive' : '');
            row.setAttribute('role', 'listitem');

            const name = document.createElement('input');
            name.type = 'text'; name.className = 'ls-input'; name.value = p.name || ''; name.maxLength = 60;
            name.placeholder = T.namePh; name.setAttribute('aria-label', T.name + ' ' + n);
            name.addEventListener('input', () => { p.name = name.value; serialise(); });

            const priceWrap = document.createElement('div'); priceWrap.className = 'ls-input-affix ls-profile-price';
            const cur = document.createElement('span'); cur.setAttribute('aria-hidden', 'true'); cur.textContent = T.currency;
            const price = document.createElement('input');
            price.type = 'number'; price.className = 'ls-input'; price.min = 0; price.step = '0.01'; price.inputMode = 'decimal';
            price.value = p.price_per_hour ?? ''; price.setAttribute('aria-label', T.price + ' ' + n);
            price.addEventListener('input', () => { p.price_per_hour = price.value; serialise(); });
            const per = document.createElement('small'); per.className = 'ls-profile-per'; per.textContent = T.perHour;
            priceWrap.append(cur, price);

            const act = document.createElement('label'); act.className = 'ls-profile-active';
            const cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = p.is_active !== false;
            cb.setAttribute('aria-label', T.active + ' ' + n);
            cb.addEventListener('change', () => { p.is_active = cb.checked; row.classList.toggle('is-inactive', !cb.checked); serialise(); });
            const at = document.createElement('span'); at.textContent = T.active;
            act.append(cb, at);

            const rm = document.createElement('button');
            rm.type = 'button'; rm.className = 'ls-btn ls-btn--danger-quiet ls-btn--sm ls-btn--icon';
            rm.setAttribute('aria-label', T.remove.replace(':n', n)); rm.title = T.remove.replace(':n', n);
            rm.innerHTML = '<svg class="ls-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
            rm.addEventListener('click', () => { rows.splice(i, 1); render(); box.querySelector('[data-profile-add]').focus(); });

            const priceCell = document.createElement('div'); priceCell.className = 'ls-profile-price-cell'; priceCell.append(priceWrap, per);
            row.append(name, priceCell, act, rm);
            list.appendChild(row);
        });
        serialise();
    }

    box.querySelector('[data-profile-add]').addEventListener('click', () => {
        rows.push({ id: null, name: '', price_per_hour: '', is_active: true });
        render();
        list.lastElementChild.querySelector('input').focus();
    });
    render();
})();
</script>
