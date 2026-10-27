{{--
    Sticky (desktop) / fixed bottom bar (mobile, via CSS) confirmation panel
    — a checkout-style receipt, not a second copy of the form. Every field
    is filled in live by the page's script; nothing here is computed
    independently of the same room-options/payment data the rest of the
    form already uses.
--}}
@php $isEdit = $isEdit ?? false; @endphp
<aside class="ls-confirm" id="confirm-summary">
    <details class="ls-confirm-details" open>
        <summary class="ls-confirm-summary-toggle">
            <span class="ls-card-title">{{ __('app.booking.confirm.title') }}</span>
            <x-ui.icon name="chevron-right" class="ls-confirm-chevron" />
        </summary>

        <div class="ls-confirm-header">
            <div class="ls-confirm-customer" id="confirm-customer">&mdash;</div>
            <div class="ls-confirm-room" id="confirm-room">&mdash;</div>
            <div class="ls-confirm-datetime" id="confirm-datetime">&mdash;</div>
            <div class="ls-confirm-duration" id="confirm-duration">&mdash;</div>
        </div>

        <dl class="ls-confirm-list">
            <div class="ls-confirm-total"><dt>{{ __('app.booking.total') }}</dt><dd id="confirm-total" class="ls-num">&mdash;</dd></div>
            <div id="confirm-pricing-row" hidden><dt>{{ __('app.pricing.applied') }}</dt><dd id="confirm-pricing"></dd></div>
            <div id="confirm-coupon-row" hidden><dt>{{ __('app.coupons.checkout.discount') }}</dt><dd id="confirm-coupon" class="ls-num">&mdash;</dd></div>
            <div><dt>{{ __('app.booking.payment.paid_now') }}</dt><dd id="confirm-paid" class="ls-num">&mdash;</dd></div>
            <div><dt>{{ __('app.booking.payment.remaining') }}</dt><dd id="confirm-remaining" class="ls-num">&mdash;</dd></div>
        </dl>
        <x-ui.badge id="confirm-status-badge" tone="neutral" :dot="false">&mdash;</x-ui.badge>
    </details>

    <button type="submit" form="booking-form" class="ls-btn ls-btn--primary ls-btn--block" id="confirm-cta">
        {{ $isEdit ? __('app.booking.confirm.cta_edit', ['total' => '--']) : __('app.booking.confirm.cta', ['total' => '--']) }}
    </button>
</aside>
