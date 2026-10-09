{{-- Shared <head> for the Inertia root templates. Rendered once per full page load only. --}}
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="color-scheme" content="light dark">
@include('partials.favicon')
@include('partials.theme')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400..700&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/css/panel.css?v={{ @filemtime(public_path('css/panel.css')) }}">
@php
    $lsI18n = [
        'h' => __('app.ui.unit_h'), 'm' => __('app.ui.unit_m'), 's' => __('app.ui.unit_s'),
        'left' => __('app.ui.time_left'), 'ended' => __('app.ui.time_up'), 'dismiss' => __('app.ui.dismiss'),
        'expand' => __('app.ui.expand_sidebar'), 'collapse' => __('app.ui.collapse_sidebar'),
    ];
@endphp
<script>window.LS_I18N = @json($lsI18n);</script>
{{-- All UI strings for the React pages (lang/{locale}/app.php), once per full load. --}}
<script>window.LS_LANG = @json(__('app'));</script>
<style>html, body { height: 100%; } .app-shell { height: 100vh; height: 100dvh; overflow: hidden; }</style>
<script src="/js/panel.js?v={{ @filemtime(public_path('js/panel.js')) }}" defer></script>
@viteReactRefresh
@vite('resources/js/app.jsx')
