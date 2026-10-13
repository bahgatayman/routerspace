{{--
    Coupon shown as a ticket: discount value (the highlight) and what it applies to
    on top, a perforated divider, then the code (copyable) and the limits that exist.
    <x-ui.coupon-card :coupon="$coupon" :business="$owner->business_name" :href="…">actions…</x-ui.coupon-card>
    Pass the coupon with withCount(['usages', 'rooms', 'products']) to avoid per-card queries.
    Read-only display of the existing Coupon model — no business rules live here.
--}}
@props(['coupon', 'business' => null, 'href' => null])
@php
    use App\Support\Money;

    $status = $coupon->statusKey();
    $muted = in_array($status, ['inactive', 'expired', 'limit_reached'], true);
    $isPct = $coupon->discount_type === \App\Models\Coupon::TYPE_PERCENTAGE;
    $value = (float) $coupon->discount_value;
    $valueText = $isPct
        ? rtrim(rtrim(number_format($value, 1), '0'), '.').'%'
        : Money::format($value);

    $roomsCount = $coupon->rooms_count ?? $coupon->rooms()->count();
    $productsCount = $coupon->products_count ?? $coupon->products()->count();
    $scope = collect([
        $coupon->appliesTo('rooms') ? ($roomsCount ? trans_choice('app.coupons.card.n_rooms', $roomsCount, ['count' => $roomsCount]) : __('app.coupons.all_rooms')) : null,
        $coupon->appliesTo('products') ? ($productsCount ? trans_choice('app.coupons.card.n_products', $productsCount, ['count' => $productsCount]) : __('app.coupons.all_products')) : null,
    ])->filter()->implode(' · ');
    $scopeIcon = match ($coupon->applies_to) { 'rooms' => 'building', 'products' => 'box', default => 'tag' };

    $used = $coupon->usedCount();
    $pct = $coupon->usage_limit ? min(100, (int) round($used / max(1, $coupon->usage_limit) * 100)) : null;
@endphp
<article {{ $attributes->merge(['class' => 'ls-coupon is-'.$status.($muted ? ' is-muted' : '')]) }}>
    <div class="ls-coupon-main">
        <div class="ls-coupon-top">
            <span class="ls-coupon-scope"><x-ui.icon :name="$scopeIcon" /><span class="ls-trunc">{{ $scope }}</span></span>
            <x-ui.badge :tone="$coupon->statusTone()">{{ $coupon->statusLabel() }}</x-ui.badge>
        </div>
        <p class="ls-coupon-value">
            @if (__('app.coupons.card.off_before') !== '')<small>{{ __('app.coupons.card.off_before') }}</small>@endif
            <b class="ls-num">{{ $valueText }}</b>
            @if (__('app.coupons.card.off_after') !== '')<small>{{ __('app.coupons.card.off_after') }}</small>@endif
        </p>
        @if ($business)
            <p class="ls-coupon-business ls-trunc"><x-ui.icon name="building" /><span>{{ $business }}</span></p>
        @endif
    </div>

    <div class="ls-coupon-perf" aria-hidden="true"></div>

    <div class="ls-coupon-stub">
        <div class="ls-coupon-code">
            @if ($href)
                <a href="{{ $href }}" class="ls-coupon-code-text" dir="ltr" title="{{ $coupon->code }}">{{ $coupon->code }}</a>
            @else
                <code class="ls-coupon-code-text" dir="ltr" title="{{ $coupon->code }}">{{ $coupon->code }}</code>
            @endif
            <button type="button" class="ls-coupon-copy" data-coupon-copy="{{ $coupon->code }}"
                    aria-label="{{ __('app.coupons.card.copy_code', ['code' => $coupon->code]) }}" title="{{ __('app.coupons.card.copy') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="8.5" y="8.5" width="12" height="12" rx="2"/><path d="M15.5 8.5V5a1.5 1.5 0 0 0-1.5-1.5H5A1.5 1.5 0 0 0 3.5 5v9A1.5 1.5 0 0 0 5 15.5h3.5"/></svg>
                <span>{{ __('app.coupons.card.copy') }}</span>
            </button>
        </div>

        <dl class="ls-coupon-meta">
            <div>
                <dt><x-ui.icon name="calendar" />{{ $coupon->isScheduled() ? __('app.coupons.card.starts') : __('app.coupons.card.valid_until') }}</dt>
                <dd>
                    @if ($coupon->isScheduled())
                        {{ $coupon->starts_at->translatedFormat('j M Y') }}
                    @else
                        {{ $coupon->expires_at?->translatedFormat('j M Y') ?? __('app.coupons.no_expiry') }}
                    @endif
                </dd>
            </div>
            @if ($coupon->minimum_spend !== null && (float) $coupon->minimum_spend > 0)
                <div><dt><x-ui.icon name="receipt" />{{ __('app.coupons.card.min_spend') }}</dt><dd class="ls-num">{{ Money::format((float) $coupon->minimum_spend) }}</dd></div>
            @endif
            @if ($coupon->per_customer_limit)
                <div><dt><x-ui.icon name="user" />{{ __('app.coupons.card.per_customer') }}</dt><dd class="ls-num">{{ trans_choice('app.coupons.card.times', $coupon->per_customer_limit, ['count' => $coupon->per_customer_limit]) }}</dd></div>
            @endif
            <div class="ls-coupon-usage">
                <dt><x-ui.icon name="users" />{{ __('app.coupons.col_usage') }}</dt>
                <dd class="ls-num">
                    {{ $coupon->usage_limit !== null ? __('app.coupons.used_of', ['used' => $used, 'limit' => $coupon->usage_limit]) : __('app.coupons.used_count', ['count' => $used]) }}
                </dd>
                @if ($pct !== null)
                    <div class="ls-meter {{ $pct >= 100 ? 'is-danger' : ($pct >= 80 ? 'is-warn' : '') }}" role="presentation"><i style="width: {{ $pct }}%"></i></div>
                @endif
            </div>
        </dl>
    </div>

    @if (trim($slot) !== '')
        <footer class="ls-coupon-actions">{{ $slot }}</footer>
    @endif
</article>

@once
<script>
// Copy a coupon code from any coupon card (delegated, so cards added later work too).
document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-coupon-copy]');
    if (!btn) return;
    const code = btn.dataset.couponCopy;
    const done = () => {
        btn.classList.add('is-copied');
        setTimeout(() => btn.classList.remove('is-copied'), 1400);
        if (window.LS && LS.toast) LS.toast(@json(__('app.coupons.card.copied')).replace(':code', code));
    };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(code).then(done, () => window.prompt('', code));
    } else {
        window.prompt('', code); // http on a LAN: let staff copy it manually
    }
});
</script>
@endonce
