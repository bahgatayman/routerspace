{{--
    Payment: an explicit choice (No / Partial / Full payment), the amount field
    only for Partial, then a Total · Paid now · Remaining summary. Always
    visible; before a room is picked the figures read "—" with a hint, and for
    shared rooms (billed at checkout) the choice gives way to a note.
    payment_choice is only a statement of intent — nothing is recorded until
    the booking itself saves. The server (BookingController::store/update)
    re-derives the amount from it (none → 0, full → its own quoted total,
    partial → must be > 0 and ≤ total) and the payment_status from that.
--}}
@php
    $amountPaid = $amountPaid ?? '';
    $memberPackageId = $memberPackageId ?? '';
    // No default: the choice is required on a new booking (edit passes the current one).
    $paymentChoice = in_array($paymentChoice ?? null, ['none', 'partial', 'full'], true) ? $paymentChoice : null;
    $choices = [
        'none' => __('app.booking.payment.choice_none_hint'),
        'partial' => __('app.booking.payment.choice_partial_hint'),
        'full' => __('app.booking.payment.choice_full_hint'),
    ];
@endphp
<section class="ls-plain-section" id="payment-section">
    <h2 class="ls-section-label">{{ __('app.booking.payment.title') }}</h2>

    {{--
        Hour packages: "Normal payment · Use hour package", shown only when the
        member has packages (filled from /bookings/package-options). A chosen
        package covers the whole booking — no deposit is taken.
    --}}
    <input type="hidden" name="member_package_id" id="f-member_package_id" value="{{ old('member_package_id', $memberPackageId) }}">
    <div class="ls-pkg-pay" id="pkg-pay" hidden>
        <div class="ls-inv-seg" role="radiogroup" aria-label="{{ __('app.packages.pay_title') }}">
            <label><input type="radio" name="pay_mode" value="normal" data-pay-mode @checked(! old('member_package_id', $memberPackageId))><span>{{ __('app.packages.pay_normal') }}</span></label>
            <label><input type="radio" name="pay_mode" value="package" data-pay-mode @checked((bool) old('member_package_id', $memberPackageId))><span>{{ __('app.packages.pay_package') }}</span></label>
        </div>
        <p class="ls-hint" id="pkg-needs" hidden></p>
        <div class="ls-pkg-options" id="pkg-options" role="radiogroup" aria-label="{{ __('app.packages.pick_package') }}" hidden></div>
        <div class="ls-pkg-covered" id="pkg-covered" hidden><x-ui.icon name="check-circle" /><span></span></div>
    </div>

    <div id="pay-deposit" class="ls-pay">
        <div class="ls-pay-choices" role="radiogroup" aria-label="{{ __('app.booking.payment.choice_label') }}">
            @foreach ($choices as $value => $hint)
                <label class="ls-pay-choice">
                    <input type="radio" name="payment_choice" value="{{ $value }}" data-pay-choice @checked($paymentChoice === $value)>
                    <span class="ls-pay-choice-body">
                        <span class="ls-pay-choice-title">{{ __('app.booking.payment.choice_'.$value) }}</span>
                        <span class="ls-pay-choice-hint">{{ $hint }}</span>
                    </span>
                </label>
            @endforeach
        </div>
        @error('payment_choice') <span class="ls-error"><x-ui.icon name="alert" />{{ $message }}</span> @enderror

        <div id="pay-amount-field" @if ($paymentChoice !== 'partial') hidden @endif>
            <x-ui.input name="amount_paid" type="number" :label="__('app.booking.payment.amount_received')"
                        :value="old('amount_paid', $amountPaid)" min="0" step="0.01" inputmode="decimal" />
            <span class="ls-error" id="pay-amount-error" hidden><x-ui.icon name="alert" /><span></span></span>
        </div>

        <dl class="ls-pay-summary" aria-live="polite">
            <div><dt>{{ __('app.booking.payment.total') }}</dt><dd class="ls-num" id="pay-total">&mdash;</dd></div>
            <div><dt>{{ __('app.booking.payment.paid_now') }}</dt><dd class="ls-num" id="pay-paid">&mdash;</dd></div>
            <div><dt>{{ __('app.booking.payment.remaining') }}</dt><dd class="ls-num" id="pay-remaining">&mdash;</dd></div>
        </dl>
        <div class="ls-pay-foot">
            <span class="ls-status" id="pay-status-text">&mdash;</span>
            <span class="ls-hint" id="pay-pick-room">{{ __('app.booking.payment.pick_room_first') }}</span>
        </div>
    </div>

    <p class="ls-hint" id="pay-shared-note" hidden>{{ __('app.booking.payment.shared_estimate_note') }}</p>
</section>
