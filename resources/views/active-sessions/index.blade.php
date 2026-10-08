@extends('layouts.app')

@section('page-title', __('app.nav.active_sessions'))

@section('content')
@php
    $canSell = $owner->hasFeature('sales');
@endphp
<div class="ls-page">
    <x-ui.flash />

    <x-ui.page-header :title="__('app.session.active_sessions')" :count="$totalCount" :subtitle="__('app.ui.sessions.subtitle')">
        <x-slot:actions>
            <x-ui.button variant="primary" icon="play" data-ls-open="start-session-modal">{{ __('app.ui.sessions.start_session') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($totalCount > 0)
        <div class="ls-toolbar">
            {{-- Room filter — only rooms with at least one active session appear, each with its own live count. --}}
            <nav class="ls-chips is-scroll" aria-label="{{ __('app.session.filter_all_rooms') }}">
                <a href="{{ route('active-sessions.index') }}" class="ls-chip {{ ! $selectedRoomId ? 'is-active' : '' }}" @if(! $selectedRoomId) aria-current="true" @endif>
                    @if (! $selectedRoomId)<x-ui.icon name="check" />@endif
                    {{ __('app.session.filter_all_rooms') }} <span class="ls-chip-count">{{ $totalCount }}</span>
                </a>
                @foreach ($roomCounts as $entry)
                    @php $on = $selectedRoomId === $entry['room']->id; @endphp
                    <a href="{{ route('active-sessions.index', ['room_id' => $entry['room']->id]) }}" class="ls-chip {{ $on ? 'is-active' : '' }}" @if($on) aria-current="true" @endif>
                        @if ($on)<x-ui.icon name="check" />@endif
                        {{ $entry['room']->name }} <span class="ls-chip-count">{{ $entry['count'] }}</span>
                    </a>
                @endforeach
            </nav>
            <div class="ls-toolbar-spacer"></div>
            <x-ui.search group="sessions" :placeholder="__('app.ui.sessions.search')" width="240px" />
        </div>
    @endif

    @if ($sessions->isEmpty())
        <div class="ls-card">
            <x-ui.empty-state illustration="quiet" :title="__('app.ui.sessions.empty_title')" :text="__('app.empty.no_active_sessions').' '.__('app.ui.sessions.empty_text')">
                <x-ui.button variant="primary" icon="play" data-ls-open="start-session-modal">{{ __('app.ui.sessions.start_session') }}</x-ui.button>
            </x-ui.empty-state>
        </div>
    @else
        <div class="ls-grid-sessions">
            @foreach ($sessions as $row)
                @include('active-sessions._card', [
                    'row' => $row,
                    'estimate' => ($row->isShared() || $row->model->isOpenSession()) ? ($estimates[$row->type.'-'.$row->model->id] ?? null) : null,
                ])
            @endforeach
        </div>
        <div class="ls-card" data-ls-empty="sessions" hidden>
            <x-ui.empty-state illustration="search" :title="__('app.ui.sessions.no_match_title')" :text="__('app.ui.sessions.no_match_text')" />
        </div>
    @endif
</div>

{{-- ===================== Start a new shared/walk-in session =====================
     Was a standalone page (active-sessions/create); now a modal on this page so
     starting a session never leaves it. Plain form POST to the existing
     shared-sessions.store route — unchanged server-side, including its
     redirect back to active-sessions.index on both success and validation
     failure, which is exactly this page. --}}
<x-ui.modal id="start-session-modal" :title="__('app.session.open_new_session')" size="wide">
    <form id="start-session-form" method="POST" action="{{ route('shared-sessions.store') }}" style="display:grid;gap:18px">
        @csrf

        <div class="ls-field">
            <label class="ls-label">{{ __('app.session.room') }}</label>
            <div class="ls-qb-rooms">
                @php
                    // Only one shared area at all → pick it for the owner, nothing
                    // to choose between. Two or more → leave it unselected rather
                    // than guess which one they mean.
                    $onlyRoom = $sharedRooms->count() === 1 ? $sharedRooms->first() : null;
                @endphp
                @foreach ($sharedRooms as $room)
                    @php
                        $available = max(0, $room->capacity - ($room->occupied_seats ?? 0));
                        $isFull = $available <= 0;
                        $selected = old('room_id') !== null
                            ? old('room_id') == $room->id
                            : ($onlyRoom && $onlyRoom->id === $room->id && ! $isFull);
                    @endphp
                    <label class="ls-qb-room {{ $isFull ? 'is-off' : '' }} {{ $selected ? 'is-selected' : '' }}">
                        <input type="radio" name="room_id" value="{{ $room->id }}"
                               data-capacity="{{ $room->capacity }}" data-available="{{ $available }}"
                               data-default-rate="{{ \App\Support\Money::format((float) $room->price_per_hour).__('app.common.slash_hr') }}"
                               data-profiles="{{ $room->activePricingProfiles->map(fn ($p) => ['id' => $p->id, 'label' => __('app.pricing_profiles.option', ['name' => $p->name, 'rate' => $p->rateLabel()])])->values()->toJson() }}"
                               {{ $selected ? 'checked' : '' }} {{ $isFull ? 'disabled' : '' }} required>
                        <span class="ls-qb-room-main">
                            <b class="ls-trunc">{{ $room->workspace->name }} &rarr; {{ $room->name }}</b>
                            <span class="ls-qb-room-meta">{{ __('app.workspace.rooms') }}</span>
                        </span>
                        <span class="ls-qb-room-price">
                            <b>{{ $available }}/{{ $room->capacity }}</b>
                            <small>{{ $isFull ? __('app.workspace.seats_full') : __('app.ui.sessions.shared_seats') }}</small>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('room_id') <p class="ls-error">{{ $message }}</p> @enderror
        </div>

        {{-- Pricing profile (alternative hourly rate) — only when the chosen room has profiles. --}}
        <div class="ls-field ls-profile-pick" id="start-session-profile" hidden>
            <label class="ls-label" for="start-session-profile-select">{{ __('app.pricing_profiles.pricing') }}</label>
            <select name="room_pricing_profile_id" id="start-session-profile-select" class="ls-select" data-old="{{ old('room_pricing_profile_id') }}"></select>
        </div>

        <div class="ls-field">
            <label class="ls-label" for="start-session-party">{{ __('app.session.party_size') }}</label>
            <input type="number" name="party_size" id="start-session-party" min="1"
                   value="{{ old('party_size', 1) }}" required class="ls-input" style="max-width:140px">
            <p class="ls-hint">{{ __('app.session.party_size_hint') }}</p>
            @error('party_size') <p class="ls-error">{{ $message }}</p> @enderror
        </div>

        <div class="ls-field relative">
            @include('partials.member-picker', ['label' => __('app.session.user'), 'searchIcon' => true])
            @error('hotspot_user_id') <p class="ls-error">{{ $message }}</p> @enderror
        </div>

        {{-- Hour package for the member's own seat — shown only when they have packages;
             the actual time is deducted when the session closes (SharedSessionController). --}}
        <input type="hidden" name="member_package_id" id="start-session-pkg" value="{{ old('member_package_id') }}">
        <div class="ls-pkg-pay" id="start-session-pkg-box" hidden>
            <div class="ls-inv-seg" role="radiogroup" aria-label="{{ __('app.packages.pay_title') }}">
                <label><input type="radio" name="ss_pay_mode" value="normal" data-ss-pay-mode @checked(! old('member_package_id'))><span>{{ __('app.packages.pay_normal') }}</span></label>
                <label><input type="radio" name="ss_pay_mode" value="package" data-ss-pay-mode @checked((bool) old('member_package_id'))><span>{{ __('app.packages.pay_package') }}</span></label>
            </div>
            <p class="ls-hint">{{ __('app.packages.session_hint') }}</p>
            <div class="ls-pkg-options" id="start-session-pkg-options" hidden></div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="ls-field">
                <label class="ls-label" for="start-session-date">{{ __('app.session.date') }}</label>
                <input type="date" name="session_date" id="start-session-date"
                       value="{{ old('session_date', now()->format('Y-m-d')) }}" required class="ls-input">
                @error('session_date') <p class="ls-error">{{ $message }}</p> @enderror
            </div>
            <div class="ls-field">
                <label class="ls-label" for="start-session-time">{{ __('app.session.start') }}</label>
                <input type="time" name="start_time" id="start-session-time"
                       value="{{ old('start_time', now()->format('H:i')) }}" required class="ls-input">
                @error('start_time') <p class="ls-error">{{ $message }}</p> @enderror
            </div>
        </div>
    </form>

    <x-slot:footer>
        <x-ui.button variant="ghost" data-ls-close>{{ __('app.common.cancel') }}</x-ui.button>
        <div class="ls-push">
            <x-ui.button type="submit" form="start-session-form" variant="primary" icon="play">{{ __('app.session.open_session') }}</x-ui.button>
        </div>
    </x-slot:footer>
</x-ui.modal>

@if ($errors->any() && old('room_id') !== null)
    <script>document.addEventListener('DOMContentLoaded', () => LS.open('start-session-modal'));</script>
@endif

<script>
(function () {
    const partyInput = document.getElementById('start-session-party');
    if (!partyInput) return;

    // The server-rendered default (now()->format('H:i')) is only "now" at the
    // moment the page itself was loaded — since this modal lives inline in the
    // page instead of being its own freshly-rendered route anymore, re-stamp
    // both fields with the browser's actual current time each time it's
    // opened, so leaving the tab open for a while doesn't leave a stale time.
    // Skipped only when re-showing the form after a validation error (not
    // merely because a room happens to be pre-checked — the lone-shared-area
    // default-select below also leaves a radio checked on a plain page load).
    const modal = document.getElementById('start-session-modal');
    const dateInput = document.getElementById('start-session-date');
    const timeInput = document.getElementById('start-session-time');
    @php $isValidationRedisplay = old('room_id') !== null; @endphp
    const isValidationRedisplay = @json($isValidationRedisplay);
    if (modal && !isValidationRedisplay) {
        modal.addEventListener('ls:open', () => {
            const now = new Date();
            dateInput.value = now.toLocaleDateString('en-CA'); // YYYY-MM-DD, locale-independent
            timeInput.value = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
        });
    }

    function syncMax() {
        const checked = document.querySelector('#start-session-form input[name="room_id"]:checked');
        const available = checked ? parseInt(checked.dataset.available || '0', 10) : null;
        if (available) {
            partyInput.max = available;
            if (parseInt(partyInput.value, 10) > available) partyInput.value = available;
        } else {
            partyInput.removeAttribute('max');
        }
    }

    // Pricing select: Default — room rate, then the room's active profiles.
    const profileBox = document.getElementById('start-session-profile');
    const profileSelect = document.getElementById('start-session-profile-select');
    const DEFAULT_OPTION = @json(__('app.pricing_profiles.default_option', ['rate' => ':rate']));
    function syncProfiles() {
        const checked = document.querySelector('#start-session-form input[name="room_id"]:checked');
        let profiles = [];
        try { profiles = checked ? JSON.parse(checked.dataset.profiles || '[]') : []; } catch (e) { profiles = []; }
        profileBox.hidden = !profiles.length;
        const keep = profileSelect.value || profileSelect.dataset.old || '';
        profileSelect.innerHTML = '';
        if (!profiles.length) return;
        profileSelect.add(new Option(DEFAULT_OPTION.replace(':rate', checked.dataset.defaultRate), ''));
        profiles.forEach((p) => profileSelect.add(new Option(p.label, p.id)));
        profileSelect.value = profiles.some((p) => String(p.id) === String(keep)) ? keep : '';
        profileSelect.dataset.old = '';
    }

    document.querySelectorAll('#start-session-form input[name="room_id"]').forEach((radio) => {
        radio.addEventListener('change', function () {
            document.querySelectorAll('#start-session-form .ls-qb-room').forEach((label) => label.classList.remove('is-selected'));
            this.closest('.ls-qb-room').classList.add('is-selected');
            profileSelect.value = ''; // profiles belong to one room
            syncMax();
            syncProfiles();
        });
    });
    syncMax();
    syncProfiles();

    // --- Hour package (member's own seat; eligibility from the server) ---
    @php $ssPkgI18n = ['expires' => __('app.packages.expires_short'), 'left' => __('app.packages.left')]; @endphp
    const PK = @json($ssPkgI18n);
    const pkgInput = document.getElementById('start-session-pkg');
    const pkgBox = document.getElementById('start-session-pkg-box');
    const pkgOpts = document.getElementById('start-session-pkg-options');
    const userInput = document.getElementById('selected-user-id');
    let pkgs = [], pkgTimer = null, pkgToken = 0;
    const mode = () => (document.querySelector('[data-ss-pay-mode]:checked') || {}).value || 'normal';
    function renderPkgs() {
        pkgBox.hidden = !pkgs.length;
        const use = mode() === 'package' && pkgs.length;
        pkgOpts.hidden = !use;
        pkgOpts.innerHTML = '';
        if (!use) { pkgInput.value = ''; return; }
        if (!pkgs.some((p) => p.eligible && String(p.id) === String(pkgInput.value))) {
            const first = pkgs.find((p) => p.eligible); pkgInput.value = first ? first.id : '';
        }
        pkgs.forEach((p) => {
            const el = document.createElement('label');
            el.className = 'ls-pkg-opt' + (p.eligible ? '' : ' is-disabled');
            el.innerHTML = '<input type="radio" name="ss-pkg"><span class="ls-pkg-opt-main"><span class="ls-pkg-opt-name"></span><span class="ls-pkg-opt-meta"></span></span><span class="ls-pkg-opt-left"></span>';
            const input = el.querySelector('input');
            input.disabled = !p.eligible; input.checked = String(p.id) === String(pkgInput.value);
            el.querySelector('.ls-pkg-opt-name').textContent = p.name;
            el.querySelector('.ls-pkg-opt-meta').textContent = PK.expires.replace(':date', p.expires_label);
            el.querySelector('.ls-pkg-opt-left').textContent = PK.left.replace(':time', p.remaining_label);
            if (p.reason) { const w = document.createElement('span'); w.className = 'ls-pkg-opt-reason'; w.textContent = p.reason; el.querySelector('.ls-pkg-opt-main').appendChild(w); }
            input.addEventListener('change', () => { pkgInput.value = p.id; });
            pkgOpts.appendChild(el);
        });
    }
    function fetchPkgs() {
        clearTimeout(pkgTimer);
        pkgTimer = setTimeout(() => {
            const uid = userInput ? userInput.value : '';
            if (!uid) { pkgs = []; renderPkgs(); return; }
            const room = document.querySelector('#start-session-form input[name="room_id"]:checked');
            const params = new URLSearchParams({ hotspot_user_id: uid, context: 'session', party_size: partyInput.value || 1 });
            if (room) params.set('room_id', room.value);
            const token = ++pkgToken;
            fetch(`/bookings/package-options?${params}`, { headers: { Accept: 'application/json' } })
                .then((r) => r.ok ? r.json() : { packages: [] })
                .then((d) => { if (token === pkgToken) { pkgs = d.packages || []; renderPkgs(); } })
                .catch(() => {});
        }, 150);
    }
    document.querySelectorAll('[data-ss-pay-mode]').forEach((r) => r.addEventListener('change', renderPkgs));
    document.querySelectorAll('#start-session-form input[name="room_id"]').forEach((r) => r.addEventListener('change', fetchPkgs));
    partyInput.addEventListener('input', fetchPkgs);
    if (typeof window.selectUser === 'function') {
        const baseSelect = window.selectUser;
        window.selectUser = function (...args) { baseSelect(...args); fetchPkgs(); };
    }
    if (typeof window.clearUserSelection === 'function') {
        const baseClear = window.clearUserSelection;
        window.clearUserSelection = function (...args) { baseClear(...args); fetchPkgs(); };
    }
    fetchPkgs();
})();
</script>

{{-- ===================== Shared session: add products + check out =====================
     One modal serves both actions (as before); the title, note and primary button reflect
     which one was clicked. All amounts come from the server preview. --}}
<x-ui.modal id="session-modal" :title="__('app.session.close_session')" size="wide">
    <div id="modal-loading" style="display:grid;gap:12px" aria-live="polite">
        <span class="ls-sr">{{ __('app.ui.sessions.loading') }}</span>
        <div style="display:flex;gap:12px;align-items:center"><div class="ls-skel" style="width:40px;height:40px;border-radius:50%"></div><div style="flex:1"><div class="ls-skel" style="width:55%"></div><div class="ls-skel" style="width:35%;margin-top:8px"></div></div></div>
        <div class="ls-skel" style="height:64px"></div>
        <div class="ls-skel" style="width:70%"></div>
    </div>

    <div id="modal-error" hidden>
        <x-ui.banner tone="danger">{{ __('app.ui.sessions.load_failed') }}</x-ui.banner>
        <x-ui.button onclick="retryPreview()">{{ __('app.ui.sessions.try_again') }}</x-ui.button>
    </div>

    <div id="modal-content" hidden style="display:grid;gap:18px">
        <dl class="ls-kv">
            <dt>{{ __('app.session.user') }}</dt><dd id="modal-user"></dd>
            <dt>{{ __('app.session.room') }}</dt><dd id="modal-room"></dd>
            <dt id="modal-party-label" hidden>{{ __('app.session.party_size') }}</dt><dd id="modal-party-row" hidden><span id="modal-party"></span></dd>
            <dt>{{ __('app.session.time') }}</dt><dd><bdi id="modal-time" class="ls-num" dir="ltr"></bdi></dd>
            <dt>{{ __('app.session.duration') }}</dt><dd><span id="modal-duration"></span><span id="modal-billed-row" hidden class="ls-faint" style="display:block;font-size:12px;font-weight:400"><span id="modal-billed"></span></span></dd>
            <dt>{{ __('app.session.rate') }}</dt><dd id="modal-rate"></dd>
            <dt id="modal-pkg-label" hidden>{{ __('app.packages.covered_by') }}</dt><dd id="modal-pkg-row" hidden><span id="modal-pkg"></span></dd>
        </dl>

        @if ($canSell)
            <div>
                <div class="ls-label" style="margin-bottom:4px">{{ __('app.ui.sessions.on_the_bill') }}</div>
                <div id="modal-items" class="ls-lines"></div>
                @if ($products->isNotEmpty())
                    <div class="ls-add-row" style="margin-top:12px">
                        <div class="ls-field">
                            <label class="ls-label" for="modal-product">{{ __('app.ui.sessions.product') }}</label>
                            <select id="modal-product" class="ls-select">
                                @foreach ($products as $p)
                                    <option value="{{ $p->id }}">{{ $p->name }} — {{ number_format($p->price, 2) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="ls-field">
                            <label class="ls-label" for="modal-qty">{{ __('app.ui.sessions.quantity') }}</label>
                            <div class="ls-stepper"><button type="button" onclick="stepQty('modal-qty',-1)" aria-label="-">−</button><input type="number" id="modal-qty" value="1" min="1" max="1000"><button type="button" onclick="stepQty('modal-qty',1)" aria-label="+">+</button></div>
                        </div>
                        <x-ui.button id="modal-add-btn" icon="plus" onclick="sessionAddItem()">{{ __('app.ui.sessions.add_to_bill') }}</x-ui.button>
                    </div>
                @else
                    <p class="ls-hint" style="margin-top:8px">{{ __('app.ui.sessions.no_catalog') }} <a class="ls-link" href="{{ route('products.create') }}">{{ __('app.ui.sessions.add_catalog_product') }}</a></p>
                @endif
            </div>

            <div class="ls-paper">
                <div style="display:flex;justify-content:space-between"><span class="ls-muted">{{ __('app.ui.sessions.room_time') }}</span><span id="modal-total" class="ls-num"></span></div>
                <div style="display:flex;justify-content:space-between"><span class="ls-muted">{{ __('app.ui.sessions.products') }}</span><span id="modal-items-total" class="ls-num"></span></div>
                <div class="ls-total"><span>{{ __('app.ui.sessions.total_to_collect') }}</span><b id="modal-grand-total"></b></div>
            </div>
        @else
            <div class="ls-total"><span>{{ __('app.ui.sessions.total_to_collect') }}</span><b id="modal-total"></b></div>
        @endif
    </div>

    <x-slot:note><span id="session-modal-note"></span></x-slot:note>
    <x-slot:footer>
        <x-ui.button variant="ghost" id="session-modal-dismiss-btn" onclick="closeSessionModal()">{{ __('app.ui.sessions.keep_open') }}</x-ui.button>
        <div class="ls-push">
            <x-ui.button variant="primary" id="confirm-close-btn" disabled>{{ __('app.session.confirm_save') }}</x-ui.button>
        </div>
    </x-slot:footer>
</x-ui.modal>

{{-- ===================== Exclusive room: add products =====================
     No preview endpoint exists for bookings, so a successful add/remove reloads
     the page (with a toast) rather than patching totals in place. --}}
@if ($canSell)
<x-ui.modal id="booking-items-modal" :title="__('app.session.add_products')" size="wide">
    <div>
        <div class="ls-label" style="margin-bottom:4px">{{ __('app.ui.sessions.on_the_bill') }}</div>
        <div id="booking-modal-items" class="ls-lines"></div>
    </div>
    @if ($products->isNotEmpty())
        <div class="ls-add-row">
            <div class="ls-field">
                <label class="ls-label" for="booking-modal-product">{{ __('app.ui.sessions.product') }}</label>
                <select id="booking-modal-product" class="ls-select">
                    @foreach ($products as $p)
                        <option value="{{ $p->id }}">{{ $p->name }} — {{ number_format($p->price, 2) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="ls-field">
                <label class="ls-label" for="booking-modal-qty">{{ __('app.ui.sessions.quantity') }}</label>
                <div class="ls-stepper"><button type="button" onclick="stepQty('booking-modal-qty',-1)" aria-label="-">−</button><input type="number" id="booking-modal-qty" value="1" min="1" max="1000"><button type="button" onclick="stepQty('booking-modal-qty',1)" aria-label="+">+</button></div>
            </div>
            <x-ui.button id="booking-add-btn" icon="plus" onclick="bookingAddItem()">{{ __('app.ui.sessions.add_to_bill') }}</x-ui.button>
        </div>
    @else
        <p class="ls-hint">{{ __('app.ui.sessions.no_catalog') }} <a class="ls-link" href="{{ route('products.create') }}">{{ __('app.ui.sessions.add_catalog_product') }}</a></p>
    @endif
    <x-slot:footer>
        <div class="ls-push"><x-ui.button variant="primary" onclick="closeBookingItemsModal()">{{ __('app.ui.sessions.done') }}</x-ui.button></div>
    </x-slot:footer>
</x-ui.modal>
@endif

{{-- ===================== Exclusive room: check out =====================
     A plain form submit, not AJAX: the room charge was fixed at booking time. --}}
<x-ui.modal id="booking-checkout-modal" :title="__('app.session.confirm_check_out')">
    <div class="ls-paper">
        <div style="display:flex;justify-content:space-between"><span class="ls-muted">{{ __('app.ui.sessions.room_price') }}</span><span id="booking-checkout-room-charge" class="ls-num"></span></div>
        <div style="display:flex;justify-content:space-between"><span class="ls-muted">{{ __('app.ui.sessions.products') }}</span><span id="booking-checkout-items-total" class="ls-num"></span></div>
        <div class="ls-total"><span>{{ __('app.ui.sessions.total_to_collect') }}</span><b id="booking-checkout-grand-total"></b></div>
    </div>
    <x-slot:note><span id="booking-checkout-note"></span></x-slot:note>
    <x-slot:footer>
        <x-ui.button variant="ghost" data-ls-close>{{ __('app.ui.sessions.keep_open') }}</x-ui.button>
        <form id="booking-checkout-form" method="POST" action="" class="ls-push" data-ls-busy>
            @csrf
            <input type="hidden" name="status" value="completed">
            <x-ui.button type="submit" variant="primary" id="booking-checkout-submit">{{ __('app.session.confirm_check_out') }}</x-ui.button>
        </form>
    </x-slot:footer>
</x-ui.modal>

<script>
(() => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const RTL = document.documentElement.dir === 'rtl';
    const SALES_ENABLED = @json($canSell);
    const PKG_COVERED = @json(__('app.packages.covered_total'));
    const S = @json(__('app.ui.sessions'));
    const L = {
        usedVsBilled: @json(__('app.session.used_vs_billed')),
        noItems: @json(__('app.sales.no_items_yet')),
        remove: @json(__('app.common.delete')),
        decrease: @json(__('app.sales.decrease_quantity')),
        increase: @json(__('app.sales.increase_quantity')),
        qtyUpdated: @json(__('app.sales.item_quantity_updated')),
        closeFailed: @json(__('app.session.failed_to_close_session')),
        titles: { products: @json(__('app.session.add_products')), checkout: @json(__('app.session.close_session')) },
    };
    const fill = (s, map) => Object.entries(map).reduce((acc, [k, v]) => acc.replaceAll(':' + k, v), s);
    const money = (formatted) => RTL ? `${formatted} ج.م` : `EGP ${formatted}`;
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const $ = (id) => document.getElementById(id);
    const show = (id, on) => { const el = $(id); if (el) el.hidden = !on; };

    window.stepQty = (id, d) => { const i = $(id); i.value = Math.max(1, Math.min(1000, (parseInt(i.value, 10) || 1) + d)); };

    function renderLines(boxId, items, removeFn, updateFn) {
        const box = $(boxId);
        if (!items || !items.length) { box.innerHTML = `<p class="ls-hint" style="margin:6px 0 0">${esc(L.noItems)}</p>`; return; }
        box.innerHTML = items.map(it => `
            <div class="ls-line" data-id="${esc(it.id)}" data-qty="${esc(it.quantity)}">
                <span class="ls-line-main ls-trunc">${esc(it.name)}</span>
                <div class="ls-stepper ls-stepper--sm">
                    <button type="button" class="ls-qty-dec" aria-label="${esc(L.decrease)} ${esc(it.name)}">−</button>
                    <span class="ls-qty-val">${esc(it.quantity)}</span>
                    <button type="button" class="ls-qty-inc" aria-label="${esc(L.increase)} ${esc(it.name)}">+</button>
                </div>
                <span class="ls-num" style="font-weight:500">${esc(money(it.line_total))}</span>
                <button type="button" class="ls-remove" data-remove="${esc(it.id)}" data-name="${esc(it.name)}" aria-label="${esc(L.remove)} ${esc(it.name)}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>`).join('');
        box.querySelectorAll('[data-remove]').forEach(b => b.addEventListener('click', () => removeFn(b.dataset.remove, b.dataset.name, b)));
        if (updateFn) {
            box.querySelectorAll('.ls-line').forEach(line => {
                const id = line.dataset.id, name = line.querySelector('.ls-line-main').textContent;
                line.querySelector('.ls-qty-dec').addEventListener('click', (e) => updateFn(id, parseInt(line.dataset.qty, 10) - 1, name, e.currentTarget));
                line.querySelector('.ls-qty-inc').addEventListener('click', (e) => updateFn(id, parseInt(line.dataset.qty, 10) + 1, name, e.currentTarget));
            });
        }
    }

    /* ---------------- Shared session modal (also serves Open Session bookings) ---------------- */
    let currentSessionId = null, currentMode = 'checkout', currentKind = 'shared', lastPreview = null, itemsChanged = false;

    // 'shared' -> /shared-sessions/{id}/..., 'open-booking' -> /bookings/{id}/...
    // (an Open Session exclusive booking, which has the identical
    // close-preview/close response shape, so no other branching is needed).
    const sessionBaseUrl = () => currentKind === 'open-booking' ? `/bookings/${currentSessionId}` : `/shared-sessions/${currentSessionId}`;
    // One-time key per user action (see App\Support\IdempotencyKey).
    const idemKey = () => (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2);

    window.openSessionModal = (sessionId, mode = 'checkout', kind = 'shared') => {
        currentSessionId = sessionId; currentMode = mode; currentKind = kind; itemsChanged = false; lastPreview = null;
        $('session-modal-title').textContent = L.titles[mode] || L.titles.checkout;
        $('session-modal-sub').textContent = '';
        const confirmBtn = $('confirm-close-btn');
        confirmBtn.hidden = mode === 'products';
        confirmBtn.disabled = true;
        $('session-modal-dismiss-btn').querySelector('span').textContent = mode === 'products' ? S.done : S.keep_open;
        $('session-modal-note').closest('.ls-dialog-note').hidden = mode === 'products';
        show('modal-loading', true); show('modal-content', false); show('modal-error', false);
        LS.open('session-modal');
        loadPreview();
    };
    window.retryPreview = () => { show('modal-error', false); show('modal-loading', true); loadPreview(); };

    function loadPreview() {
        return fetch(`${sessionBaseUrl()}/close-preview`, { headers: { 'Accept': 'application/json' } })
            .then(r => { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then(data => { populatePreview(data); show('modal-loading', false); show('modal-error', false); show('modal-content', true); })
            .catch(() => { show('modal-loading', false); show('modal-content', false); show('modal-error', true); });
    }

    function populatePreview(data) {
        lastPreview = data;
        const title = currentMode === 'products' ? S.products_title : S.checkout_title;
        $('session-modal-title').textContent = fill(title, { name: data.user_name });
        $('session-modal-sub').textContent = `${data.room_name} · ${data.user_phone}`;
        $('modal-user').textContent = data.user_name;
        $('modal-room').textContent = data.room_name;
        const party = data.party_size > 1;
        show('modal-party-label', party); show('modal-party-row', party);
        $('modal-party').textContent = data.party_size;
        $('modal-time').textContent = data.start_time + ' → ' + data.end_time;
        $('modal-duration').textContent = data.duration;
        // Rule-priced rooms explain the matched package/tier instead of a flat rate.
        $('modal-rate').textContent = data.pricing_note || fill(S.rate_per_hour, { rate: money(data.price_per_hour) });
        $('modal-total').textContent = money(data.total_price);
        show('modal-billed-row', !!data.billed_duration);
        if (data.billed_duration) $('modal-billed').textContent = fill(L.usedVsBilled, { used: data.duration, billed: data.billed_duration });

        // Hour package chosen at open: covers the room time, or says why it can't.
        const pkg = data.package;
        show('modal-pkg-label', !!pkg); show('modal-pkg-row', !!pkg);
        if (pkg) {
            $('modal-pkg').textContent = pkg.message;
            if (pkg.covers) $('modal-total').textContent = PKG_COVERED.replace(':time', pkg.hours);
        }
        data.collect = SALES_ENABLED ? data.grand_total : (pkg && pkg.covers ? '0.00' : data.total_price);
        const total = data.collect;
        if (SALES_ENABLED) {
            $('modal-items-total').textContent = money(data.items_total);
            $('modal-grand-total').textContent = money(data.grand_total);
            renderLines('modal-items', data.items, sessionRemoveItem, sessionUpdateItemQty);
        }
        $('session-modal-note').textContent = fill(S.closes_note, { room: data.room_name });
        const confirmBtn = $('confirm-close-btn');
        confirmBtn.querySelector('span').textContent = fill(S.collect, { amount: money(total) });
        confirmBtn.disabled = false;
    }

    window.sessionAddItem = () => {
        const btn = $('modal-add-btn');
        const select = $('modal-product');
        const name = select.options[select.selectedIndex].text.split(' — ')[0];
        if (btn.dataset.busy) return; // a second click while the first add is in flight
        btn.dataset.busy = '1';
        LS.busy(btn, true);
        // Items go to THIS session's own invoice: /bookings/{id} for an Open
        // Session booking, /shared-sessions/{id} for a shared tab.
        fetch(`${sessionBaseUrl()}/items`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'X-Idempotency-Key': idemKey() },
            body: JSON.stringify({ product_id: select.value, quantity: $('modal-qty').value || 1 }),
        }).then(r => { if (!r.ok) throw new Error(r.status); return r.json(); })
          .then(() => { itemsChanged = true; $('modal-qty').value = 1; LS.toast(fill(S.added_to_bill, { product: name, name: lastPreview ? lastPreview.user_name : '' })); return loadPreview(); })
          .catch(() => LS.toast(S.action_failed, { tone: 'danger' }))
          .finally(() => { LS.busy(btn, false); delete btn.dataset.busy; });
    };

    function sessionRemoveItem(itemId, name, btn) {
        btn.disabled = true;
        fetch(`${sessionBaseUrl()}/items/${itemId}`, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        }).then(r => { if (!r.ok) throw new Error(r.status); return r.json(); })
          .then(() => { itemsChanged = true; LS.toast(fill(S.removed_from_bill, { product: name })); return loadPreview(); })
          .catch(() => { btn.disabled = false; LS.toast(S.action_failed, { tone: 'danger' }); });
    }

    function sessionUpdateItemQty(itemId, newQty, name, btn) {
        if (newQty <= 0) return sessionRemoveItem(itemId, name, btn);
        btn.disabled = true;
        fetch(`${sessionBaseUrl()}/items/${itemId}`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ quantity: newQty }),
        }).then(r => { if (!r.ok) throw new Error(r.status); return r.json(); })
          .then(() => { itemsChanged = true; return loadPreview(); })
          .catch(() => { btn.disabled = false; LS.toast(S.action_failed, { tone: 'danger' }); });
    }

    window.closeSessionModal = () => LS.close('session-modal');
    // Card totals are rendered server-side, so refresh them if the tab changed.
    $('session-modal').addEventListener('ls:close', () => { currentSessionId = null; currentKind = 'shared'; if (itemsChanged) location.reload(); });

    $('confirm-close-btn').addEventListener('click', function () {
        if (!currentSessionId) return;
        const btn = this, preview = lastPreview;
        LS.busy(btn, true);
        fetch(`${sessionBaseUrl()}/close`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { LS.busy(btn, false); LS.toast(data.message || L.closeFailed, { tone: 'danger' }); return; }
            const amount = preview ? preview.collect : '';
            LS.reloadWithToast(fill(S.settled, { amount: money(amount), name: preview ? preview.user_name : '' }));
        })
        .catch(() => { LS.busy(btn, false); LS.toast(L.closeFailed, { tone: 'danger' }); });
    });

    /* ---------------- Exclusive room: add products ---------------- */
    let currentBookingId = null, bookingCustomer = '';
    window.openBookingItemsModal = (bookingId, items, customer) => {
        currentBookingId = bookingId; bookingCustomer = customer || '';
        $('booking-items-modal-title').textContent = customer ? fill(S.products_title, { name: customer }) : L.titles.products;
        renderLines('booking-modal-items', items, bookingRemoveItem, bookingUpdateItemQty);
        LS.open('booking-items-modal');
    };
    window.closeBookingItemsModal = () => LS.close('booking-items-modal');

    window.bookingAddItem = () => {
        const btn = $('booking-add-btn');
        const select = $('booking-modal-product');
        const name = select.options[select.selectedIndex].text.split(' — ')[0];
        if (btn.dataset.busy) return; // already adding — reload follows
        btn.dataset.busy = '1';
        LS.busy(btn, true);
        fetch(`/bookings/${currentBookingId}/items`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'X-Idempotency-Key': idemKey() },
            body: JSON.stringify({ product_id: select.value, quantity: $('booking-modal-qty').value || 1 }),
        }).then(r => { if (!r.ok) throw new Error(r.status); return r.json(); })
          .then(() => LS.reloadWithToast(fill(S.added_to_bill, { product: name, name: bookingCustomer })))
          .catch(() => { delete btn.dataset.busy; LS.busy(btn, false); LS.toast(S.action_failed, { tone: 'danger' }); });
    };

    function bookingRemoveItem(itemId, name, btn) {
        btn.disabled = true;
        fetch(`/bookings/${currentBookingId}/items/${itemId}`, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        }).then(r => { if (!r.ok) throw new Error(r.status); return r.json(); })
          .then(() => LS.reloadWithToast(fill(S.removed_from_bill, { product: name })))
          .catch(() => { btn.disabled = false; LS.toast(S.action_failed, { tone: 'danger' }); });
    }

    function bookingUpdateItemQty(itemId, newQty, name, btn) {
        if (newQty <= 0) return bookingRemoveItem(itemId, name, btn);
        btn.disabled = true;
        fetch(`/bookings/${currentBookingId}/items/${itemId}`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ quantity: newQty }),
        }).then(r => { if (!r.ok) throw new Error(r.status); return r.json(); })
          .then(() => LS.reloadWithToast(L.qtyUpdated))
          .catch(() => { btn.disabled = false; LS.toast(S.action_failed, { tone: 'danger' }); });
    }

    /* ---------------- Exclusive room: check out ---------------- */
    window.openBookingCheckoutModal = (bookingId, roomCharge, itemsTotal, grandTotal, customer, room) => {
        $('booking-checkout-modal-title').textContent = customer ? fill(S.checkout_title, { name: customer }) : @json(__('app.session.confirm_check_out'));
        $('booking-checkout-modal-sub').textContent = room || '';
        $('booking-checkout-room-charge').textContent = money(roomCharge);
        $('booking-checkout-items-total').textContent = money(itemsTotal);
        $('booking-checkout-grand-total').textContent = money(grandTotal);
        $('booking-checkout-note').textContent = fill(S.booking_closes_note, { room: room || '' });
        $('booking-checkout-submit').querySelector('span').textContent = fill(S.collect, { amount: money(grandTotal) });
        $('booking-checkout-form').action = `/bookings/${bookingId}/status`;
        LS.open('booking-checkout-modal');
    };
    window.closeBookingCheckoutModal = () => LS.close('booking-checkout-modal');

    /* ---------------- Remove a product line from a card (trash icon) ---------------- */
    document.querySelectorAll('[data-item-remove]').forEach(btn => btn.addEventListener('click', () => {
        if (btn.dataset.busy) return;
        btn.dataset.busy = '1';
        btn.disabled = true;
        fetch(btn.dataset.url, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        }).then(r => r.json().then(b => { if (!r.ok || !b.success) throw new Error(b.message || ''); }))
          .then(() => LS.reloadWithToast(fill(S.removed_from_bill, { product: btn.dataset.name })))
          .catch((e) => { delete btn.dataset.busy; btn.disabled = false; LS.toast((e && e.message) || S.action_failed, { tone: 'danger' }); });
    }));

    /* ---------------- Quick add (one click, one product) ---------------- */
    // One add per click: while a card's add is in flight (until the page
    // reloads) its chips are locked, and the request carries a one-time key
    // so a retried/duplicated request is applied once server-side.
    document.querySelectorAll('.ls-qa[data-qa-kind]').forEach(chip => chip.addEventListener('click', () => {
        const d = chip.dataset;
        const card = chip.closest('.ls-session-quick') || chip.parentElement;
        if (card.dataset.busy) return;
        card.dataset.busy = '1';
        card.querySelectorAll('.ls-qa').forEach(c => { c.disabled = true; });
        const url = d.qaKind === 'shared' ? `/shared-sessions/${d.qaId}/items` : `/bookings/${d.qaId}/items`;
        chip.classList.add('is-busy');
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'X-Idempotency-Key': idemKey() },
            body: JSON.stringify({ product_id: d.qaProduct, quantity: 1 }),
        }).then(r => r.json().then(b => { if (!r.ok || !b.success) throw new Error(b.message || r.status); return b; }))
          .then(() => LS.reloadWithToast(fill(S.added_to_bill, { product: d.qaName, name: d.qaCustomer })))
          .catch((e) => {
              chip.classList.remove('is-busy');
              delete card.dataset.busy;
              card.querySelectorAll('.ls-qa').forEach(c => { c.disabled = false; });
              LS.toast(e && e.message && isNaN(e.message) ? e.message : S.action_failed, { tone: 'danger' });
          });
    }));

    // Keep amounts honest for block-billed sessions: refresh the page every 5 minutes
    // while nothing is open (per-minute amounts already tick live).
    setInterval(() => { if (!document.querySelector('.ls-overlay.is-open') && !document.activeElement.matches('input')) location.reload(); }, 300000);
})();
</script>
@endsection
