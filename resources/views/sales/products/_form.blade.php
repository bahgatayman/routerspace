{{--
    Product form (create + edit). Three groups: what it is → how it's priced
    (purchase + selling, with a live profit/margin preview) → inventory (only
    for physical products). The preview is display only; the server computes
    and stores everything (ProductController, InventoryService).
--}}
@php
    $isEdit = isset($product) && $product;
    $currentType = old('type', $product->type ?? 'product');
    $tracked = (bool) old('track_stock', $product?->track_stock ?? false);
    // Stock is set here only when tracking starts; afterwards it changes only via Restock/Remove.
    $stockLocked = $isEdit && $product->tracksStock();
    $currency = app()->getLocale() === 'ar' ? 'ج.م' : 'EGP';
    $money = fn ($v) => app()->getLocale() === 'ar' ? number_format($v, 2).' ج.م' : 'EGP '.number_format($v, 2);
@endphp
<div class="space-y-4" data-product-form>
    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="p-name">{{ __('app.sales.name') }} <span class="text-red-500">*</span></label>
        <input type="text" id="p-name" name="name" value="{{ old('name', $product->name ?? '') }}" required
            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
        @error('name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="p-type">{{ __('app.sales.type') }} <span class="text-red-500">*</span></label>
            <select name="type" id="p-type" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <option value="product" @selected($currentType === 'product')>{{ __('app.sales.product') }}</option>
                <option value="service" @selected($currentType === 'service')>{{ __('app.sales.service') }}</option>
            </select>
            <p class="text-xs text-gray-400 mt-1">{{ __('app.sales.type_hint') }}</p>
            @error('type') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="p-sku">{{ __('app.sales.sku') }}</label>
            <input type="text" id="p-sku" name="sku" value="{{ old('sku', $product->sku ?? '') }}"
                class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            @error('sku') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>
    </div>

    {{-- Icon --}}
    <fieldset class="ls-inv-group">
        <legend class="ls-inv-legend">{{ __('app.inventory.icon') }}</legend>
        <p class="ls-hint">{{ __('app.inventory.icon_hint') }}</p>
        <div class="ls-icon-picker" role="radiogroup" aria-label="{{ __('app.inventory.icon') }}">
            @foreach (\App\Models\Product::ICONS as $iconKey)
                <label class="ls-icon-opt">
                    <input type="radio" name="icon" value="{{ $iconKey }}" @checked(old('icon', $product->icon ?? '') === $iconKey)>
                    <x-ui.product-icon :name="$iconKey" size="xl" />
                    <span>{{ __('app.inventory.icons.'.$iconKey) }}</span>
                </label>
            @endforeach
        </div>
        @error('icon') <p class="ls-error">{{ $message }}</p> @enderror
    </fieldset>

    {{-- Pricing --}}
    <fieldset class="ls-inv-group">
        <legend class="ls-inv-legend">{{ __('app.inventory.pricing') }}</legend>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="ls-field">
                <label class="ls-label" for="p-cost">{{ __('app.inventory.purchase_price') }}</label>
                <div class="ls-input-affix"><span aria-hidden="true">{{ $currency }}</span>
                    <input type="number" step="0.01" min="0" id="p-cost" name="purchase_price" class="ls-input" inputmode="decimal"
                           value="{{ old('purchase_price', $product->purchase_price ?? '0.00') }}" aria-describedby="p-cost-hint">
                </div>
                <p class="ls-hint" id="p-cost-hint">{{ __('app.inventory.purchase_hint') }}</p>
                @error('purchase_price') <p class="ls-error">{{ $message }}</p> @enderror
            </div>
            <div class="ls-field">
                <label class="ls-label" for="p-price">{{ __('app.inventory.selling_price') }} <span class="ls-req" aria-hidden="true">*</span></label>
                <div class="ls-input-affix"><span aria-hidden="true">{{ $currency }}</span>
                    <input type="number" step="0.01" min="0.01" id="p-price" name="price" class="ls-input" inputmode="decimal" required
                           value="{{ old('price', $product->price ?? '') }}">
                </div>
                @error('price') <p class="ls-error">{{ $message }}</p> @enderror
            </div>
        </div>
        <div class="ls-inv-preview" aria-live="polite">
            <div><span>{{ __('app.inventory.profit_unit') }}</span><b class="ls-num" data-preview-profit>—</b></div>
            <div><span>{{ __('app.inventory.margin') }}</span><b class="ls-num" data-preview-margin>—</b></div>
        </div>
    </fieldset>

    {{-- Inventory (physical products only) --}}
    <fieldset class="ls-inv-group" data-inventory-group @if ($currentType === 'service') hidden @endif>
        <legend class="ls-inv-legend">{{ __('app.inventory.inventory') }}</legend>
        <label class="ls-inv-switch">
            <input type="checkbox" name="track_stock" value="1" id="p-track" @checked($tracked) @if ($stockLocked) data-locked @endif>
            <span><b>{{ __('app.inventory.track') }}</b><small>{{ __('app.inventory.track_hint') }}</small></span>
        </label>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4" data-stock-fields @unless ($tracked) hidden @endunless>
            <div class="ls-field">
                <label class="ls-label" for="p-stock">{{ __('app.inventory.current_stock') }}</label>
                @if ($stockLocked)
                    <div class="ls-inv-locked">
                        <b class="ls-num">{{ $product->stock_quantity }}</b>
                        <a class="ls-link" href="{{ route('products.show', $product) }}">{{ __('app.inventory.adjust') }}</a>
                    </div>
                    <p class="ls-hint">{{ __('app.inventory.stock_locked_hint') }}</p>
                @else
                    <input type="number" min="0" step="1" id="p-stock" name="stock_quantity" class="ls-input" inputmode="numeric"
                           value="{{ old('stock_quantity', $product->stock_quantity ?? 0) }}">
                @endif
                @error('stock_quantity') <p class="ls-error">{{ $message }}</p> @enderror
            </div>
            <div class="ls-field">
                <label class="ls-label" for="p-threshold">{{ __('app.inventory.low_alert') }}</label>
                <input type="number" min="0" step="1" id="p-threshold" name="low_stock_threshold" class="ls-input" inputmode="numeric" aria-describedby="p-threshold-hint"
                       placeholder="{{ __('app.inventory.low_alert_ph') }}" value="{{ old('low_stock_threshold', $product->low_stock_threshold ?? '') }}">
                <p class="ls-hint" id="p-threshold-hint">{{ __('app.inventory.low_alert_hint') }}</p>
                @error('low_stock_threshold') <p class="ls-error">{{ $message }}</p> @enderror
            </div>
        </div>
    </fieldset>

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1" for="p-desc">{{ __('app.sales.description') }}</label>
        <textarea name="description" id="p-desc" rows="3"
            class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">{{ old('description', $product->description ?? '') }}</textarea>
        @error('description') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
    </div>

    <label class="flex items-center gap-2">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $product->is_active ?? true))
            class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
        <span class="text-sm text-gray-700">{{ __('app.sales.is_active') }}</span>
    </label>
