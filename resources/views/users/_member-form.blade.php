{{--
    Add-member fields (design-system ls-* components). Used by the "Add user"
    pop-up on the Users page (submit button lives in the modal footer via
    form="…") and by the full /users/create page ($inline = true: own button,
    plus the plan usage bar). Posts to the unchanged POST /users.
    Expects $owner; optional $formId, $inline.

    UX: only the two required fields are shown up front; email + notes sit
    behind "Add email or notes" (opened automatically when they hold a value
    or an error). While typing a phone number, GET /users/phone-check tells
    straight away if it already belongs to a member (same rule as the save
    validation, which stays the authority).
--}}
@php
    $formId = $formId ?? 'add-member-form';
    $inline = $inline ?? false;
    $hotspot = $owner->hasFeature('hotspot');
    $detailsOpen = filled(old('email')) || filled(old('notes')) || $errors->hasAny(['email', 'notes']);
@endphp

@if ($inline && ($plan = $owner->plan))
    @php $pct = $owner->usagePercentage(); @endphp
    <div class="ls-paper" style="margin-bottom: var(--space-5)">
        <div class="ls-row-split">
            <span>{{ __('app.common.members') }}: <b>{{ $owner->hotspotUsers()->count() }} / {{ $plan->max_members }}</b></span>
            <span class="ls-faint">{{ $plan->name }} {{ __('app.common.plan') }}</span>
        </div>
        <div class="ls-meter {{ $pct >= 100 ? 'is-danger' : ($pct >= 80 ? 'is-warn' : '') }}"><i style="width: {{ min(100, $pct) }}%"></i></div>
    </div>
@endif

<form method="POST" action="/users" id="{{ $formId }}" class="ls-stack" novalidate data-member-form
      onsubmit="if (!this.reportValidity() || this.dataset.dupPhone === '1') return false; if (window.LS) LS.busy(document.querySelector('[form={{ $formId }}][type=submit], #{{ $formId }} [type=submit]'));">
    @csrf

    <x-ui.input name="name" :label="__('app.user.name')" required autofocus autocomplete="name" maxlength="255"
                :placeholder="__('app.user.name_placeholder')" />

    <div class="ls-field">
        <label class="ls-label" for="f-phone">{{ __('app.user.phone') }}<span class="ls-req" aria-hidden="true">*</span></label>
        <input id="f-phone" name="phone" type="tel" value="{{ old('phone') }}" required aria-required="true" inputmode="tel" autocomplete="tel"
               dir="ltr" maxlength="20" placeholder="{{ __('app.user.phone_placeholder') }}" data-phone-check
               aria-describedby="f-phone-note" class="ls-input {{ $errors->has('phone') ? 'is-invalid' : '' }}">
        {{-- One live region: server error, live "already registered" hint, or the MikroTik note. --}}
        <div id="f-phone-note" aria-live="polite">
            @error('phone')
                <span class="ls-error"><x-ui.icon name="alert" />{{ $message }}</span>
            @else
                <span class="ls-error" data-phone-taken hidden><x-ui.icon name="alert" /><span data-phone-taken-text></span>
                    <a href="#" class="ls-link" data-phone-taken-link style="margin-inline-start: 4px">{{ __('app.user.open_profile') }}</a></span>
                @if ($hotspot)
                    <span class="ls-hint" data-phone-hint>{{ __('app.user.mikrotik_login_hint') }}</span>
                @endif
            @enderror
        </div>
    </div>

    <details class="ls-member-more" @if ($detailsOpen) open @endif>
        <summary class="ls-link" style="cursor: pointer; list-style: none; display: inline-flex; align-items: center; gap: 6px; font-size: 14px">
            <x-ui.icon name="plus" style="width: 14px; height: 14px" />{{ __('app.user.add_details') }}
        </summary>
        <div class="ls-stack" style="margin-top: var(--space-4)">
            <x-ui.input name="email" type="email" :label="__('app.user.email')" optional autocomplete="email" dir="ltr" maxlength="255" />
            <x-ui.input name="notes" :label="__('app.user.notes')" optional>
                <textarea id="f-notes" name="notes" rows="2" maxlength="500" class="ls-textarea {{ $errors->has('notes') ? 'is-invalid' : '' }}">{{ old('notes') }}</textarea>
            </x-ui.input>
        </div>
    </details>

    @if ($inline)
        <x-ui.button type="submit" variant="primary" icon="plus" block>{{ __('app.btn.add_user') }}</x-ui.button>
    @endif
</form>

@once
<script>
(function () {
    const taken = @json(__('app.user.phone_taken', ['name' => ':name']));
    document.querySelectorAll('form[data-member-form]').forEach((form) => {
        const input = form.querySelector('[data-phone-check]');
        const box = form.querySelector('[data-phone-taken]');
        if (!input || !box) return;
        const text = box.querySelector('[data-phone-taken-text]');
        const link = box.querySelector('[data-phone-taken-link]');
        const hint = form.querySelector('[data-phone-hint]');
        const submits = () => document.querySelectorAll(`[form="${form.id}"][type=submit], #${form.id} [type=submit]`);
        let timer = null, ctrl = null, last = '';

        const show = (member) => {
            const on = !!member;
            form.dataset.dupPhone = on ? '1' : '';
            box.hidden = !on;
            if (hint) hint.hidden = on;
            input.classList.toggle('is-invalid', on);
            input.setAttribute('aria-invalid', on ? 'true' : 'false');
            submits().forEach((b) => { b.disabled = on; });
            if (on) { text.textContent = taken.replace(':name', member.name); link.href = member.url; }
        };

        const check = () => {
            const v = input.value.trim();
            if (v === last) return;
            last = v;
            if (ctrl) ctrl.abort();
            if (v.replace(/\D/g, '').length < 6) { show(null); return; }
            ctrl = new AbortController();
            fetch('/users/phone-check?phone=' + encodeURIComponent(v), { headers: { Accept: 'application/json' }, signal: ctrl.signal })
                .then((r) => (r.ok ? r.json() : { exists: false }))
                .then((d) => show(d.exists ? d : null))
                .catch(() => {});
        };

        input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(check, 350); });
        input.addEventListener('blur', check);
        if (input.value.trim()) check();
    });
})();
</script>
@endonce
