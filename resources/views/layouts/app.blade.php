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
    <title>Link Space Panel - {{ $owner->business_name ?? __('app.auth.linkspace') }}</title>
    @include('partials.favicon')
    @include('partials.theme')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400..700&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    {{-- Owner design system — see public/css/panel.css and resources/views/components/ui. --}}
    <link rel="stylesheet" href="/css/panel.css?v={{ @filemtime(public_path('css/panel.css')) }}">
    @php
        $lsI18n = [
            'h' => __('app.ui.unit_h'), 'm' => __('app.ui.unit_m'), 's' => __('app.ui.unit_s'),
            'left' => __('app.ui.time_left'), 'ended' => __('app.ui.time_up'),
            'dismiss' => __('app.ui.dismiss'),
            'expand' => __('app.ui.expand_sidebar'), 'collapse' => __('app.ui.collapse_sidebar'),
        ];
    @endphp
    <script>window.LS_I18N = @json($lsI18n);</script>
    <style>
        /* App shell pinned to the viewport: sidebar and top bar stay put, only <main> scrolls.
           dvh keeps mobile browser bars from adding phantom height over 100vh. */
        html, body { height: 100%; }
        .app-shell { height: 100vh; height: 100dvh; overflow: hidden; }
    </style>
    <script src="/js/panel.js?v={{ @filemtime(public_path('js/panel.js')) }}" defer></script>
