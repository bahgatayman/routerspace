{{--
    Premium 3D product/service illustration tile. Usage: <x-ui.product-icon name="cup" size="xl" />
    name must be one of Product::ICONS — unknown names fall back to 'box'. Artwork is a real
    transparent-background WebP image per icon (public/images/products/*.webp, ~5-8KB each),
    not a drawn SVG — see panel.css "Product illustrations" for the tile background/animation
    rules. Each key maps to a fixed pastel tile colour (never randomised) so the same product
    looks identical everywhere it's shown. Motion is CSS-only and neutralised app-wide by the
    existing prefers-reduced-motion rule — no JS, no extra dependency.
--}}
@props(['name' => 'box', 'size' => 'sm'])
@php
    $assets = ['box', 'cup', 'tea', 'bottle', 'juice', 'can', 'snack', 'printer', 'wifi', 'clock', 'lock', 'bolt', 'tag'];
    $softs = [
        'box' => '--color-surface-subtle', 'tag' => '--color-surface-subtle',
        'clock' => '--color-surface-subtle', 'lock' => '--color-surface-subtle',
        'cup' => '--color-coffee-soft', 'tea' => '--color-teal-soft',
        'bottle' => '--color-info-soft', 'juice' => '--color-warning-soft',
        'can' => '--color-danger-soft', 'snack' => '--color-snack-soft',
        'printer' => '--color-violet-soft', 'wifi' => '--color-info-soft',
        'bolt' => '--color-primary-soft',
    ];
    // Motion hooks (panel.css): gentle float for bottle/juice, tilt-on-hover for snack,
    // a tiny animated steam accent layered above the cup/tea artwork — matching the
    // brief's own per-product examples; everything else only gets hover/selection motion.
    $motion = [
        'bottle' => 'ls-pi-float-img', 'juice' => 'ls-pi-float-img', 'snack' => 'ls-pi-tilt-img',
    ];
    $steam = in_array($name, ['cup', 'tea'], true);
    $glow = in_array($name, ['bottle', 'juice'], true);

    $key = in_array($name, $assets, true) ? $name : 'box';
    $bg = $softs[$key] ?? '--color-surface-subtle';
    $imgClass = $motion[$key] ?? '';
@endphp
<span {{ $attributes->merge(['class' => 'ls-picon ls-picon-'.$size]) }} style="background: var({{ $bg }})" aria-hidden="true">
    @if ($glow)<span class="ls-pi-glow"></span>@endif
    <img src="{{ asset('images/products/'.$key.'.webp') }}" alt="" class="{{ $imgClass }}" width="240" height="240" loading="lazy">
    @if ($steam)
        <svg class="ls-pi-steam" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round" aria-hidden="true">
            <path d="M9.5 6c-.8-1-.8-2 0-3M14 6c-.8-1-.8-2 0-3" opacity=".65"/>
        </svg>
    @endif
</span>
