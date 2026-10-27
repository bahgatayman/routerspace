{{--
    Member picker — search the registry, or add a member inline when the search
    comes up empty, without losing the half-filled booking form behind it.

    Shared by bookings/create and shared-sessions/create. Posts the chosen member
    as `hotspot_user_id`.

    Params:
      $label        — field label
      $selectedId   — pre-selected member id (optional)
      $selectedName, $selectedPhone — the pre-selected member's display
                      fields (optional; without these, a pre-selected id
                      posts correctly but shows no name/phone until the
                      operator searches again — needed for edit forms)
      $inputClass   — classes for the search box (each screen has its own shape)
      $resultsClass — extra classes for the dropdown
      $hideLabel    — visually hide the label (screen-reader only) when the
                      caller already has its own heading for this field
                      (e.g. the booking form's "Customer" section title)
      $searchIcon   — wrap the input in the .ls-search leading-icon pattern;
                      opt-in (defaults off) so callers passing their own
                      $inputClass (e.g. shared-sessions/create) keep their
                      exact current padding instead of an icon overlapping it
--}}
@php
    $label         = $label         ?? __('app.booking.user');
    $selectedId    = $selectedId    ?? '';
    $selectedName  = $selectedName  ?? null;
    $selectedPhone = $selectedPhone ?? null;
    $inputClass    = $inputClass    ?? 'ls-input';
    $resultsClass  = $resultsClass  ?? '';
    $hideLabel     = $hideLabel     ?? false;
    $searchIcon    = $searchIcon    ?? false;
    $hasSelection  = $selectedId !== '' && $selectedName;
@endphp

<label class="{{ $hideLabel ? 'sr-only' : 'ls-label' }}" for="user-search">{{ $label }}</label>

@if ($searchIcon)
    <div class="ls-search" id="user-search-wrap">
        <x-ui.icon name="search" />
        <input type="text" id="user-search" placeholder="{{ __('app.placeholder.search_name_phone') }}"
               autocomplete="off" class="{{ $inputClass }}">
    </div>
@else
    <input type="text" id="user-search" placeholder="{{ __('app.placeholder.search_name_phone') }}"
           autocomplete="off" class="{{ $inputClass }}">
@endif
<input type="hidden" name="hotspot_user_id" id="selected-user-id" value="{{ old('hotspot_user_id', $selectedId) }}">

<div id="search-results" class="hidden ls-pop ls-picker-pop {{ $resultsClass }}"></div>

<div id="selected-user-display" class="{{ $hasSelection ? '' : 'hidden' }} ls-picker-selected">
    <span class="ls-picker-selected-avatar" id="selected-user-avatar" aria-hidden="true">{{ $hasSelection ? mb_strtoupper(mb_substr($selectedName, 0, 1)) : '' }}</span>
    <span class="ls-picker-selected-info">
        <span class="ls-picker-selected-name" id="selected-user-name">{{ $selectedName }}</span>
        <span class="ls-picker-selected-phone ls-num" id="selected-user-phone">{{ $selectedPhone }}</span>
    </span>
    <button type="button" onclick="clearUserSelection()" class="ls-picker-clear">
        {{ __('app.booking.customer_change') }}
        <x-ui.icon name="arrow-right" />
    </button>
</div>

{{-- Quick-add. Inputs carry no `name`, so they never post with the parent form. --}}
<div id="quick-add" class="hidden ls-picker-quickadd">
    <p class="ls-picker-quickadd-title">{{ __('app.user.quick_add_title') }}</p>

    <div class="ls-picker-quickadd-grid">
        <input type="text" id="quick-add-name" placeholder="{{ __('app.user.name') }}" class="ls-input">
        <input type="text" id="quick-add-phone" placeholder="{{ __('app.user.phone') }}" inputmode="tel" class="ls-input">
    </div>

    <p id="quick-add-error" class="hidden ls-error" style="margin-top: var(--space-2)"></p>

    <div class="ls-picker-quickadd-actions">
        <button type="button" id="quick-add-submit" class="ls-btn ls-btn--primary ls-btn--sm">
            {{ __('app.user.add_and_select') }}
        </button>
        <button type="button" id="quick-add-cancel" class="ls-link">
            {{ __('app.common.cancel') }}
        </button>
        <span class="ls-picker-quickadd-hint">{{ __('app.user.quick_add_hint') }}</span>
    </div>
</div>

<script>
(function () {
    const TOKEN     = '{{ csrf_token() }}';
    const search    = document.getElementById('user-search');
    const results   = document.getElementById('search-results');
    const panel     = document.getElementById('quick-add');
    const nameInput = document.getElementById('quick-add-name');
    const phoneInput= document.getElementById('quick-add-phone');
    const errorBox  = document.getElementById('quick-add-error');
    const submitBtn = document.getElementById('quick-add-submit');

    let searchTimeout = null;

    // Built with the DOM API rather than innerHTML: member names are free text
    // and would otherwise break (or inject) markup — an apostrophe was enough.
    function resultRow(user) {
        const row = document.createElement('div');
        row.className = 'ls-picker-row';

        const name = document.createElement('p');
        name.className = 'ls-picker-row-name';
        name.textContent = user.name;

        const phone = document.createElement('p');
        phone.className = 'ls-picker-row-phone ls-num';
        phone.textContent = user.phone;

        row.append(name, phone);
        // mousedown (not click), with preventDefault, so tapping a row on mobile
        // doesn't first blur #user-search — that closes the keyboard, shifts the
        // viewport, and the tap misses its target before "click" ever fires.
        // Same fix already used in bookings/_quick-modal.blade.php and workspaces/create.blade.php.
        row.addEventListener('mousedown', (e) => { e.preventDefault(); selectUser(user.id, user.name, user.phone); });
        return row;
    }

    function addRow(query) {
        const row = document.createElement('button');
        row.type = 'button';
        row.className = 'ls-picker-row ls-picker-add-row';
        row.innerHTML = '<svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>';

        const label = document.createElement('span');
        label.textContent = @json(__('app.user.add_new_member'));
        row.append(label);

        row.addEventListener('mousedown', (e) => { e.preventDefault(); openQuickAdd(query); });
        return row;
    }

    function openQuickAdd(query) {
        results.classList.add('hidden');
        panel.classList.remove('hidden');
        errorBox.classList.add('hidden');

        // Digits look like a phone number, anything else like a name.
        if (/^[0-9+\s-]+$/.test(query)) {
            phoneInput.value = query.trim();
            nameInput.value  = '';
            nameInput.focus();
        } else {
            nameInput.value  = query.trim();
            phoneInput.value = '';
            phoneInput.focus();
        }
    }

    function closeQuickAdd() {
        panel.classList.add('hidden');
        nameInput.value = phoneInput.value = '';
        errorBox.classList.add('hidden');
    }

    function showError(message) {
        errorBox.textContent = message;
        errorBox.classList.remove('hidden');
    }

    function submitQuickAdd() {
        submitBtn.disabled = true;
        errorBox.classList.add('hidden');

        fetch('/users/quick', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': TOKEN,
            },
            body: JSON.stringify({ name: nameInput.value.trim(), phone: phoneInput.value.trim() }),
        })
            // Parsed defensively: an expired session redirects to the HTML login
            // page, which would otherwise blow up in response.json().
            .then(response => response.text().then(text => {
                let body = null;
                try { body = JSON.parse(text); } catch (e) { /* not JSON */ }
                return { ok: response.ok, body };
            }))
            .then(({ ok, body }) => {
                if (!body) {
                    showError(@json(__('app.user.quick_add_session_lost')));
                    return;
                }
                if (ok) {
                    selectUser(body.id, body.name, body.phone);
                    closeQuickAdd();
                    return;
                }
                // Field validation comes back as `errors`, rule failures (plan
                // limit, missing speed profile, router down) as `message`.
                const first = body.errors ? Object.values(body.errors)[0][0] : body.message;
                showError(first || @json(__('app.user.quick_add_failed')));
            })
            .catch(() => showError(@json(__('app.user.quick_add_failed'))))
            .finally(() => { submitBtn.disabled = false; });
    }

    search.addEventListener('input', function () {
        const q = this.value.trim();
        clearTimeout(searchTimeout);

        if (q.length < 2) {
            results.classList.add('hidden');
            return;
        }

        searchTimeout = setTimeout(() => {
            fetch(`/users/search?q=${encodeURIComponent(q)}`)
                .then(r => r.json())
                .then(users => {
                    results.replaceChildren();

                    if (users.length === 0) {
                        const empty = document.createElement('div');
                        empty.className = 'ls-picker-empty';
                        empty.textContent = @json(__('app.user.no_match'));
                        results.append(empty);
                    } else {
                        users.forEach(u => results.append(resultRow(u)));
                    }

                    results.append(addRow(q));
                    results.classList.remove('hidden');
                })
                .catch(() => { results.classList.add('hidden'); });
        }, 300);
    });

    submitBtn.addEventListener('click', submitQuickAdd);
    document.getElementById('quick-add-cancel').addEventListener('click', closeQuickAdd);

    // Enter inside the quick-add fields must not submit the booking form behind it.
    [nameInput, phoneInput].forEach(input => {
        input.addEventListener('keydown', event => {
            if (event.key === 'Enter') {
                event.preventDefault();
                submitQuickAdd();
            }
        });
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('#user-search') && !e.target.closest('#search-results')) {
            results.classList.add('hidden');
        }
    });

    window.selectUser = function (id, name, phone) {
        document.getElementById('selected-user-id').value          = id;
        document.getElementById('selected-user-name').textContent  = name;
        document.getElementById('selected-user-phone').textContent = phone;
        document.getElementById('selected-user-avatar').textContent = (name || '?').trim().charAt(0).toUpperCase();
        document.getElementById('selected-user-display').classList.remove('hidden');
        search.value = '';
        results.classList.add('hidden');
    };

    window.clearUserSelection = function () {
        document.getElementById('selected-user-id').value = '';
        document.getElementById('selected-user-display').classList.add('hidden');
        search.value = '';
        search.focus();
    };
})();
</script>
