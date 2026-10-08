<!DOCTYPE html>
@php $locale = app()->getLocale(); $isRtl = $locale === 'ar'; @endphp
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" data-theme="light" data-server-now="{{ now()->getTimestampMs() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Resolve the theme (light | dark | system) and sidebar state before first paint — no flash. --}}
    <script>(function(){var r=document.documentElement;try{var p=localStorage.getItem('ls-theme')||'system';var d=p==='dark'||(p==='system'&&window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches);r.dataset.theme=d?'dark':'light';r.dataset.themePref=p;if(localStorage.getItem('ls-nav')==='collapsed')r.dataset.nav='collapsed';}catch(e){}})();</script>
    <meta name="color-scheme" content="light dark">
    <title>Link Space Admin - @yield('page-title', __('app.nav.dashboard'))</title>
    @include('partials.favicon')
    @include('partials.theme')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400..700&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    {{-- Same design system as the owner app — public/css/panel.css + components/ui. --}}
    <link rel="stylesheet" href="/css/panel.css?v={{ @filemtime(public_path('css/panel.css')) }}">
    @php
        $lsI18n = [
            'h' => __('app.ui.unit_h'), 'm' => __('app.ui.unit_m'), 's' => __('app.ui.unit_s'),
            'left' => __('app.ui.time_left'), 'ended' => __('app.ui.time_up'), 'dismiss' => __('app.ui.dismiss'),
            'expand' => __('app.ui.expand_sidebar'), 'collapse' => __('app.ui.collapse_sidebar'),
        ];
    @endphp
    <script>window.LS_I18N = @json($lsI18n);</script>
    <style>html, body { height: 100%; } .app-shell { height: 100vh; height: 100dvh; overflow: hidden; }</style>
    <script src="/js/panel.js?v={{ @filemtime(public_path('js/panel.js')) }}" defer></script>
    @stack('head')
</head>
<body class="ls-app ls-admin antialiased">
@php
    $admin = auth('admin')->user();
    $pendingRenewals = \App\Models\SubscriptionRequest::pending()->count();
    $navGroups = [
        ['label' => __('app.admin_platform.nav.overview'), 'items' => [
            ['href' => '/admin/dashboard', 'active' => request()->is('admin/dashboard'), 'icon' => 'home', 'label' => __('app.nav.dashboard')],
        ]],
        ['label' => __('app.admin_platform.nav.platform'), 'items' => [
            ['href' => '/admin/workspaces', 'active' => request()->is('admin/workspaces*', 'admin/owners*'), 'icon' => 'building', 'label' => __('app.admin_platform.nav.workspaces')],
            ['href' => '/admin/locations', 'active' => request()->is('admin/locations*'), 'icon' => 'pin', 'label' => __('app.admin_platform.nav.locations')],
            ['href' => '/admin/rooms', 'active' => request()->is('admin/rooms*'), 'icon' => 'door', 'label' => __('app.admin_platform.nav.rooms')],
            ['href' => '/admin/bookings', 'active' => request()->is('admin/bookings*'), 'icon' => 'calendar', 'label' => __('app.nav.bookings')],
        ]],
        ['label' => __('app.admin_platform.nav.money'), 'items' => [
            ['href' => '/admin/financial', 'active' => request()->is('admin/financial*'), 'icon' => 'money', 'label' => __('app.nav.financial')],
            ['href' => '/admin/plans', 'active' => request()->is('admin/plans*'), 'icon' => 'tag', 'label' => __('app.nav.plans')],
            ['href' => route('admin.subscription-requests.index'), 'active' => request()->is('admin/subscription-requests*'), 'icon' => 'receipt', 'label' => __('app.subscription.admin_requests'), 'count' => $pendingRenewals],
        ]],
        ['label' => __('app.admin_platform.nav.engage'), 'items' => [
            ['href' => '/admin/notifications', 'active' => request()->is('admin/notifications*'), 'icon' => 'bell', 'label' => __('app.nav.notifications')],
            ['href' => '/admin/features', 'active' => request()->is('admin/features*'), 'icon' => 'gear', 'label' => __('app.nav.features')],
        ]],
    ];
    $themeOptions = [
        'light' => ['icon' => 'sun', 'label' => __('app.ui.theme_light')],
        'dark' => ['icon' => 'moon', 'label' => __('app.ui.theme_dark')],
        'system' => ['icon' => 'monitor', 'label' => __('app.ui.theme_system')],
    ];
