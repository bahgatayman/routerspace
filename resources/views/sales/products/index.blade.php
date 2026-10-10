@extends('layouts.app')

@section('page-title', __('app.sales.products'))

@section('content')
    {{--
        Products as a card grid: name and selling price first (what staff scan
        for), then cost/profit, then stock with its status — Low and Out stand
        out, untracked products show no stock at all. Filters are server-side.
    --}}
    <x-ui.page-header :title="__('app.sales.products')" :count="$counts['all']">
        <x-slot:actions>
            {{-- Opens the pop-up below (panel.js data-ls-open); the href is the no-JS fallback. --}}
            <x-ui.button variant="primary" icon="plus" :href="route('products.create')" data-ls-open="add-product-modal" aria-haspopup="dialog">{{ __('app.sales.add_product') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.flash />

    @php
        // POST /products redirects back here on failure: reopen the pop-up with the
        // typed values and the messages (validation errors, or the plan-limit error
        // flashed together with the old input).
        $productFormFailed = $errors->hasAny(['name', 'type', 'icon', 'price', 'purchase_price', 'sku', 'description', 'track_stock', 'stock_quantity', 'low_stock_threshold'])
            || (session('error') && old('name') !== null);
    @endphp
    <x-ui.modal id="add-product-modal" :title="__('app.sales.add_product')" :subtitle="__('app.sales.add_product_subtitle')" size="wide">
        @if ($productFormFailed && session('error'))
            <x-ui.banner tone="danger" style="margin: 0">{{ session('error') }}</x-ui.banner>
        @endif
        <form method="POST" action="{{ route('products.store') }}" id="add-product-form"
              onsubmit="if (window.LS) LS.busy(document.querySelector('[form=add-product-form][type=submit]'));">
            @csrf
            @include('sales.products._form', ['product' => null])
        </form>
        <x-slot:footer>
            <div class="ls-push">
                <button type="button" class="ls-btn ls-btn--secondary" data-ls-close>{{ __('app.common.cancel') }}</button>
                <button type="submit" form="add-product-form" class="ls-btn ls-btn--primary"><x-ui.icon name="plus" /><span>{{ __('app.sales.add_product') }}</span></button>
            </div>
        </x-slot:footer>
    </x-ui.modal>
    @if ($productFormFailed)
        <script>document.addEventListener('DOMContentLoaded', () => window.LS && LS.open('add-product-modal'));</script>
    @endif


    @if ($counts['all'] > 0)
        {{--
            Toolbar: stock filter chips + catalog search. Search is server-side (covers
            every page) and live: typing swaps #product-results in place (script below);
            the plain GET form stays as the no-JS fallback.
        --}}
        <div class="ls-product-toolbar">
            <nav class="ls-chips" id="product-chips" aria-label="{{ __('app.inventory.stock') }}">
                @foreach (['all' => null, 'low' => 'low', 'out' => 'out'] as $key => $value)
                    @php $on = $stock === $value; @endphp
                    <a href="{{ request()->fullUrlWithQuery(['stock' => $value, 'page' => null]) }}" class="ls-chip {{ $on ? 'is-active' : '' }}" @if ($on) aria-current="page" @endif>
                        {{ __('app.inventory.filter_'.$key) }} <span class="ls-chip-count">{{ $counts[$key] }}</span>
                    </a>
                @endforeach
            </nav>
            <form method="GET" action="{{ route('products.index') }}" class="ls-search ls-product-search" role="search" id="product-search-form">
                @if ($stock)<input type="hidden" name="stock" value="{{ $stock }}">@endif
                <x-ui.icon name="search" />
                <input type="search" name="q" value="{{ $search }}" class="ls-input" placeholder="{{ __('app.inventory.search_placeholder') }}"
                       aria-label="{{ __('app.inventory.search_placeholder') }}" aria-controls="product-results" autocomplete="off" maxlength="100" data-product-search>
                <a href="{{ request()->fullUrlWithQuery(['q' => null, 'page' => null]) }}" class="ls-clear {{ $search !== '' ? 'is-on' : '' }}" data-product-clear aria-label="{{ __('app.ui.clear_search') }}"><x-ui.icon name="x" /></a>
            </form>
        </div>
    @endif

    <div id="product-results" aria-live="polite">
    @if ($products->isEmpty())
        <div class="ls-card">
            @if ($search !== '')
                <x-ui.empty-state illustration="box" :title="__('app.inventory.no_results', ['term' => $search])" :text="__('app.inventory.no_results_hint')">
                    <x-ui.button :href="request()->fullUrlWithQuery(['q' => null, 'page' => null])">{{ __('app.ui.clear_search') }}</x-ui.button>
                </x-ui.empty-state>
            @elseif ($stock)
                <x-ui.empty-state illustration="box" :title="__('app.inventory.filter_'.$stock)" :text="__('app.sales.no_products')">
                    <x-ui.button :href="route('products.index')">{{ __('app.inventory.filter_all') }}</x-ui.button>
                </x-ui.empty-state>
            @else
                <x-ui.empty-state illustration="box" :title="__('app.sales.no_products')">
                    <x-ui.button variant="primary" icon="plus" :href="route('products.create')" data-ls-open="add-product-modal" aria-haspopup="dialog">{{ __('app.sales.add_product') }}</x-ui.button>
                </x-ui.empty-state>
            @endif
        </div>
    @else
        {{--
            Card: identity (icon, name, type/SKU) → price with margin → cost & profit
            → stock status → actions. Every card has the same rows so prices line up
            across the grid; the whole card opens the product, rarely used actions
            (activate/deactivate, delete) live in the ⋯ menu.
        --}}
        <div class="ls-product-grid ls-product-grid--catalog">
            @foreach ($products as $product)
                @php
                    $isService = $product->isService();
                    $status = $product->stockStatus();
                    $hasCost = (float) $product->purchase_price > 0;
                    $profit = $product->profitPerUnit();
                    $margin = $product->marginPercent();
                    $menuId = 'product-'.$product->id.'-menu';
                @endphp
                <article class="ls-product ls-product--catalog {{ $product->is_active ? '' : 'is-inactive' }} is-{{ $status }}" aria-labelledby="product-{{ $product->id }}-name">
                    <header class="ls-product-head">
                        <x-ui.product-icon :name="$product->iconName()" size="lg" />
                        <div class="ls-product-title">
                            <a href="{{ route('products.show', $product) }}" class="ls-product-name" id="product-{{ $product->id }}-name" title="{{ $product->name }}">{{ $product->name }}</a>
                            <div class="ls-product-meta">
                                <span class="ls-product-kind is-{{ $isService ? 'service' : 'product' }}">{{ $isService ? __('app.sales.service') : __('app.sales.product') }}</span>
                                @if ($product->sku)
                                    <span class="ls-mono ls-trunc" title="{{ __('app.sales.sku') }}"><bdi dir="ltr">{{ $product->sku }}</bdi></span>
                                @endif
                                @unless ($product->is_active)
                                    <x-ui.badge tone="neutral" :dot="false">{{ __('app.status.inactive') }}</x-ui.badge>
                                @endunless
                            </div>
                        </div>
                        <div class="ls-menu-wrap ls-product-menu">
                            <button type="button" class="ls-btn ls-btn--ghost ls-btn--sm ls-btn--icon" data-ls-menu="{{ $menuId }}" aria-haspopup="true" aria-expanded="false"
                                    aria-label="{{ __('app.inventory.more_actions') }}: {{ $product->name }}" title="{{ __('app.inventory.more_actions') }}"><x-ui.icon name="more" /></button>
                            <div id="{{ $menuId }}" class="ls-pop" role="menu" hidden>
                                <div class="ls-menu">
                                    <a href="{{ route('products.show', $product) }}" class="ls-menu-item" role="menuitem"><x-ui.icon name="eye" /><span>{{ __('app.inventory.view') }}</span></a>
                                    <form method="POST" action="{{ route('products.toggle', $product) }}">
                                        @csrf
                                        <button type="submit" class="ls-menu-item" role="menuitem"><x-ui.icon name="power" />
                                            <span>{{ $product->is_active ? __('app.btn.deactivate') : __('app.btn.activate') }}</span></button>
                                    </form>
                                    <form method="POST" action="{{ route('products.destroy', $product) }}" onsubmit="return confirm(@js(__('app.sales.delete_confirm')))">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="ls-menu-item is-danger" role="menuitem"><x-ui.icon name="trash" /><span>{{ __('app.common.delete') }}</span></button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </header>

                    <div class="ls-product-pricing">
                        <div class="ls-product-price"><x-ui.money :amount="$product->price" /></div>
                        @if ($hasCost && $margin !== null)
                            <span class="ls-product-margin {{ $profit < 0 ? 'is-negative' : '' }}" title="{{ __('app.inventory.margin') }}">{{ rtrim(rtrim(number_format($margin, 1), '0'), '.') }}% {{ __('app.inventory.margin_short') }}</span>
                        @endif
                    </div>

                    <p class="ls-product-cost">
                        @if ($hasCost)
                            {{ __('app.inventory.cost') }} <x-ui.money :amount="$product->purchase_price" />
                            <span aria-hidden="true">·</span>
                            {{ __('app.inventory.profit') }} <b class="{{ $profit < 0 ? 'is-negative' : 'is-profit' }}"><x-ui.money :amount="$profit" /></b>
                        @else
                            <a href="{{ route('products.edit', $product) }}" class="ls-link">{{ __('app.inventory.add_cost') }}</a>
                        @endif
                    </p>

                    <div class="ls-product-stock is-{{ $status }}">
                        <span class="ls-product-dot" aria-hidden="true"></span>
                        @switch ($status)
                            @case('out') <b>{{ __('app.inventory.status.out') }}</b> @break
                            @case('low') <b>{{ __('app.inventory.status.low') }}</b> <span>· {{ trans_choice('app.inventory.units_left', $product->stock_quantity, ['count' => $product->stock_quantity]) }}</span> @break
                            @case('in_stock') <span>{{ trans_choice('app.inventory.units_in_stock', $product->stock_quantity, ['count' => $product->stock_quantity]) }}</span> @break
                            @default <span>{{ $isService ? __('app.inventory.always_available') : __('app.inventory.status.untracked') }}</span>
                        @endswitch
                    </div>

                    <footer class="ls-product-foot">
                        <x-ui.button size="sm" icon="edit" :href="route('products.edit', $product)">{{ __('app.common.edit') }}</x-ui.button>
                        @if (in_array($status, ['low', 'out'], true))
                            <x-ui.button size="sm" variant="tonal" icon="plus" :href="route('products.show', $product)">{{ __('app.inventory.restock') }}</x-ui.button>
                        @else
                            <x-ui.button size="sm" variant="ghost" :href="route('products.show', $product)">{{ __('app.inventory.view') }}</x-ui.button>
                        @endif
                    </footer>
                </article>
            @endforeach
        </div>

        <div class="mt-6">{{ $products->links() }}</div>
    @endif
    </div>

    <script>
    (function () {
        const form = document.getElementById('product-search-form');
        if (!form || !window.fetch || !window.DOMParser) return;
        const input = form.querySelector('[data-product-search]');
        const clear = form.querySelector('[data-product-clear]');
        let timer = null, ctrl = null;

        // Fetch the server-rendered page for a URL and swap in the results and
        // chips (their links carry the search term) — same markup, no reload.
        function load(url) {
            if (ctrl) ctrl.abort();
            ctrl = new AbortController();
            const results = document.getElementById('product-results');
            results.setAttribute('aria-busy', 'true');
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: ctrl.signal })
                .then((r) => (r.ok ? r.text() : Promise.reject(r)))
                .then((html) => {
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    ['product-results', 'product-chips'].forEach((id) => {
                        const fresh = doc.getElementById(id), cur = document.getElementById(id);
                        if (fresh && cur) cur.replaceWith(fresh);
                    });
                    history.replaceState(null, '', url);
                })
                .catch((e) => { if (e.name !== 'AbortError') window.location.href = url; })
                .finally(() => { const r = document.getElementById('product-results'); if (r) r.removeAttribute('aria-busy'); });
        }

        function urlFor(term) {
            const u = new URL(form.action, location.origin);
            new URLSearchParams(new FormData(form)).forEach((v, k) => { if (v !== '') u.searchParams.set(k, v); });
            if (!term) u.searchParams.delete('q');
            return u.pathname + u.search;
        }

        function run() {
            const term = input.value.trim();
            clear.classList.toggle('is-on', term !== '');
            load(urlFor(term));
        }

        input.addEventListener('input', () => {
            clear.classList.toggle('is-on', input.value.trim() !== '');
            clearTimeout(timer);
            timer = setTimeout(run, 250);
        });
        form.addEventListener('submit', (e) => { e.preventDefault(); clearTimeout(timer); run(); });
        clear.addEventListener('click', (e) => { e.preventDefault(); input.value = ''; clearTimeout(timer); run(); input.focus(); });

        // Pagination and the empty-state "Clear search" inside the results stay live too.
        document.addEventListener('click', (e) => {
            const a = e.target.closest('#product-results a[href]');
            if (!a || e.metaKey || e.ctrlKey || e.shiftKey) return;
            const u = new URL(a.href, location.origin);
            if (u.origin !== location.origin || u.pathname !== new URL(form.action).pathname) return;
            e.preventDefault();
            input.value = u.searchParams.get('q') || '';
            clear.classList.toggle('is-on', input.value !== '');
            load(u.pathname + u.search);
        });
    })();
    </script>
@endsection
