{{--
    One active-session card (refined design).
    $row: ActiveSessionRow — a SharedSession (shared room, billed live), an
          in-progress exclusive-room Booking (price fixed at booking time), or
          a confirmed SHARED-room Booking whose window has started but hasn't
          been checked in yet ($needsCheckIn below) — visible immediately, but
          never billed live: its amount stays the static total_price quoted at
          booking time until Check In turns it into a real SharedSession.
    $estimate: server-side running estimate for shared sessions (SharedSessionBillingService).

    Reading order is deliberate: who → which room → how long → how much →
    is it ending → what next (Check out — or Check In — is the one dominant action).
--}}
@php
    $endAlert = $endAlert ?? null; // AvailabilityService::sessionEndAlerts() entry, checked-in sessions only
    $isShared = $row->isShared();
    $model = $row->model;
    $customer = $row->customer;
    $itemsTotal = (float) ($row->sale?->total ?? 0);
    $items = $row->sale?->items ?? collect();
    $itemCount = (int) $items->sum('quantity');
    $startedAt = $row->startedAt();

    // Matches LS.durationSeconds()'s formatting exactly (hour segment omitted
    // when zero, minutes padded to 2 digits only once an hour is shown) so
    // the first paint doesn't visibly jump the instant the client-side
    // per-second ticker takes over.
    // diffInSeconds() returns a float in this Carbon version (same quirk
    // already noted elsewhere in this codebase) — cast before intdiv().
    $elapsedSeconds = (int) max(0, $startedAt->diffInSeconds(now()));
    $elapsedH = intdiv($elapsedSeconds, 3600);
    $elapsedM = intdiv($elapsedSeconds % 3600, 60);
    $elapsedS = $elapsedSeconds % 60;
    $elapsedLabel = ($elapsedH ? $elapsedH.__('app.ui.unit_h').' ' : '')
        .($elapsedH ? str_pad((string) $elapsedM, 2, '0', STR_PAD_LEFT) : $elapsedM).__('app.ui.unit_m')
        .' '.str_pad((string) $elapsedS, 2, '0', STR_PAD_LEFT).__('app.ui.unit_s');

    // An Open Session exclusive-room booking bills live, exactly like a
    // shared session (no known end yet) — it shares that whole branch below,
    // never the fixed-progress-bar one.
    $isOpenBooking = ! $isShared && $model->isOpenSession();

    // A confirmed shared-room booking: visible here the instant its window
    // starts (no check-in required to appear), but visibility is not
    // billing — it stays a plain reservation, priced only at its already-
    // fixed total_price, until Check In turns it into a real SharedSession.
    $needsCheckIn = ! $isShared && ! $isOpenBooking && $row->room->isShared();

    if ($isShared || $isOpenBooking) {
        // $estimate: the RoomPricingService quote for "now" (the same service
        // closePreview()/close() charge with). Only a price that's linear in
        // time gets a live per-second ticker; package prices change in steps,
        // so they show the server figure until the next refresh.
        $unit = $model->billing_unit ?? 'minute';
        $rate = (float) ($estimate?->liveRatePerHour ?? 0);
        $roomCharge = (float) ($estimate?->totalPrice ?? 0);
        // Block billing: when the bill next goes up (grace buffer included) —
        // from the same service that prices preview/close.
        $nextChargeAt = $isShared
            ? app(\App\Services\RoomPricingService::class)->nextSessionChargeAt($model, now())
            : app(\App\Services\RoomPricingService::class)->nextOpenBookingChargeAt($model, now());
        // A checked-in reservation keeps its booked end (AvailabilityService::
        // sessionEndAlerts()); a walk-in has none. Billing is unaffected.
        $endsAt = $endAlert['ends_at'] ?? null;
        $endingSoon = (bool) ($endAlert['soon'] ?? false);
    } elseif ($needsCheckIn) {
        $unit = null;
        $nextChargeAt = null;
        $rate = null;
        // Static — the amount quoted and stored at booking time. Never a
        // live quote/estimate call here: that's the whole point of this
        // branch (visible ≠ billable).
        $roomCharge = (float) $model->total_price;
        $endsAt = $model->endsAt();
        $endingSoon = now()->diffInMinutes($endsAt, false) <= 15;
    } else {
        $unit = null;
        $nextChargeAt = null;
        $rate = (float) $model->price_per_hour;
        $roomCharge = $model->netRoomCharge();
        $endsAt = $model->endsAt();
        $endingSoon = now()->diffInMinutes($endsAt, false) <= 15;
    }
    $runningLate = (bool) ($endAlert['late'] ?? false);
    $endConflict = $endAlert['conflict'] ?? null;
    $bill = round($roomCharge + $itemsTotal, 2);
    // Per-minute billing ticks live for shared sessions AND Open Session
    // bookings (both bill by elapsed time); block billing steps server-side.
    $liveBill = ($isShared || $isOpenBooking) && $estimate?->liveRatePerHour !== null;
    $liveAttrs = $liveBill
        ? 'data-ls-since="'.$startedAt->toIso8601String().'" data-ls-rate="'.$rate.'" data-ls-extra="'.$itemsTotal.'"'
        : '';
    $moneyText = app()->getLocale() === 'ar' ? number_format($bill, 2).' ج.م' : 'EGP '.number_format($bill, 2);
    $checkoutLabel = str_replace(':amount', '<span class="ls-num" '.$liveAttrs.'>'.e($moneyText).'</span>', e(__('app.ui.sessions.check_out_amount')));

    // Payload for the exclusive-room "add products" modal (unchanged contract).
    $itemsPayload = $items->map(fn ($item) => [
        'id' => $item->id,
        'name' => $item->name,
        'quantity' => $item->quantity,
        'line_total' => number_format($item->line_total, 2),
    ])->values();