</head>
<body class="ls-app antialiased">
@php
    $currentOwner = $owner;
    // Owner sessions have no permission grid — every nav item they can
    // reach via feature entitlement is visible. A staff session only
    // sees items it's also been granted the matching permission for.
    $can = fn (string $key) => ! $actingStaff || $actingStaff->hasPermission($key);

    // Grouped navigation — shared with the React layout (App\Support\OwnerNavigation).
    $navGroups = \App\Support\OwnerNavigation::groups($currentOwner, $actingStaff, $navActiveSessionsCount ?? 0);
    $who = $actingStaff->name ?? $owner->name ?? $owner->business_name;
    $toneFor = fn ($c) => match ($c) { 'red', 'rose' => 'danger', 'yellow', 'amber', 'orange' => 'warning', 'green', 'emerald' => 'success', default => 'info' };
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
                <a href="/dashboard" class="ls-brand-logo" aria-label="Link Space — {{ __('app.nav.dashboard') }}">
                    <img src="/logo.webp" alt="Link Space Panel">
                </a>
                <button type="button" class="ls-iconbtn ls-collapse" data-ls-nav-toggle aria-controls="sidebar" aria-expanded="true" aria-label="{{ __('app.ui.collapse_sidebar') }}" title="{{ __('app.ui.collapse_sidebar') }}"><x-ui.icon name="sidebar" /></button>
                <button type="button" class="ls-iconbtn ls-side-close" data-ls-side-close aria-label="{{ __('app.ui.close_menu') }}"><x-ui.icon name="x" /></button>
            </div>
            <nav class="nav-scroll ls-nav">
                @foreach ($navGroups as $group)
                    @php $visible = collect($group['items'])->where('show', true); @endphp
                    @if ($visible->isNotEmpty())
                        <div class="ls-nav-label">{{ $group['label'] }}</div>
                        @foreach ($visible as $item)
                            <a href="{{ $item['href'] }}" class="ls-nav-item {{ $item['active'] ? 'is-active' : '' }}" title="{{ $item['label'] }}" @if($item['active']) aria-current="page" @endif>
                                <x-ui.icon :name="$item['icon']" />
                                <span class="ls-trunc">{{ $item['label'] }}</span>
                                @if (($item['count'] ?? 0) > 0)<b class="ls-nav-count">{{ $item['count'] }}</b>@endif
                            </a>
                        @endforeach
                    @endif
                @endforeach
            </nav>
            <div class="ls-side-foot">
                <x-ui.avatar :name="$who" size="sm" />
                <a href="/profile" class="ls-who" title="{{ __('app.nav.my_profile') }}">
                    <b class="ls-trunc">{{ $who }}</b>
                    <span class="ls-trunc">{{ $owner->business_name }}</span>
                </a>
                <form method="POST" action="/logout">
                    @csrf
                    <button type="submit" class="ls-iconbtn" title="{{ __('app.nav.logout') }}" aria-label="{{ __('app.nav.logout') }}"><x-ui.icon name="logout" /></button>
                </form>
            </div>
        </aside>

        <div class="ls-sheet">
            <header class="ls-topbar">
                <button id="menu-toggle" type="button" class="ls-iconbtn ls-menu-btn" data-ls-side-open aria-controls="sidebar" aria-expanded="false" aria-label="{{ __('app.ui.open_menu') }}"><x-ui.icon name="menu" /></button>
                <nav class="ls-crumbs" aria-label="{{ __('app.ui.breadcrumb') }}">
                    <span class="ls-crumb-home ls-trunc" style="max-width:220px">{{ $owner->business_name }}</span>
                    <span class="ls-crumb-sep" aria-hidden="true">/</span>
                    <span class="ls-crumb-now ls-trunc" aria-current="page">@yield('page-title', __('app.nav.dashboard'))</span>
                </nav>
                <div class="ls-topbar-spacer"></div>
                <span class="ls-live-pill" aria-hidden="true"><x-ui.icon name="clock" /><span id="ls-clock">{{ now()->format('g:i A') }}</span></span>

                {{-- Notifications --}}
                <div class="ls-menu-wrap" id="notif-wrap">
                    <button type="button" class="ls-iconbtn" data-ls-menu="notif-dropdown" data-ls-menu-focus="none" aria-haspopup="true" aria-expanded="false"
                            aria-label="{{ __('app.notif.title') }}{{ ($navUnreadCount ?? 0) > 0 ? ' — '.__('app.ui.unread_count', ['count' => $navUnreadCount]) : '' }}">
                        <x-ui.icon name="bell" />
                        @if(($navUnreadCount ?? 0) > 0)
                            <span class="ls-ndot" aria-hidden="true">{{ $navUnreadCount > 9 ? '9+' : $navUnreadCount }}</span>
                        @endif
                    </button>
                    <div id="notif-dropdown" class="ls-pop ls-pop--notif" hidden>
                        <div class="ls-pop-head">
                            <span>{{ __('app.notif.title') }}</span>
                            @if(($navUnreadCount ?? 0) > 0)
                                <form method="POST" action="{{ route('notifications.read-all') }}">
                                    @csrf
                                    <button type="submit" class="ls-link" style="font-size:12.5px">{{ __('app.notif.mark_all_read') }}</button>
                                </form>
                            @endif
                        </div>
                        <div style="max-height:24rem;overflow-y:auto">
                            @forelse($navRecentNotifications ?? [] as $n)
                                <a href="{{ route('notifications.open', $n->id) }}" class="ls-notif {{ $n->isRead() ? '' : 'is-unread' }}">
                                    <span class="ls-notif-icon ls-notif-icon--{{ $toneFor($n->levelColor()) }}">
                                        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $n->iconPath() }}"/></svg>
                                    </span>
                                    <span class="ls-notif-body">
                                        <b>{{ $n->title }}</b>
                                        @if($n->body)<span>{{ \Illuminate\Support\Str::limit($n->body, 110) }}</span>@endif
                                        <small>{{ $n->created_at->diffForHumans() }}</small>
                                    </span>
                                    @unless($n->isRead())<span class="ls-notif-dot" role="img" aria-label="{{ __('app.ui.unread') }}"></span>@endunless
                                </a>
                            @empty
                                <div style="padding:32px 16px;text-align:center;font-size:14px;color:var(--color-text-muted)">{{ __('app.notif.empty') }}</div>
                            @endforelse
                        </div>
                        <a href="{{ route('notifications.index') }}" class="ls-pop-foot ls-link">{{ __('app.notif.view_all') }}</a>
                    </div>
                </div>

                {{-- Theme: Light / Dark / System --}}
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
                @if($currentOwner->subscriptionStatus() === 'expiring_soon')
                    <x-ui.banner tone="warn">{{ __('app.msg.subscription_expires_in', ['days' => $currentOwner->daysUntilExpiry()]) }}</x-ui.banner>
                @endif
                @yield('content')
            </main>
        </div>
    </div>

    @if (session('permission_denied'))
        <div id="permission-modal" class="ls-overlay is-open" onclick="if (event.target === this) closePermissionModal()">
            <div class="ls-dialog ls-dialog--narrow" role="alertdialog" aria-modal="true" aria-labelledby="permission-title" aria-describedby="permission-text">
                <div class="ls-dialog-body" style="padding-top:26px;justify-items:center;text-align:center">
                    <span class="ls-icon-circle ls-icon-circle--danger"><x-ui.icon name="lock" /></span>
                    <h3 class="ls-dialog-title" id="permission-title">{{ __('app.error.403_heading') }}</h3>
                    <p class="ls-muted" id="permission-text" style="margin:0">{{ session('permission_denied') }}</p>
                </div>
                <div class="ls-dialog-foot">
                    <button type="button" id="permission-close" onclick="closePermissionModal()" class="ls-btn ls-btn--primary ls-btn--block">{{ __('app.common.close') }}</button>
                </div>
            </div>
        </div>
        <script>
            function closePermissionModal() {
                const modal = document.getElementById('permission-modal');
                if (modal) modal.remove();
            }
            document.addEventListener('keydown', e => { if (e.key === 'Escape') closePermissionModal(); });
            document.getElementById('permission-close').focus();
        </script>
    @endif

    <script>
    // Live clock in the top bar, in the business timezone (minute precision is enough).
    (function () {
        const el = document.getElementById('ls-clock');
        if (!el) return;
        const fmt = () => el.textContent = new Date().toLocaleTimeString(@json($isRtl ? 'ar-EG-u-nu-latn' : 'en-US'), { hour: 'numeric', minute: '2-digit', timeZone: @json(config('app.timezone')) });
        fmt(); setInterval(fmt, 30000);
    })();

    // Whole-row navigation for any table row marked `class="row-link" data-href="…"`.
    // Each such row still contains a real <a>, so this only adds a convenience
    // layer — it is never the only way to reach the record.
    document.querySelectorAll('tr.row-link').forEach(row => {
        const ignore = event => event.target.closest('a, button, form, input, select, label');

        row.addEventListener('click', event => {
            if (ignore(event)) return;                        // let real controls act
            if (window.getSelection().toString()) return;     // don't navigate mid-selection

            if (event.metaKey || event.ctrlKey) {
                window.open(row.dataset.href, '_blank', 'noopener');
            } else {
                window.location.href = row.dataset.href;
            }
        });

        // Middle-click opens a new tab, matching normal link behaviour.
        row.addEventListener('auxclick', event => {
            if (event.button !== 1 || ignore(event)) return;
            event.preventDefault();
            window.open(row.dataset.href, '_blank', 'noopener');
        });
    });
    </script>

    {{-- EXPERIMENT: quick booking modal (opens from any /bookings/create link). Remove to switch off. --}}
    @if ($currentOwner->hasFeature('booking') && $can('bookings.create') && $can('bookings.view'))
        @include('bookings._quick-modal')
    @endif
</body>
</html>
