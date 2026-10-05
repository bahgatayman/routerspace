<!DOCTYPE html>
@php $locale = app()->getLocale(); $isRtl = $locale === 'ar'; @endphp
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link Space Panel Admin - @yield('page-title', __('app.nav.dashboard'))</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/logo-icon.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @include('partials.theme')
    @if($isRtl)
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Cairo', sans-serif; }</style>
    @endif
    <style>
        /* App shell pinned to the viewport — see layouts/app.blade.php. */
        html, body { height: 100%; }
        .app-shell {
            height: 100vh;
            height: 100dvh;
            overflow: hidden;
        }
    </style>
</head>
<body class="bg-[#f8fafc] font-sans antialiased">
    <div class="app-shell flex flex-col lg:flex-row">
        <!-- Mobile header -->
        <div class="lg:hidden shrink-0 flex items-center justify-between bg-brand-900 px-4 py-3">
            <div class="flex items-center gap-2">
                <img src="/logo.webp" alt="Link Space Panel" class="h-7 w-auto brightness-0 invert">
                <span class="text-[10px] bg-red-600 text-white px-1.5 py-0.5 rounded">Admin</span>
            </div>
            <div class="flex items-center gap-2">
                <button id="menu-toggle" class="text-white p-2 focus:outline-none">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Sidebar overlay (mobile) -->
        <div id="sidebar-overlay" class="lg:hidden fixed inset-0 bg-black/50 z-10 hidden" onclick="closeSidebar()"></div>

        <!-- Sidebar -->
        <aside id="sidebar" class="fixed lg:static inset-y-0 {{ $isRtl ? 'right-0' : 'left-0' }} z-20 w-[260px] bg-gradient-to-b from-brand-900 to-brand-800 text-white flex flex-col shrink-0 transition-transform duration-300 {{ $isRtl ? 'translate-x-full' : '-translate-x-full' }} lg:translate-x-0">
            <div class="shrink-0 flex flex-col items-center px-6 py-6 border-b border-white/10">
                <img src="/logo.webp" alt="Link Space Panel" class="h-8 w-auto brightness-0 invert">
                <span class="text-[10px] bg-red-600 text-white px-1.5 py-0.5 rounded mt-2">Admin</span>
            </div>
            <nav class="nav-scroll flex-1 min-h-0 overflow-y-auto px-3 py-4 space-y-1">
                <a href="/admin/dashboard" class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-white/10 transition {{ request()->is('admin/dashboard') ? 'bg-white/10 border-l-4 border-red-500' : '' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                    <span>{{ __('app.nav.dashboard') }}</span>
                </a>
                <a href="/admin/owners" class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-white/10 transition {{ request()->is('admin/owners*') ? 'bg-white/10 border-l-4 border-red-500' : '' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                    <span>{{ __('app.nav.owners') }}</span>
                </a>
                <a href="/admin/notifications" class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-white/10 transition {{ request()->is('admin/notifications*') ? 'bg-white/10 border-l-4 border-red-500' : '' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                    </svg>
                    <span>{{ __('app.nav.notifications') }}</span>
                </a>
                <a href="/admin/features" class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-white/10 transition {{ request()->is('admin/features*') ? 'bg-white/10 border-l-4 border-red-500' : '' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    </svg>
                    <span>{{ __('app.nav.features') }}</span>
                </a>
                <a href="/admin/workspaces" class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-white/10 transition {{ request()->is('admin/workspaces*') ? 'bg-white/10 border-l-4 border-red-500' : '' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                    <span>{{ __('app.nav.workspaces') }}</span>
                </a>
                <a href="/admin/bookings" class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-white/10 transition {{ request()->is('admin/bookings*') ? 'bg-white/10 border-l-4 border-red-500' : '' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <span>{{ __('app.nav.bookings') }}</span>
                </a>
                <a href="/admin/plans" class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-white/10 transition {{ request()->is('admin/plans*') ? 'bg-white/10 border-l-4 border-red-500' : '' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                    </svg>
                    <span>{{ __('app.nav.plans') }}</span>
                </a>
                @php $pendingRenewals = \App\Models\SubscriptionRequest::pending()->count(); @endphp
                <a href="{{ route('admin.subscription-requests.index') }}" class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-white/10 transition {{ request()->is('admin/subscription-requests*') ? 'bg-white/10 border-l-4 border-red-500' : '' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                    </svg>
                    <span class="flex-1">{{ __('app.subscription.admin_requests') }}</span>
                    @if ($pendingRenewals > 0)
                        <span class="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 text-[11px] font-bold text-white bg-red-500 rounded-full">{{ $pendingRenewals }}</span>
                    @endif
                </a>
                <a href="/admin/financial" class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-white/10 transition {{ request()->is('admin/financial*') ? 'bg-white/10 border-l-4 border-red-500' : '' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span>{{ __('app.nav.financial') }}</span>
                </a>
            </nav>
            <div class="px-4 py-4 border-t border-white/10">
                <p class="text-sm text-gray-400 truncate">{{ auth('admin')->user()->name }}</p>
                <form method="POST" action="/admin/logout">
                    @csrf
                    <button type="submit" class="text-xs text-red-400 hover:text-red-300 mt-1">{{ __('app.nav.logout') }}</button>
                </form>
            </div>
        </aside>

        <!-- Main -->
        <div class="flex-1 flex flex-col min-w-0 min-h-0">
            <header class="shrink-0 bg-white shadow-sm px-4 lg:px-6 py-4 flex items-center justify-between">
                <h1 class="text-lg font-semibold text-gray-800">@yield('page-title', __('app.nav.dashboard'))</h1>
                <div class="flex items-center gap-3">
                    <form method="POST" action="{{ route('language.switch', $isRtl ? 'en' : 'ar') }}" class="flex items-center gap-1.5">
                        @csrf
                        <button type="submit" class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 ease-in-out focus:outline-none {{ $isRtl ? 'bg-indigo-600' : 'bg-gray-300' }}" role="switch" aria-checked="{{ $isRtl ? 'true' : 'false' }}">
                            <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-sm transition duration-200 ease-in-out {{ $isRtl ? 'translate-x-[18px]' : 'translate-x-[3px]' }}"></span>
                        </button>
                        <span class="text-xs font-medium {{ $isRtl ? 'text-indigo-600' : 'text-gray-500' }}">{{ $isRtl ? 'AR' : 'EN' }}</span>
                    </form>
                    <span class="text-sm text-gray-500 truncate">{{ __('app.admin.admin_panel') }}</span>
                </div>
            </header>

            <main class="flex-1 min-h-0 overflow-y-auto p-4 lg:p-6">
                @if (session('success'))
                    <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-6">
                        {{ session('success') }}
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>

    <script>
    function closeSidebar() {
        document.getElementById('sidebar').classList.add('{{ $isRtl ? "translate-x-full" : "-translate-x-full" }}');
        document.getElementById('sidebar-overlay').classList.add('hidden');
    }
    document.getElementById('menu-toggle').addEventListener('click', function() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        sidebar.classList.toggle('{{ $isRtl ? "translate-x-full" : "-translate-x-full" }}');
        overlay.classList.toggle('hidden');
    });
    </script>
</body>
</html>