@endphp
<article class="ls-session {{ $runningLate ? 'is-late' : ($endingSoon ? 'is-ending' : '') }}"
         data-ls-item="sessions"
         data-ls-text="{{ $customer->name }} {{ $customer->phone }} {{ $row->room->name }}"
         aria-label="{{ $customer->name }} — {{ $row->room->name }}">
    <div class="ls-session-head">
        <x-ui.avatar :name="$customer->name" />
        <div class="ls-session-who">
            <span class="ls-session-name ls-trunc" title="{{ $customer->name }}">{{ $customer->name }}</span>
            @php $roomIsShared = $row->room->isShared(); @endphp
            <span class="ls-session-room {{ $roomIsShared ? 'is-shared' : 'is-private' }}">
                <x-ui.icon name="door" />
                <span class="ls-trunc">{{ $row->room->name }}</span>
                <span class="ls-kind">· {{ $roomIsShared ? __('app.ui.sessions.shared') : __('app.ui.sessions.private') }}@if ($isOpenBooking) · {{ __('app.booking.duration_type.badge') }}@endif</span>
            </span>
        </div>
        @if ($runningLate)
            <x-ui.badge tone="danger">{{ __('app.ui.sessions.running_late') }}</x-ui.badge>
        @elseif ($endingSoon)
            <x-ui.badge tone="warn">{{ __('app.ui.sessions.ending_soon') }}</x-ui.badge>
        @else
            <span class="ls-status"><span class="ls-dot is-pulse"></span>{{ __('app.ui.sessions.live') }}</span>
        @endif
    </div>

    <div class="ls-session-money">
        <div>
            <div class="ls-session-label">{{ __('app.ui.sessions.time') }}</div>
            <div class="ls-session-time" data-ls-since="{{ $startedAt->toIso8601String() }}" data-ls-seconds>{{ $elapsedLabel }}</div>
        </div>
        <div class="ls-session-bill">
            @if ($liveBill)
                {{-- Continuous billing: tick the estimate live (same formula as the server). --}}
                <div class="ls-session-big" data-ls-since="{{ $startedAt->toIso8601String() }}" data-ls-rate="{{ $rate }}" data-ls-extra="{{ $itemsTotal }}"><x-ui.money :amount="$bill" /></div>
            @else
                <div class="ls-session-big"><x-ui.money :amount="$bill" /></div>
            @endif
        </div>
    </div>

    @if ($isShared || $isOpenBooking)
        @if ($model->party_size > 1 || $endsAt)
            <div class="ls-session-meta">
                <span class="ls-trunc">@if ($model->party_size > 1){{ __('app.session.party_of', ['count' => $model->party_size]) }}@endif</span>
                @if ($endsAt)
                    <span class="{{ $runningLate ? 'is-late' : ($endingSoon ? 'is-soon' : 'ls-faint') }}">{{ __('app.ui.sessions.booked_until', ['time' => $endsAt->translatedFormat('g:i A')]) }}</span>
                @endif
            </div>
        @endif
        @if ($endConflict)
            {{-- The next booking of another use of this room: say what and when, and what to do. --}}
            <p class="ls-session-conflict {{ $runningLate ? 'is-late' : '' }}" role="{{ $runningLate ? 'alert' : 'status' }}">
                <x-ui.icon name="alert" />
                <span>{{ __($runningLate ? 'app.ui.sessions.late_conflict' : 'app.ui.sessions.soon_conflict', [
                    'room' => $endConflict['room'],
                    'time' => \Carbon\Carbon::createFromFormat('H:i', $endConflict['time'])->translatedFormat('g:i A'),
                ]) }}</span>
            </p>
        @endif
        @if ($nextChargeAt)
            {{-- Not shown, but kept: when it reaches zero panel.js reloads once so a
                 block-billed bill steps up to the next hour's amount from the server. --}}
            <span hidden data-ls-countdown="{{ $nextChargeAt->toIso8601String() }}" data-ls-template="" data-ls-done=""></span>
        @endif
    @elseif ($needsCheckIn)
        <div class="ls-session-meta">
            <span class="ls-trunc">@if ($model->party_size > 1){{ __('app.session.party_of', ['count' => $model->party_size]) }}@endif</span>
            <span class="ls-faint ls-trunc {{ $endingSoon ? 'is-soon' : '' }}">{{ __('app.ui.sessions.ends_at', ['time' => $endsAt->translatedFormat('g:i A')]) }}</span>
        </div>
    @else
        <div class="ls-session-timing">
        <div class="ls-meter {{ $endingSoon ? 'is-warn' : '' }}" role="progressbar" aria-label="{{ $model->timeRange() }}">
            <i data-ls-progress-from="{{ $model->startsAt()->toIso8601String() }}" data-ls-progress-to="{{ $endsAt->toIso8601String() }}"
               style="width: {{ max(2, min(100, $model->startsAt()->diffInSeconds(now()) / max(1, $model->startsAt()->diffInSeconds($endsAt)) * 100)) }}%"></i>
        </div>
        <div class="ls-session-meta">
            <span class="ls-num ls-trunc">{{ $model->timeRange() }}</span>
            <span data-ls-until="{{ $endsAt->toIso8601String() }}" class="{{ $endingSoon ? 'is-soon' : '' }}">{{ __('app.ui.sessions.ends_at', ['time' => $endsAt->translatedFormat('g:i A')]) }}</span>
        </div>
        </div>
    @endif

    <div class="ls-session-items">
        @if ($items->isNotEmpty())
            <details>
                <summary>
                    <span>{{ trans_choice('app.ui.sessions.products_summary', $itemCount, ['count' => $itemCount]) }} · <b class="ls-num" style="color:var(--color-text)"><x-ui.money :amount="$itemsTotal" /></b></span>
                    <x-ui.icon name="chevron-right" />
                </summary>
                <ul>
                    @foreach ($items as $item)
                        <li>
                            <span class="ls-session-item-name">
                                <x-ui.product-icon :name="$item->product?->iconName() ?? 'box'" size="sm" />
                                <span class="ls-trunc">{{ $item->quantity }}× {{ $item->name }}</span>
                            </span>
                            <span class="ls-session-item-end">
                                <x-ui.money :amount="$item->line_total" />
                                @if ($canSell)
                                    {{-- Remove from this session's own bill (same endpoints as the products window). --}}
                                    <button type="button" class="ls-item-remove" data-item-remove
                                            data-url="{{ $isShared ? '/shared-sessions/'.$model->id.'/items/'.$item->id : '/bookings/'.$model->id.'/items/'.$item->id }}"
                                            data-name="{{ $item->name }}"
                                            aria-label="{{ __('app.ui.sessions.remove_item', ['product' => $item->name]) }}"
                                            title="{{ __('app.ui.sessions.remove_item', ['product' => $item->name]) }}"><x-ui.icon name="trash" /></button>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </details>
        @else
            <span class="ls-items-empty">{{ __('app.ui.sessions.no_products_short') }}</span>
        @endif
    </div>

    @php $cardQuickProducts = ($quickProductsByWorkspace ?? [])[$row->room->workspace_id] ?? $quickProducts; @endphp
    @if ($canSell && $cardQuickProducts->isNotEmpty() && ! $needsCheckIn)
        <div class="ls-session-quick">
            <span class="ls-session-quick-label">{{ __('app.ui.sessions.quick_add') }}</span>
            @foreach ($cardQuickProducts as $p)
                <button type="button" class="ls-qa" title="{{ __('app.ui.sessions.add_to_bill') }}: {{ $p->name }}"
                        data-qa-kind="{{ $isShared ? 'shared' : 'booking' }}" data-qa-id="{{ $model->id }}"
                        data-qa-product="{{ $p->id }}" data-qa-name="{{ $p->name }}" data-qa-customer="{{ $customer->name }}"><span class="ls-qa-plus" aria-hidden="true">+</span><x-ui.product-icon :name="$p->iconName()" size="sm" />{{ $p->name }}</button>
            @endforeach
        </div>
    @endif

    <div class="ls-session-foot">
        @if ($canSell && ! $needsCheckIn)
            @php $addLabel = __('app.ui.sessions.add_products_aria', ['name' => $customer->name]); @endphp
            @if ($isShared)
                <x-ui.button variant="ghost" icon="plus" :icon-only="true" :aria-label="$addLabel" :title="__('app.session.add_products')" onclick="openSessionModal({{ $model->id }}, 'products')" />
            @else
                <x-ui.button variant="ghost" icon="plus" :icon-only="true" :aria-label="$addLabel" :title="__('app.session.add_products')" onclick="openBookingItemsModal({{ $model->id }}, {{ Illuminate\Support\Js::from($itemsPayload) }}, {{ Illuminate\Support\Js::from($customer->name) }})" />
            @endif
        @endif
        @if ($isShared)
            <x-ui.button variant="tonal" onclick="openSessionModal({{ $model->id }}, 'checkout')">{!! $checkoutLabel !!}</x-ui.button>
        @elseif ($isOpenBooking)
            {{-- Price isn't known until checkout (same as a shared session) — reuse the
                 AJAX preview/confirm modal, not the plain-total booking checkout form. --}}
            <x-ui.button variant="tonal" onclick="openSessionModal({{ $model->id }}, 'checkout', 'open-booking')">{!! $checkoutLabel !!}</x-ui.button>
        @elseif ($needsCheckIn)
            {{-- The only valid transition for a shared-room reservation — a direct
                 "completed" checkout is rejected server-side until it's checked in
                 (BookingController::updateStatus()). Claims the reservation's own
                 party_size; a plain POST is enough since checkIn() already redirects
                 back here with a flash message either way. --}}
            <form method="POST" action="/bookings/{{ $model->id }}/check-in">
                @csrf
                <input type="hidden" name="party_size" value="{{ $model->party_size }}">
                <x-ui.button type="submit" variant="tonal">{{ __('app.ui.sessions.check_in') }}</x-ui.button>
            </form>
        @else
            <x-ui.button variant="tonal"
                onclick="openBookingCheckoutModal({{ $model->id }}, '{{ number_format($roomCharge, 2) }}', '{{ number_format($itemsTotal, 2) }}', '{{ number_format($bill, 2) }}', {{ Illuminate\Support\Js::from($customer->name) }}, {{ Illuminate\Support\Js::from($row->room->name) }})">{!! $checkoutLabel !!}</x-ui.button>
        @endif
    </div>
</article>