</div>

<script>
(function () {
    const root = document.querySelector('[data-product-form]');
    const cost = root.querySelector('#p-cost'), price = root.querySelector('#p-price');
    const type = root.querySelector('#p-type'), track = root.querySelector('#p-track');
    const invGroup = root.querySelector('[data-inventory-group]'), stockFields = root.querySelector('[data-stock-fields]');
    const rtl = document.documentElement.dir === 'rtl';
    const money = (n) => { const s = (Number(n) || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); return rtl ? `${s} ج.م` : `EGP ${s}`; };

    // Display-only preview of the same formulas the server uses (Product::profitPerUnit/marginPercent).
    function preview() {
        const p = parseFloat(price.value), c = parseFloat(cost.value) || 0;
        const profitEl = root.querySelector('[data-preview-profit]'), marginEl = root.querySelector('[data-preview-margin]');
        if (!(p > 0)) { profitEl.textContent = '—'; marginEl.textContent = '—'; return; }
        const profit = Math.round((p - c) * 100) / 100;
        profitEl.textContent = money(profit);
        profitEl.classList.toggle('is-negative', profit < 0);
        marginEl.textContent = (Math.round(profit / p * 1000) / 10) + '%';
    }
    function toggles() {
        invGroup.hidden = type.value === 'service';
        stockFields.hidden = !track.checked;
    }
    [cost, price].forEach((el) => el.addEventListener('input', preview));
    type.addEventListener('change', toggles);
    track.addEventListener('change', toggles);
    preview(); toggles();
})();
</script>