@endphp
    <a href="#main" class="ls-skip">{{ __('app.ui.skip_to_content') }}</a>
    <div class="app-shell ls-shell">
        <div id="sidebar-overlay" class="ls-side-scrim"></div>

        <aside id="sidebar" class="ls-side" aria-label="{{ __('app.ui.main_navigation') }}">
            <div class="ls-brand">
                <a href="/admin/dashboard" class="ls-brand-logo" aria-label="Link Space Admin — {{ __('app.nav.dashboard') }}">
                    <img src="/logo.webp" alt="Link Space Panel">
                </a>
                <span class="ls-admin-pill">Admin</span>
                <button type="button" class="ls-iconbtn ls-collapse" data-ls-nav-toggle aria-controls="sidebar" aria-expanded="true" aria-label="{{ __('app.ui.collapse_sidebar') }}" title="{{ __('app.ui.collapse_sidebar') }}"><x-ui.icon name="sidebar" /></button>
                <button type="button" class="ls-iconbtn ls-side-close" data-ls-side-close aria-label="{{ __('app.ui.close_menu') }}"><x-ui.icon name="x" /></button>
            </div>
            <nav class="nav-scroll ls-nav">
                @foreach ($navGroups as $group)
                    <div class="ls-nav-label">{{ $group['label'] }}</div>
                    @foreach ($group['items'] as $item)
                        <a href="{{ $item['href'] }}" class="ls-nav-item {{ $item['active'] ? 'is-active' : '' }}" title="{{ $item['label'] }}" @if($item['active']) aria-current="page" @endif>
                            <x-ui.icon :name="$item['icon']" />
                            <span class="ls-trunc">{{ $item['label'] }}</span>
                            @if (($item['count'] ?? 0) > 0)<b class="ls-nav-count ls-nav-count--alert">{{ $item['count'] }}</b>@endif
                        </a>
                    @endforeach
                @endforeach
            </nav>
            <div class="ls-side-foot">
                <x-ui.avatar :name="$admin->name" size="sm" />
                <span class="ls-who">
                    <b class="ls-trunc">{{ $admin->name }}</b>
                    <span class="ls-trunc">{{ __('app.admin.admin_panel') }}</span>
                </span>
                <form method="POST" action="/admin/logout">
                    @csrf
                    <button type="submit" class="ls-iconbtn ls-admin-logout" title="{{ __('app.nav.logout') }}" aria-label="{{ __('app.nav.logout') }}"><x-ui.icon name="logout" /></button>
                </form>
            </div>
        </aside>

        <div class="ls-sheet">
            <header class="ls-topbar">
                <button id="menu-toggle" type="button" class="ls-iconbtn ls-menu-btn" data-ls-side-open aria-controls="sidebar" aria-expanded="false" aria-label="{{ __('app.ui.open_menu') }}"><x-ui.icon name="menu" /></button>
                <nav class="ls-crumbs" aria-label="{{ __('app.ui.breadcrumb') }}">
                    <a href="/admin/dashboard" class="ls-crumb-home">{{ __('app.admin.admin_panel') }}</a>
                    @hasSection('crumb-parent')
                        <span class="ls-crumb-sep" aria-hidden="true">/</span>
                        <span class="ls-crumb-home ls-trunc">@yield('crumb-parent')</span>
                    @endif
                    <span class="ls-crumb-sep" aria-hidden="true">/</span>
                    <span class="ls-crumb-now ls-trunc" aria-current="page">@yield('page-title', __('app.nav.dashboard'))</span>
                </nav>
                <div class="ls-topbar-spacer"></div>

                <div class="ls-menu-wrap">
                    <button type="button" class="ls-iconbtn" data-ls-menu="theme-menu" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('app.ui.theme') }}" title="{{ __('app.ui.theme') }}">
                        <x-ui.icon name="sun" data-ls-theme-icon="light" />
                        <x-ui.icon name="moon" data-ls-theme-icon="dark" hidden />
                    </button>
                    <div id="theme-menu" class="ls-pop" role="menu" aria-label="{{ __('app.ui.theme') }}" hidden>
                        <div class="ls-menu">
                            <div class="ls-menu-label" aria-hidden="true">{{ __('app.ui.theme') }}</div>
                            @foreach ($themeOptions as $key => $opt)
                                <button type="button" class="ls-menu-item" role="menuitemradio" aria-checked="false" data-ls-theme-option="{{ $key }}">
                                    <x-ui.icon :name="$opt['icon']" />
                                    <span>{{ $opt['label'] }}</span>
                                    <x-ui.icon name="check" class="ls-menu-check" />
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="ls-lang" role="group" aria-label="{{ __('app.ui.language') }}">
                    <form method="POST" action="{{ route('language.switch', 'en') }}">@csrf<button type="submit" class="{{ $isRtl ? '' : 'is-on' }}" lang="en" aria-pressed="{{ $isRtl ? 'false' : 'true' }}">EN</button></form>
                    <form method="POST" action="{{ route('language.switch', 'ar') }}">@csrf<button type="submit" class="{{ $isRtl ? 'is-on' : '' }}" lang="ar" aria-pressed="{{ $isRtl ? 'true' : 'false' }}">عربي</button></form>
                </div>
            </header>

            <main id="main" class="ls-main flex-1 min-h-0 overflow-y-auto" tabindex="-1">
                <div class="ls-page ls-admin-page">
                    @if (session('error'))
                        <x-ui.banner tone="danger">{{ session('error') }}</x-ui.banner>
                    @endif
                    @if ($errors->any() && ! View::hasSection('handles-errors'))
                        <x-ui.banner tone="danger">{{ $errors->first() }}</x-ui.banner>
                    @endif
                    @yield('content')
                </div>
            </main>
        </div>
    </div>

    @if (session('success'))
        <script>document.addEventListener('DOMContentLoaded', () => window.LS && LS.toast(@json(session('success')), { tone: 'ok' }));</script>
        <noscript><p role="status">{{ session('success') }}</p></noscript>
    @endif

    <script>
    // Whole-row navigation for rows marked class="row-link" data-href="…" (each row also holds a real link).
    document.querySelectorAll('tr.row-link').forEach(row => {
        const ignore = e => e.target.closest('a, button, form, input, select, label');
        row.addEventListener('click', e => {
            if (ignore(e) || window.getSelection().toString()) return;
            if (e.metaKey || e.ctrlKey) window.open(row.dataset.href, '_blank', 'noopener'); else location.href = row.dataset.href;
        });
    });
    // Confirm any form marked data-confirm="message" with the design-system dialog.
    document.addEventListener('submit', e => {
        const f = e.target;
        if (!f.dataset || !f.dataset.confirm || f.dataset.confirmed) return;
        e.preventDefault();
        const dlg = document.getElementById('ls-confirm');
        document.getElementById('ls-confirm-sub').textContent = f.dataset.confirm;
        const reason = document.getElementById('ls-confirm-reason');
        reason.closest('.ls-field').hidden = !f.querySelector('input[name="reason"]');
        reason.value = '';
        const ok = document.getElementById('ls-confirm-ok');
        ok.textContent = f.dataset.confirmLabel || @json(__('app.common.confirm'));
        ok.className = 'ls-btn ' + (f.dataset.confirmTone === 'primary' ? 'ls-btn--primary' : 'ls-btn--danger');
        ok.onclick = () => {
            const r = f.querySelector('input[name="reason"]');
            if (r) r.value = reason.value;
            f.dataset.confirmed = '1';
            LS.busy(ok);
            f.requestSubmit ? f.requestSubmit() : f.submit();
        };
        LS.open('ls-confirm');
    });
    </script>

    <x-ui.modal id="ls-confirm" :title="__('app.admin_platform.confirm_title')" size="narrow">
        <div class="ls-field" hidden>
            <label class="ls-label" for="ls-confirm-reason">{{ __('app.admin_platform.reason') }} <span class="ls-opt">({{ __('app.admin_platform.optional') }})</span></label>
            <textarea id="ls-confirm-reason" class="ls-textarea" rows="2" maxlength="500"></textarea>
        </div>
        <x-slot:footer>
            <button type="button" class="ls-btn ls-btn--secondary" data-ls-close>{{ __('app.common.cancel') }}</button>
            <button type="button" id="ls-confirm-ok" class="ls-btn ls-btn--danger">{{ __('app.common.confirm') }}</button>
        </x-slot:footer>
    </x-ui.modal>

    @stack('scripts')
</body>
</html>
