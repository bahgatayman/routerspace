<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LinkSpace - Coworking Management Platform</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
                    animation: {
                        'float': 'float 6s ease-in-out infinite',
                        'float-slow': 'float 8s ease-in-out 2s infinite',
                        'pulse-glow': 'pulseGlow 3s ease-in-out infinite alternate',
                        'shimmer': 'shimmer 3s ease-in-out infinite',
                        'slide-up': 'slideUp 0.6s ease-out forwards',
                        'slide-up-1': 'slideUp 0.6s ease-out 0.1s forwards',
                        'slide-up-2': 'slideUp 0.6s ease-out 0.2s forwards',
                        'slide-up-3': 'slideUp 0.6s ease-out 0.3s forwards',
                        'slide-up-4': 'slideUp 0.6s ease-out 0.4s forwards',
                        'slide-up-5': 'slideUp 0.6s ease-out 0.5s forwards',
                        'orbit': 'orbit 20s linear infinite',
                        'orbit-slow': 'orbit 30s linear infinite reverse',
                        'tilt': 'tilt 10s ease-in-out infinite',
                        'pulse-soft': 'pulseSoft 4s ease-in-out infinite',
                    },
                    keyframes: {
                        float: {
                            '0%, 100%': { transform: 'translateY(0px)' },
                            '50%': { transform: 'translateY(-20px)' },
                        },
                        pulseGlow: {
                            '0%': { opacity: '0.4', transform: 'scale(1)' },
                            '100%': { opacity: '0.8', transform: 'scale(1.1)' },
                        },
                        shimmer: {
                            '0%': { backgroundPosition: '-200% 0' },
                            '100%': { backgroundPosition: '200% 0' },
                        },
                        slideUp: {
                            '0%': { opacity: '0', transform: 'translateY(40px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' },
                        },
                        orbit: {
                            '0%': { transform: 'rotate(0deg) translateX(60px) rotate(0deg)' },
                            '100%': { transform: 'rotate(360deg) translateX(60px) rotate(-360deg)' },
                        },
                        tilt: {
                            '0%, 100%': { transform: 'perspective(800px) rotateY(-3deg) rotateX(2deg)' },
                            '50%': { transform: 'perspective(800px) rotateY(3deg) rotateX(-2deg)' },
                        },
                        pulseSoft: {
                            '0%, 100%': { opacity: '0.5' },
                            '50%': { opacity: '0.8' },
                        },
                    },
                },
            },
        }
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500&display=swap');
        html { scroll-behavior: smooth; }

        .glass { background: rgba(255,255,255,0.05); backdrop-filter: blur(16px); border: 1px solid rgba(255,255,255,0.08); }
        .glass-strong { background: rgba(255,255,255,0.08); backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.12); }
        .glass-card { background: linear-gradient(135deg, rgba(255,255,255,0.04) 0%, rgba(255,255,255,0.01) 100%); backdrop-filter: blur(8px); border: 1px solid rgba(255,255,255,0.06); transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        .glass-card:hover { background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, rgba(255,255,255,0.03) 100%); border-color: rgba(255,255,255,0.15); transform: translateY(-8px) scale(1.01); box-shadow: 0 20px 60px rgba(0,0,0,0.3), 0 0 40px rgba(59,130,246,0.1); }

        .gradient-text { background: linear-gradient(135deg, #60a5fa, #a78bfa, #f472b6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-size: 200% 200%; animation: shimmer 4s ease-in-out infinite; }
        .gradient-text-simple { background: linear-gradient(135deg, #60a5fa, #818cf8); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .gradient-text-warm { background: linear-gradient(135deg, #fbbf24, #f472b6, #a78bfa); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }

        .grid-pattern { background-image: radial-gradient(rgba(255,255,255,0.03) 1px, transparent 1px); background-size: 40px 40px; }
        .grid-pattern-dense { background-image: radial-gradient(rgba(255,255,255,0.04) 1px, transparent 1px); background-size: 24px 24px; }

        .glow-ring { position: relative; }
        .glow-ring::before { content: ''; position: absolute; inset: -2px; border-radius: inherit; background: linear-gradient(135deg, #3b82f6, #8b5cf6, #ec4899); z-index: -1; opacity: 0; transition: opacity 0.4s ease; }
        .glow-ring:hover::before { opacity: 1; }

        .card-shine { position: relative; overflow: hidden; }
        .card-shine::after { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 60%); opacity: 0; transition: opacity 0.5s ease; transform: translate(var(--mx, 0), var(--my, 0)); pointer-events: none; }
        .card-shine:hover::after { opacity: 1; }

        .hero-grid { background-image: linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px); background-size: 60px 60px; }

        .morph-blob { border-radius: 30% 70% 70% 30% / 30% 30% 70% 70%; animation: morphBlob 12s ease-in-out infinite; }
        @keyframes morphBlob {
            0% { border-radius: 30% 70% 70% 30% / 30% 30% 70% 70%; }
            25% { border-radius: 58% 42% 75% 25% / 76% 46% 54% 24%; }
            50% { border-radius: 50% 50% 33% 67% / 55% 27% 73% 45%; }
            75% { border-radius: 33% 67% 58% 42% / 63% 68% 32% 37%; }
            100% { border-radius: 30% 70% 70% 30% / 30% 30% 70% 70%; }
        }

        .line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #0b1120; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #1e293b, #334155); border-radius: 4px; }

        .noise-overlay { position: fixed; inset: 0; pointer-events: none; z-index: 9999; opacity: 0.015; background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E"); background-repeat: repeat; background-size: 200px 200px; }
        .noise { position: relative; }
        .noise::before { content: ''; position: absolute; inset: 0; opacity: 0.02; background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E"); pointer-events: none; }

        .floating-shapes { position: absolute; inset: 0; overflow: hidden; pointer-events: none; }
        .floating-shapes span { position: absolute; display: block; width: 20px; height: 20px; border: 1px solid rgba(255,255,255,0.05); border-radius: 4px; animation: floatShape 15s linear infinite; }
        .floating-shapes span:nth-child(1) { left: 10%; top: 20%; width: 30px; height: 30px; animation-delay: 0s; animation-duration: 18s; }
        .floating-shapes span:nth-child(2) { left: 80%; top: 60%; width: 15px; height: 15px; animation-delay: 3s; animation-duration: 14s; border-radius: 50%; }
        .floating-shapes span:nth-child(3) { left: 30%; top: 80%; width: 25px; height: 25px; animation-delay: 6s; animation-duration: 20s; }
        .floating-shapes span:nth-child(4) { left: 70%; top: 10%; width: 18px; height: 18px; animation-delay: 2s; animation-duration: 16s; border-radius: 50%; }
        .floating-shapes span:nth-child(5) { left: 50%; top: 40%; width: 12px; height: 12px; animation-delay: 5s; animation-duration: 12s; }
        .floating-shapes span:nth-child(6) { left: 20%; top: 50%; width: 22px; height: 22px; animation-delay: 8s; animation-duration: 22s; border-radius: 50%; }

        @keyframes floatShape {
            0% { transform: translateY(0) rotate(0deg); opacity: 0; }
            10% { opacity: 1; }
            90% { opacity: 1; }
            100% { transform: translateY(-100vh) rotate(720deg); opacity: 0; }
        }
    </style>
</head>
<body class="bg-[#080d1a] text-white font-sans antialiased overflow-x-hidden">
    <div class="noise-overlay"></div>

    <!-- Navbar -->
    <nav class="fixed top-0 left-0 right-0 z-50">
        <div class="absolute inset-0 bg-[#080d1a]/80 backdrop-blur-xl border-b border-white/5"></div>
        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between h-16 lg:h-20">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center">
                    <span class="text-xs font-black text-white">LS</span>
                </div>
                <span class="font-bold text-lg tracking-tight">LinkSpace</span>
            </div>
            <div class="flex items-center gap-4">
                <a href="/login" class="text-sm text-gray-400 hover:text-white px-4 py-2 transition font-medium">Sign in</a>
                <button onclick="openDemoModal()" class="relative text-sm bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500 text-white px-5 py-2.5 rounded-xl transition font-medium shadow-lg shadow-blue-600/20 group cursor-pointer">
                    <span class="relative z-10">Request a Demo</span>
                    <div class="absolute inset-0 rounded-xl bg-white/0 group-hover:bg-white/10 transition"></div>
                </button>
            </div>
        </div>
    </nav>

    <!-- Hero -->
    <section class="relative min-h-screen flex items-center overflow-hidden pt-20">
        <div class="absolute inset-0 hero-grid"></div>
        <div class="floating-shapes">
            <span></span><span></span><span></span><span></span><span></span><span></span>
        </div>

        <!-- Ambient orbs -->
        <div class="absolute top-1/4 left-1/5 w-[600px] h-[600px] morph-blob bg-gradient-to-r from-blue-600/10 to-purple-600/10 blur-[100px] animate-pulse-soft"></div>
        <div class="absolute bottom-1/4 right-1/5 w-[500px] h-[500px] rounded-full bg-gradient-to-r from-pink-600/10 to-orange-600/10 blur-[120px] animate-pulse-soft" style="animation-delay: 2s;"></div>

        <!-- Decorative line grid -->
        <div class="absolute inset-0 overflow-hidden pointer-events-none">
            <div class="absolute top-0 left-1/2 -translate-x-1/2 w-px h-full bg-gradient-to-b from-transparent via-white/5 to-transparent"></div>
            <div class="absolute top-1/4 left-0 right-0 h-px bg-gradient-to-r from-transparent via-white/5 to-transparent"></div>
            <div class="absolute top-3/4 left-0 right-0 h-px bg-gradient-to-r from-transparent via-white/5 to-transparent"></div>
        </div>

        <div class="relative z-10 max-w-6xl mx-auto px-4 text-center w-full">
            <div class="inline-flex items-center gap-2 glass rounded-full px-4 py-1.5 mb-8 opacity-0 animate-slide-up">
                <span class="w-1.5 h-1.5 bg-emerald-400 rounded-full animate-pulse"></span>
                <span class="text-xs text-gray-400 font-medium tracking-wide">All-in-one coworking platform</span>
            </div>

            <h1 class="text-5xl sm:text-6xl lg:text-8xl font-black tracking-tight leading-[0.95] opacity-0 animate-slide-up-1">
                <span class="block">Run Your Space</span>
                <span class="block mt-2">
                    <span class="gradient-text">Without the Chaos</span>
                </span>
            </h1>

            <p class="mt-8 text-lg sm:text-xl text-gray-400 max-w-2xl mx-auto leading-relaxed opacity-0 animate-slide-up-2 font-light">
                Internet management, room bookings, Wi-Fi hotspot control, and workspace administration —
                <span class="text-white font-medium">all synced in real-time</span> with your MikroTik router.
            </p>

            <div class="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4 opacity-0 animate-slide-up-3">
                <button onclick="openDemoModal()" class="group relative inline-flex items-center gap-2 bg-gradient-to-r from-blue-600 to-purple-600 text-white px-8 py-4 rounded-2xl font-semibold transition-all duration-300 shadow-2xl shadow-blue-600/25 hover:shadow-purple-600/30 hover:scale-105 text-lg cursor-pointer">
                    <span>Request a Demo</span>
                    <svg class="w-5 h-5 group-hover:translate-x-1 transition" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                    <div class="absolute inset-0 rounded-2xl bg-white/0 group-hover:bg-white/10 transition"></div>
                </button>
                <a href="#features" class="glass hover:bg-white/5 text-white px-8 py-4 rounded-2xl font-semibold transition text-lg group">
                    <span class="flex items-center gap-2">
                        Explore Features
                        <svg class="w-4 h-4 group-hover:translate-y-1 transition" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7"/></svg>
                    </span>
                </a>
            </div>

            <!-- Capability tags -->
            <div class="mt-16 flex flex-wrap items-center justify-center gap-3 opacity-0 animate-slide-up-4">
                <span class="inline-flex items-center gap-1.5 text-xs text-gray-500 bg-white/[0.03] border border-white/5 px-3 py-1.5 rounded-full">
                    <svg class="w-3 h-3 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    MikroTik Hotspot
                </span>
                <span class="inline-flex items-center gap-1.5 text-xs text-gray-500 bg-white/[0.03] border border-white/5 px-3 py-1.5 rounded-full">
                    <svg class="w-3 h-3 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    Room Bookings
                </span>
                <span class="inline-flex items-center gap-1.5 text-xs text-gray-500 bg-white/[0.03] border border-white/5 px-3 py-1.5 rounded-full">
                    <svg class="w-3 h-3 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    Workspace Management
                </span>
                <span class="inline-flex items-center gap-1.5 text-xs text-gray-500 bg-white/[0.03] border border-white/5 px-3 py-1.5 rounded-full">
                    <svg class="w-3 h-3 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    Real-time Sync
                </span>
                <span class="inline-flex items-center gap-1.5 text-xs text-gray-500 bg-white/[0.03] border border-white/5 px-3 py-1.5 rounded-full">
                    <svg class="w-3 h-3 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    Multi-tenant
                </span>
            </div>
        </div>

        <div class="absolute bottom-6 left-1/2 -translate-x-1/2 animate-bounce">
            <svg class="w-5 h-5 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
        </div>
    </section>

    <!-- Trust / Stats Strip -->
    <section class="relative -mt-20 z-10 pb-8">
        <div class="max-w-5xl mx-auto px-4">
            <div class="glass-strong rounded-3xl p-8 md:p-10 grid grid-cols-2 md:grid-cols-4 gap-8 md:gap-12 opacity-0 animate-slide-up-5">
                <div class="text-center">
                    <p class="text-3xl md:text-4xl font-black gradient-text-simple">100%</p>
                    <p class="text-xs text-gray-500 mt-1.5 font-medium tracking-wide">API Coverage</p>
                </div>
                <div class="text-center">
                    <p class="text-3xl md:text-4xl font-black gradient-text">&lt;5m</p>
                    <p class="text-xs text-gray-500 mt-1.5 font-medium tracking-wide">Setup Time</p>
                </div>
                <div class="text-center">
                    <p class="text-3xl md:text-4xl font-black gradient-text-simple">Live</p>
                    <p class="text-xs text-gray-500 mt-1.5 font-medium tracking-wide">Router Sync</p>
                </div>
                <div class="text-center">
                    <p class="text-3xl md:text-4xl font-black gradient-text">All-in-1</p>
                    <p class="text-xs text-gray-500 mt-1.5 font-medium tracking-wide">Platform</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Features -->
    <section id="features" class="relative py-32 overflow-hidden">
        <div class="absolute inset-0 grid-pattern-dense"></div>
        <div class="absolute top-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-white/10 to-transparent"></div>

        <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto">
                <span class="inline-block text-[11px] font-semibold tracking-widest text-blue-400 bg-blue-500/10 px-4 py-1.5 rounded-full uppercase mb-6">Everything Included</span>
                <h2 class="text-4xl sm:text-5xl font-black tracking-tight leading-tight">
                    One platform.
                    <span class="gradient-text">Endless control.</span>
                </h2>
                <p class="mt-5 text-gray-400 text-lg leading-relaxed">
                    From Wi-Fi to workspaces — everything you need to run a modern coworking space, designed to work together.
                </p>
            </div>

            <!-- Feature blocks -->
            <div class="mt-24 space-y-32">
                <!-- 1. Hotspot -->
                <div class="grid lg:grid-cols-2 gap-12 lg:gap-20 items-center">
                    <div class="space-y-6 opacity-0 animate-slide-up" style="view-timeline-name: --hotspot;">
                        <span class="inline-flex items-center gap-2 text-xs font-semibold text-blue-400 bg-blue-500/10 px-3 py-1.5 rounded-full">
                            <span class="w-1.5 h-1.5 rounded-full bg-blue-400"></span>
                            Module 01
                        </span>
                        <h3 class="text-3xl sm:text-4xl font-bold tracking-tight leading-tight">
                            Wi-Fi & Hotspot
                            <span class="block gradient-text-simple">Management</span>
                        </h3>
                        <p class="text-gray-400 leading-relaxed">Full MikroTik integration — create users, assign speed profiles, and monitor live sessions directly from your browser. No CLI or Winbox needed.</p>
                        <ul class="space-y-3">
                            <li class="flex items-start gap-3 text-sm text-gray-300">
                                <svg class="w-5 h-5 text-emerald-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                <span>Phone-based usernames — simple for walk-in guests</span>
                            </li>
                            <li class="flex items-start gap-3 text-sm text-gray-300">
                                <svg class="w-5 h-5 text-emerald-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                <span>Custom speed profiles with download/upload limits</span>
                            </li>
                            <li class="flex items-start gap-3 text-sm text-gray-300">
                                <svg class="w-5 h-5 text-emerald-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                <span>Live session monitoring — IP, uptime, data usage</span>
                            </li>
                            <li class="flex items-start gap-3 text-sm text-gray-300">
                                <svg class="w-5 h-5 text-emerald-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                <span>Real-time sync — every action reflects instantly on your router</span>
                            </li>
                        </ul>
                    </div>
                    <div class="relative opacity-0 animate-slide-up" style="animation-delay: 0.2s;">
                        <div class="glass-card rounded-3xl p-8 card-shine">
                            <div class="grid grid-cols-2 gap-4">
                                <div class="glass rounded-2xl p-5 text-center">
                                    <p class="text-2xl font-bold text-blue-400">247</p>
                                    <p class="text-xs text-gray-500 mt-1">Active Users</p>
                                </div>
                                <div class="glass rounded-2xl p-5 text-center">
                                    <p class="text-2xl font-bold text-purple-400">12</p>
                                    <p class="text-xs text-gray-500 mt-1">Speed Profiles</p>
                                </div>
                                <div class="glass rounded-2xl p-5 text-center col-span-2">
                                    <p class="text-2xl font-bold text-emerald-400">38</p>
                                    <p class="text-xs text-gray-500 mt-1">Online Now</p>
                                </div>
                            </div>
                        </div>
                        <div class="absolute -bottom-4 -right-4 w-32 h-32 bg-blue-500/5 rounded-full blur-[60px]"></div>
                    </div>
                </div>

                <!-- 2. Workspace -->
                <div class="grid lg:grid-cols-2 gap-12 lg:gap-20 items-center">
                    <div class="lg:order-2 space-y-6 opacity-0 animate-slide-up">
                        <span class="inline-flex items-center gap-2 text-xs font-semibold text-indigo-400 bg-indigo-500/10 px-3 py-1.5 rounded-full">
                            <span class="w-1.5 h-1.5 rounded-full bg-indigo-400"></span>
                            Module 02
                        </span>
                        <h3 class="text-3xl sm:text-4xl font-bold tracking-tight leading-tight">
                            Workspace &
                            <span class="block gradient-text-warm">Room Management</span>
                        </h3>
                        <p class="text-gray-400 leading-relaxed">Organize your space into workspaces and rooms. Define types, set pricing, and control availability — all from a single dashboard.</p>
                        <ul class="space-y-3">
                            <li class="flex items-start gap-3 text-sm text-gray-300">
                                <svg class="w-5 h-5 text-emerald-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                <span>Multiple workspaces with independent room sets</span>
                            </li>
                            <li class="flex items-start gap-3 text-sm text-gray-300">
                                <svg class="w-5 h-5 text-emerald-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                <span>Room types: hot desk, private office, meeting room, event space</span>
                            </li>
                            <li class="flex items-start gap-3 text-sm text-gray-300">
                                <svg class="w-5 h-5 text-emerald-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                <span>Toggle availability on/off in one click</span>
                            </li>
                            <li class="flex items-start gap-3 text-sm text-gray-300">
                                <svg class="w-5 h-5 text-emerald-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                <span>Per-hour pricing — system auto-calculates totals</span>
                            </li>
                        </ul>
                    </div>
                    <div class="lg:order-1 relative opacity-0 animate-slide-up" style="animation-delay: 0.2s;">
                        <div class="glass-card rounded-3xl p-8 card-shine">
                            <div class="space-y-4">
                                <div class="flex items-center justify-between glass rounded-2xl px-5 py-4">
                                    <div>
                                        <p class="font-semibold text-sm">Hot Desk A1</p>
                                        <p class="text-xs text-gray-500">Ground Floor</p>
                                    </div>
                                    <span class="text-xs bg-emerald-500/10 text-emerald-400 px-2.5 py-1 rounded-full font-medium">Available</span>
                                </div>
                                <div class="flex items-center justify-between glass rounded-2xl px-5 py-4">
                                    <div>
                                        <p class="font-semibold text-sm">Private Office B2</p>
                                        <p class="text-xs text-gray-500">First Floor</p>
                                    </div>
                                    <span class="text-xs bg-amber-500/10 text-amber-400 px-2.5 py-1 rounded-full font-medium">15 EGP/hr</span>
                                </div>
                                <div class="flex items-center justify-between glass rounded-2xl px-5 py-4">
                                    <div>
                                        <p class="font-semibold text-sm">Meeting Room C1</p>
                                        <p class="text-xs text-gray-500">Second Floor</p>
                                    </div>
                                    <span class="text-xs bg-red-500/10 text-red-400 px-2.5 py-1 rounded-full font-medium">In Use</span>
                                </div>
                            </div>
                        </div>
                        <div class="absolute -top-4 -left-4 w-40 h-40 bg-indigo-500/5 rounded-full blur-[60px]"></div>
                    </div>
                </div>

                <!-- 3. Bookings -->
                <div class="grid lg:grid-cols-2 gap-12 lg:gap-20 items-center">
                    <div class="space-y-6 opacity-0 animate-slide-up">
                        <span class="inline-flex items-center gap-2 text-xs font-semibold text-amber-400 bg-amber-500/10 px-3 py-1.5 rounded-full">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                            Module 03
                        </span>
                        <h3 class="text-3xl sm:text-4xl font-bold tracking-tight leading-tight">
                            Intelligent
                            <span class="block gradient-text">Booking System</span>
                        </h3>
                        <p class="text-gray-400 leading-relaxed">Handle walk-ins and phone bookings with ease. Select a room, pick a time slot, and the system handles pricing, conflict checking, and status tracking.</p>
                        <ul class="space-y-3">
                            <li class="flex items-start gap-3 text-sm text-gray-300">
                                <svg class="w-5 h-5 text-emerald-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                <span>Customer directory with booking history</span>
                            </li>
                            <li class="flex items-start gap-3 text-sm text-gray-300">
                                <svg class="w-5 h-5 text-emerald-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                <span>Calendar view — see your day, week, and month at a glance</span>
                            </li>
                            <li class="flex items-start gap-3 text-sm text-gray-300">
                                <svg class="w-5 h-5 text-emerald-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                <span>Smart double-booking prevention in real-time</span>
                            </li>
                            <li class="flex items-start gap-3 text-sm text-gray-300">
                                <svg class="w-5 h-5 text-emerald-400 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                <span>Status workflow: pending → confirmed → completed / cancelled</span>
                            </li>
                        </ul>
                    </div>
                    <div class="relative opacity-0 animate-slide-up" style="animation-delay: 0.2s;">
                        <div class="glass-card rounded-3xl p-8 card-shine">
                            <div class="text-center mb-6">
                                <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">Today's Schedule</p>
                                <p class="text-lg font-bold mt-1">Thursday, June 16</p>
                            </div>
                            <div class="space-y-3">
                                <div class="flex items-center gap-4 glass rounded-2xl px-5 py-3.5">
                                    <div class="text-center">
                                        <p class="text-sm font-bold text-blue-400">10:00</p>
                                        <p class="text-[10px] text-gray-500">- 12:00</p>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium truncate">Ahmed Ali</p>
                                        <p class="text-xs text-gray-500 truncate">Meeting Room C1</p>
                                    </div>
                                    <span class="text-xs bg-blue-500/10 text-blue-400 px-2.5 py-1 rounded-full shrink-0 font-medium">Confirmed</span>
                                </div>
                                <div class="flex items-center gap-4 glass rounded-2xl px-5 py-3.5">
                                    <div class="text-center">
                                        <p class="text-sm font-bold text-emerald-400">14:00</p>
                                        <p class="text-[10px] text-gray-500">- 16:00</p>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium truncate">Sara Mohamed</p>
                                        <p class="text-xs text-gray-500 truncate">Private Office B2</p>
                                    </div>
                                    <span class="text-xs bg-emerald-500/10 text-emerald-400 px-2.5 py-1 rounded-full shrink-0 font-medium">Completed</span>
                                </div>
                                <div class="flex items-center gap-4 glass rounded-2xl px-5 py-3.5">
                                    <div class="text-center">
                                        <p class="text-sm font-bold text-amber-400">16:30</p>
                                        <p class="text-[10px] text-gray-500">- 18:00</p>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium truncate">Khaled Hassan</p>
                                        <p class="text-xs text-gray-500 truncate">Hot Desk A1</p>
                                    </div>
                                    <span class="text-xs bg-amber-500/10 text-amber-400 px-2.5 py-1 rounded-full shrink-0 font-medium">Pending</span>
                                </div>
                            </div>
                        </div>
                        <div class="absolute -bottom-4 -right-4 w-36 h-36 bg-amber-500/5 rounded-full blur-[60px]"></div>
                    </div>
                </div>
            </div>

            <!-- Extra feature grid -->
            <div class="mt-32">
                <div class="text-center mb-12">
                    <span class="inline-block text-[11px] font-semibold tracking-widest text-gray-500 bg-white/[0.03] px-4 py-1.5 rounded-full uppercase border border-white/5">And more</span>
                </div>
                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-5">
                    <div class="glass-card rounded-2xl p-6 group">
                        <div class="w-10 h-10 bg-gradient-to-br from-rose-500/20 to-rose-500/5 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition">
                            <svg class="w-5 h-5 text-rose-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/></svg>
                        </div>
                        <h4 class="font-semibold text-sm mb-1.5">Connection Testing</h4>
                        <p class="text-gray-500 text-xs leading-relaxed">Verify your MikroTik connection anytime. Instant feedback on reachability and API status.</p>
                    </div>
                    <div class="glass-card rounded-2xl p-6 group">
                        <div class="w-10 h-10 bg-gradient-to-br from-gray-500/20 to-gray-500/5 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition">
                            <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        </div>
                        <h4 class="font-semibold text-sm mb-1.5">Secure by Design</h4>
                        <p class="text-gray-500 text-xs leading-relaxed">Multi-tenant isolation. Each space sees only its own data, customers, and bookings.</p>
                    </div>
                    <div class="glass-card rounded-2xl p-6 group">
                        <div class="w-10 h-10 bg-gradient-to-br from-sky-500/20 to-sky-500/5 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition">
                            <svg class="w-5 h-5 text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                        </div>
                        <h4 class="font-semibold text-sm mb-1.5">Feature Flags</h4>
                        <p class="text-gray-500 text-xs leading-relaxed">Enable features per owner. Control exactly what each space can access — hotspot, workspace, or bookings.</p>
                    </div>
                    <div class="glass-card rounded-2xl p-6 group">
                        <div class="w-10 h-10 bg-gradient-to-br from-cyan-500/20 to-cyan-500/5 rounded-xl flex items-center justify-center mb-4 group-hover:scale-110 transition">
                            <svg class="w-5 h-5 text-cyan-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        </div>
                        <h4 class="font-semibold text-sm mb-1.5">Admin Dashboard</h4>
                        <p class="text-gray-500 text-xs leading-relaxed">Global overview for administrators. Monitor all owners, bookings, and revenue in real time.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works -->
    <section class="relative py-32">
        <div class="absolute inset-0 bg-gradient-to-b from-transparent via-blue-900/5 to-transparent"></div>
        <div class="absolute inset-0 grid-pattern-dense opacity-50"></div>
        <div class="absolute top-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-white/10 to-transparent"></div>

        <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-2xl mx-auto">
                <span class="inline-block text-[11px] font-semibold tracking-widest text-purple-400 bg-purple-500/10 px-4 py-1.5 rounded-full uppercase mb-6">Quick Start</span>
                <h2 class="text-4xl sm:text-5xl font-black tracking-tight">3 steps to <span class="gradient-text">go live</span></h2>
            </div>

            <div class="relative mt-20">
                <!-- Connector line -->
                <div class="absolute top-24 left-1/2 -translate-x-1/2 w-px h-[calc(100%-8rem)] bg-gradient-to-b from-blue-500/30 via-purple-500/30 to-pink-500/30 hidden lg:block"></div>

                <div class="space-y-20 lg:space-y-0">
                    <!-- Step 1 -->
                    <div class="lg:flex items-center gap-16">
                        <div class="lg:w-1/2 text-center lg:text-right opacity-0 animate-slide-up">
                            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-gradient-to-br from-blue-600 to-blue-700 mb-6 shadow-xl shadow-blue-600/20">
                                <span class="text-2xl font-black text-white">1</span>
                            </div>
                            <h3 class="text-2xl font-bold mb-3">Connect Your MikroTik</h3>
                            <p class="text-gray-400 leading-relaxed max-w-md lg:ml-auto">Enter your router's IP, API port, and credentials. We verify the connection in seconds — no configuration needed on your router.</p>
                        </div>
                        <div class="hidden lg:block w-px h-20 bg-gradient-to-b from-blue-500/50 to-transparent shrink-0"></div>
                        <div class="lg:w-1/2 opacity-0 animate-slide-up" style="animation-delay: 0.2s;">
                            <div class="glass-card rounded-3xl p-8 max-w-sm mx-auto">
                                <div class="space-y-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center">
                                            <svg class="w-4 h-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m0 0c1.657 0 3 4.03 3 9s-1.343 9-3 9z"/></svg>
                                        </div>
                                        <div>
                                            <p class="text-xs font-medium">API Host</p>
                                            <p class="text-xs text-gray-500 font-mono">192.168.88.1:8728</p>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2 text-xs">
                                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                                        <span class="text-emerald-400 font-medium">Connected successfully</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2 -->
                    <div class="lg:flex items-center gap-16 flex-row-reverse">
                        <div class="lg:w-1/2 text-center lg:text-left opacity-0 animate-slide-up">
                            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-gradient-to-br from-purple-600 to-purple-700 mb-6 shadow-xl shadow-purple-600/20">
                                <span class="text-2xl font-black text-white">2</span>
                            </div>
                            <h3 class="text-2xl font-bold mb-3">Set Up Your Space</h3>
                            <p class="text-gray-400 leading-relaxed max-w-md">Define your workspaces, rooms, speed profiles, and pricing. Everything is configured visually — no code or technical skills needed.</p>
                        </div>
                        <div class="hidden lg:block w-px h-20 bg-gradient-to-b from-purple-500/50 to-transparent shrink-0"></div>
                        <div class="lg:w-1/2 opacity-0 animate-slide-up" style="animation-delay: 0.2s;">
                            <div class="glass-card rounded-3xl p-8 max-w-sm mx-auto">
                                <div class="flex items-center justify-center gap-4">
                                    <div class="text-center">
                                        <p class="text-2xl font-bold text-indigo-400">3</p>
                                        <p class="text-xs text-gray-500">Workspaces</p>
                                    </div>
                                    <div class="text-gray-600 font-light text-xl">/</div>
                                    <div class="text-center">
                                        <p class="text-2xl font-bold text-purple-400">12</p>
                                        <p class="text-xs text-gray-500">Rooms</p>
                                    </div>
                                    <div class="text-gray-600 font-light text-xl">/</div>
                                    <div class="text-center">
                                        <p class="text-2xl font-bold text-amber-400">5</p>
                                        <p class="text-xs text-gray-500">Profiles</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 3 -->
                    <div class="lg:flex items-center gap-16">
                        <div class="lg:w-1/2 text-center lg:text-right opacity-0 animate-slide-up">
                            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-gradient-to-br from-emerald-600 to-emerald-700 mb-6 shadow-xl shadow-emerald-600/20">
                                <span class="text-2xl font-black text-white">3</span>
                            </div>
                            <h3 class="text-2xl font-bold mb-3">Manage & Grow</h3>
                            <p class="text-gray-400 leading-relaxed max-w-md lg:ml-auto">Start taking bookings, manage hotspot users, monitor sessions, and run your coworking space from one beautiful dashboard.</p>
                        </div>
                        <div class="hidden lg:block w-px h-20 bg-gradient-to-b from-emerald-500/50 to-transparent shrink-0"></div>
                        <div class="lg:w-1/2 opacity-0 animate-slide-up" style="animation-delay: 0.2s;">
                            <div class="glass-card rounded-3xl p-8 max-w-sm mx-auto">
                                <div class="grid grid-cols-3 gap-4 text-center">
                                    <div>
                                        <p class="text-lg font-bold text-blue-400">247</p>
                                        <p class="text-[10px] text-gray-500">Users</p>
                                    </div>
                                    <div>
                                        <p class="text-lg font-bold text-amber-400">18</p>
                                        <p class="text-[10px] text-gray-500">Bookings</p>
                                    </div>
                                    <div>
                                        <p class="text-lg font-bold text-emerald-400">38</p>
                                        <p class="text-[10px] text-gray-500">Online</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="relative py-32">
        <div class="absolute inset-0">
            <div class="absolute inset-0 bg-gradient-to-b from-transparent via-blue-900/10 to-transparent"></div>
            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[800px] h-[400px] bg-gradient-to-r from-blue-600/15 via-purple-600/15 to-pink-600/15 rounded-full blur-[150px]"></div>
        </div>
        <div class="absolute top-0 left-0 right-0 h-px bg-gradient-to-r from-transparent via-white/10 to-transparent"></div>

        <div class="relative z-10 max-w-3xl mx-auto px-4 text-center">
            <span class="inline-block text-[11px] font-semibold tracking-widest text-pink-400 bg-pink-500/10 px-4 py-1.5 rounded-full uppercase mb-6">Get Started Today</span>
            <h2 class="text-4xl sm:text-5xl font-black tracking-tight leading-tight mb-5">
                Ready to take your<br>
                <span class="gradient-text">coworking space online</span>?
            </h2>
            <p class="text-gray-400 text-lg max-w-xl mx-auto mb-10 leading-relaxed">
                Join LinkSpace and get a complete platform for managing your internet, workspaces, and bookings — all in one place.
            </p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                <button onclick="openDemoModal()" class="group relative inline-flex items-center gap-2 bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500 text-white px-10 py-4 rounded-2xl font-semibold transition-all duration-300 shadow-2xl shadow-blue-600/25 hover:shadow-purple-600/30 hover:scale-105 text-lg cursor-pointer">
                    <span>Request a Demo</span>
                    <svg class="w-5 h-5 group-hover:translate-x-1 transition" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                </button>
                <a href="/login" class="glass hover:bg-white/5 text-white px-10 py-4 rounded-2xl font-semibold transition text-lg">
                    Sign In
                </a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="border-t border-white/5 py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-7 h-7 rounded-lg bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center">
                    <span class="text-[10px] font-black text-white">LS</span>
                </div>
                <span class="font-semibold text-sm">LinkSpace</span>
            </div>
            <p class="text-sm text-gray-600">Coworking Management Platform — Built with Laravel & Tailwind CSS</p>
            <div class="flex items-center gap-4 text-sm text-gray-600">
                <a href="/login" class="hover:text-white transition">Sign in</a>
                <a href="/register" class="hover:text-white transition">Register</a>
            </div>
        </div>
    </footer>

    <!-- Flash Message -->
    @if (session('success'))
        <div id="flash-message" class="fixed top-24 left-1/2 -translate-x-1/2 z-50 max-w-md w-full mx-4">
            <div class="glass-strong rounded-2xl px-6 py-4 flex items-center gap-3 shadow-2xl">
                <svg class="w-5 h-5 text-emerald-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <p class="text-sm text-white font-medium">{{ session('success') }}</p>
                <button onclick="this.parentElement.parentElement.remove()" class="ml-auto text-gray-500 hover:text-white transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <script>setTimeout(() => { const el = document.getElementById('flash-message'); if (el) el.remove(); }, 6000);</script>
    @endif

    <!-- Demo Request Modal -->
    <div id="demo-modal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
        <div onclick="closeDemoModal()" class="absolute inset-0 bg-black/60 backdrop-blur-sm"></div>
        <div class="relative bg-[#0f172a] border border-white/10 rounded-3xl max-w-lg w-full p-8 shadow-2xl max-h-[90vh] overflow-y-auto">
            <button onclick="closeDemoModal()" class="absolute top-4 right-4 text-gray-500 hover:text-white transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>

            <div class="text-center mb-6">
                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                </div>
                <h3 class="text-xl font-bold text-white">Request a Demo</h3>
                <p class="text-sm text-gray-400 mt-1">Fill in your details and we'll get back to you.</p>
            </div>

            <form method="POST" action="/demo-request" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1">Name *</label>
                    <input type="text" name="name" required
                           class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-blue-500/50 focus:ring-1 focus:ring-blue-500/20 transition">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1">Email *</label>
                    <input type="email" name="email" required
                           class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-blue-500/50 focus:ring-1 focus:ring-blue-500/20 transition">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1">Phone</label>
                    <input type="text" name="phone"
                           class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-blue-500/50 focus:ring-1 focus:ring-blue-500/20 transition">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1">Company / Space Name</label>
                    <input type="text" name="company"
                           class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-blue-500/50 focus:ring-1 focus:ring-blue-500/20 transition">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-1">Message</label>
                    <textarea name="message" rows="3"
                              class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-sm text-white placeholder-gray-500 focus:outline-none focus:border-blue-500/50 focus:ring-1 focus:ring-blue-500/20 transition"></textarea>
                </div>
                <button type="submit"
                        class="w-full bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-500 hover:to-purple-500 text-white px-6 py-3.5 rounded-xl font-semibold transition text-sm shadow-lg shadow-blue-600/20">
                    Send Request
                </button>
            </form>
        </div>
    </div>

    <script>
        function openDemoModal() {
            document.getElementById('demo-modal').classList.remove('hidden');
            document.getElementById('demo-modal').classList.add('flex');
            document.body.style.overflow = 'hidden';
        }
        function closeDemoModal() {
            document.getElementById('demo-modal').classList.add('hidden');
            document.getElementById('demo-modal').classList.remove('flex');
            document.body.style.overflow = '';
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDemoModal();
        });
    </script>

</body>
</html>
