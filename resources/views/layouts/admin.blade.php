<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LinkSpace Admin - @yield('page-title', 'Dashboard')</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-[#f8fafc] font-sans antialiased">
    <div class="min-h-screen flex flex-col lg:flex-row">
        <!-- Mobile header -->
        <div class="lg:hidden flex items-center justify-between bg-[#1a0f0f] px-4 py-3">
            <div class="flex items-center gap-2">
                <img src="https://www.link-space.net/img/logo%20link%20space.png" alt="LinkSpace" class="h-7 w-auto brightness-0 invert">
                <span class="text-[10px] bg-red-600 text-white px-1.5 py-0.5 rounded">Admin</span>
            </div>
            <button id="menu-toggle" class="text-white p-2 focus:outline-none">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
        </div>

        <!-- Sidebar overlay (mobile) -->
        <div id="sidebar-overlay" class="lg:hidden fixed inset-0 bg-black/50 z-10 hidden" onclick="closeSidebar()"></div>

        <!-- Sidebar -->
        <aside id="sidebar" class="fixed lg:static inset-y-0 left-0 z-20 w-[260px] bg-gradient-to-b from-[#1a0f0f] to-[#2d1a1a] text-white flex flex-col shrink-0 transition-transform duration-300 -translate-x-full lg:translate-x-0">
            <div class="flex flex-col items-center px-6 py-6 border-b border-white/10">
                <img src="https://www.link-space.net/img/logo%20link%20space.png" alt="LinkSpace" class="h-8 w-auto brightness-0 invert">
                <span class="text-[10px] bg-red-600 text-white px-1.5 py-0.5 rounded mt-2">Admin</span>
            </div>
            <nav class="flex-1 px-3 py-4 space-y-1">
                <a href="/admin/dashboard" class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-white/10 transition {{ request()->is('admin/dashboard') ? 'bg-white/10 border-l-4 border-red-500' : '' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                    <span>Dashboard</span>
                </a>
                <a href="/admin/owners" class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-white/10 transition {{ request()->is('admin/owners*') ? 'bg-white/10 border-l-4 border-red-500' : '' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                    <span>Owners</span>
                </a>
                <a href="/admin/features" class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-white/10 transition {{ request()->is('admin/features*') ? 'bg-white/10 border-l-4 border-red-500' : '' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    </svg>
                    <span>Features</span>
                </a>
                <a href="/admin/workspaces" class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-white/10 transition {{ request()->is('admin/workspaces*') ? 'bg-white/10 border-l-4 border-red-500' : '' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                    <span>Workspaces</span>
                </a>
                <a href="/admin/bookings" class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-white/10 transition {{ request()->is('admin/bookings*') ? 'bg-white/10 border-l-4 border-red-500' : '' }}">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <span>Bookings</span>
                </a>
            </nav>
            <div class="px-4 py-4 border-t border-white/10">
                <p class="text-sm text-gray-400 truncate">{{ auth('admin')->user()->name }}</p>
                <form method="POST" action="/admin/logout">
                    @csrf
                    <button type="submit" class="text-xs text-red-400 hover:text-red-300 mt-1">Logout</button>
                </form>
            </div>
        </aside>

        <!-- Main -->
        <div class="flex-1 flex flex-col min-w-0">
            <header class="bg-white shadow-sm px-4 lg:px-6 py-4 flex items-center justify-between">
                <h1 class="text-lg font-semibold text-gray-800">@yield('page-title', 'Dashboard')</h1>
                <span class="text-sm text-gray-500 truncate ml-2">Admin Panel</span>
            </header>

            <main class="flex-1 overflow-y-auto p-4 lg:p-6">
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
        document.getElementById('sidebar').classList.add('-translate-x-full');
        document.getElementById('sidebar-overlay').classList.add('hidden');
    }
    document.getElementById('menu-toggle').addEventListener('click', function() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        sidebar.classList.toggle('-translate-x-full');
        overlay.classList.toggle('hidden');
    });
    </script>
</body>
</html>
