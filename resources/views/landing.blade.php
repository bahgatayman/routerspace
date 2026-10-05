<!DOCTYPE html>
@php $locale = app()->getLocale(); $isRtl = $locale === 'ar'; @endphp
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link Space Panel — {{ __('app.landing.product_demo') }}</title>
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/logo-icon.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @include('partials.theme')
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');

        @if($isRtl)
        @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap');
        @endif

        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: transparent;
            color: #1c1917;
            -webkit-font-smoothing: antialiased;
        }

        @if($isRtl)
        body { font-family: 'Cairo', 'Inter', system-ui, sans-serif; }
        @endif

        .text-gradient {
            background: linear-gradient(135deg, #163c85 0%, #3f68af 50%, #6f96d1 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .fade-in { opacity: 0; transform: translateY(20px); transition: all 0.7s cubic-bezier(0.16, 1, 0.3, 1); }
        .fade-in.visible { opacity: 1; transform: translateY(0); }

        .product-screen {
            background: white;
            border-radius: 16px;
            border: 1px solid #e7e5e4;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .product-screen:hover {
            box-shadow: 0 12px 40px -12px rgba(0,0,0,0.08);
            transform: translateY(-2px);
        }

        .screen-tab {
            padding: 10px 16px;
            font-size: 0.8rem;
            font-weight: 500;
            color: #78716c;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            background: none;
        }
        .screen-tab:hover { color: #1c1917; background: #f5f5f4; }
        .screen-tab.active { color: #163c85; background: #eef3fb; }

        .screen-panel { display: none; }
        .screen-panel.active { display: block; }

        .mockup-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            border-radius: 10px;
            transition: background 0.15s;
        }
        .mockup-row:hover { background: #f5f5f4; }

        .mockup-stat {
            padding: 16px;
            border-radius: 12px;
            background: #fafaf9;
        }

        .flow-step {
            position: relative;
            padding: 32px;
            border-radius: 16px;
            background: white;
            border: 1px solid #e7e5e4;
            transition: all 0.3s;
        }
        .flow-step:hover { border-color: #b0c6e6; box-shadow: 0 8px 32px -8px rgba(22, 60, 133, 0.08); }

        .cta-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 14px 28px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            cursor: pointer;
            border: none;
        }
        .cta-btn:active { transform: translateY(0) scale(0.98); }
        .cta-btn:focus-visible { outline: 2px solid #6f96d1; outline-offset: 2px; }
        .cta-primary {
            background: #163c85;
            color: white;
            box-shadow: 0 4px 16px -4px rgba(22, 60, 133, 0.25);
        }
        .cta-primary:hover {
            background: #123068;
            box-shadow: 0 10px 28px -6px rgba(22, 60, 133, 0.4);
            transform: translateY(-2px);
        }
        .cta-secondary {
            background: white;
            color: #1c1917;
            border: 1px solid #e7e5e4;
        }
        .cta-secondary:hover { border-color: #b0c6e6; color: #163c85; box-shadow: 0 8px 24px -6px rgba(0,0,0,0.08); transform: translateY(-2px); }

        .pricing-card {
            background: white;
            border: 1px solid #e7e5e4;
            border-radius: 20px;
            padding: 32px;
            transition: all 0.3s;
        }
        .pricing-card:hover { border-color: #b0c6e6; box-shadow: 0 12px 32px -8px rgba(22, 60, 133, 0.08); }
        .pricing-card.featured {
            border-color: #88a6d8;
            box-shadow: 0 0 0 1px #b0c6e6, 0 24px 50px -18px rgba(22, 60, 133, 0.30);
        }
        @media (min-width: 640px) {
            .pricing-card.featured { transform: scale(1.05); z-index: 1; }
            .pricing-card.featured:hover { transform: scale(1.05) translateY(-3px); }
        }

        /* CTA arrow micro-interaction */
        .cta-btn svg { transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1); }
        .cta-primary:hover svg, .cta-secondary:hover svg { transform: translateX(3px); }

        /* Nav elevation on scroll */
        nav.nav-scrolled { box-shadow: 0 6px 24px -14px rgba(28, 25, 23, 0.22); }

        /* Scroll-spy active nav link — a dedicated class rather than juggling
           Tailwind color utilities, since desktop and mobile nav links start
           from different base colors (surface-500 vs surface-600). */
        [data-nav-link].nav-active { color: #123068; }

        .modal-overlay { background: rgba(28, 25, 23, 0.5); backdrop-filter: blur(8px); }

        /* Offset anchor targets so the fixed nav doesn't cover section headings */
        section[id] { scroll-margin-top: 5rem; }

        /* Real photo behind the whole page (fixed) + readability overlay */
        .page-bg {
            position: fixed;
            inset: 0;
            z-index: -1;
            background: #0d244e url('/images/workspace-bg.jpg') center / cover no-repeat;
        }
        .page-bg::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(247,248,250,0.80) 0%, rgba(247,248,250,0.88) 45%, rgba(238,243,251,0.93) 100%);
        }
        .hero-glow {
            position: absolute;
            border-radius: 9999px;
            filter: blur(64px);
            pointer-events: none;
        }
        @keyframes floatY { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-26px); } }
        .hero-glow.g1 { animation: floatY 9s ease-in-out infinite; }
        .hero-glow.g2 { animation: floatY 12s ease-in-out infinite reverse; }

        @keyframes cueBounce { 0%, 100% { transform: translateX(-50%) translateY(0); opacity: 0.55; } 50% { transform: translateX(-50%) translateY(6px); opacity: 1; } }
        .scroll-cue { animation: cueBounce 2s ease-in-out infinite; }

        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            .hero-glow.g1, .hero-glow.g2, .scroll-cue { animation: none; }
            .fade-in { transition: opacity 0.3s ease; transform: none !important; }
            .cta-primary:hover, .cta-secondary:hover, .cta-btn:active,
            .product-screen:hover, .flow-step:hover, .pricing-card:hover,
            .pricing-card.featured, .pricing-card.featured:hover {
                transform: none !important;
            }
        }

        @media (max-width: 640px) {
            .cta-btn { padding: 12px 22px; font-size: 0.85rem; }
        }
    </style>
</head>
<body>

    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-3 {{ $isRtl ? 'focus:right-3' : 'focus:left-3' }} focus:z-[100] focus:bg-white focus:text-brand-700 focus:font-semibold focus:text-sm focus:px-4 focus:py-2 focus:rounded-lg focus:shadow-lg">{{ __('app.landing.skip_to_content') }}</a>

    <!-- Fixed real workspace photo behind every section -->
    <div class="page-bg" aria-hidden="true"></div>

    <!-- ═══════════════════════════════════════════
         NAVIGATION
         ═══════════════════════════════════════════ -->
    <nav id="site-nav" class="fixed top-0 left-0 right-0 z-50 bg-white/80 backdrop-blur-xl border-b border-surface-100 transition-shadow duration-300">
        <div class="max-w-6xl mx-auto px-5 sm:px-8">
            <div class="flex items-center justify-between h-16">
                <a href="/" class="flex items-center gap-3 shrink-0">
                    <img src="/logo.webp"
                         alt="Link Space Panel"
                         class="h-10 w-auto">
                </a>

                <div class="hidden sm:flex items-center gap-8">
                    <a href="#product" data-nav-link class="text-sm font-medium text-surface-500 hover:text-surface-900 transition">{{ __('app.landing.product') }}</a>
                    <a href="#how-it-works" data-nav-link class="text-sm font-medium text-surface-500 hover:text-surface-900 transition">{{ __('app.landing.how_it_works') }}</a>
                    <a href="#pricing" data-nav-link class="text-sm font-medium text-surface-500 hover:text-surface-900 transition">{{ __('app.landing.pricing') }}</a>
                    <a href="/login" class="text-sm font-medium text-surface-500 hover:text-surface-900 transition">{{ __('app.landing.sign_in') }}</a>
                    <a href="/register" class="cta-btn cta-primary !py-2.5 !px-5 text-sm">{{ __('app.landing.get_started') }}</a>
                    <form method="POST" action="{{ route('language.switch', $isRtl ? 'en' : 'ar') }}" class="flex items-center gap-1.5 ml-2">
                        @csrf
                        <button type="submit" class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 ease-in-out focus:outline-none {{ $isRtl ? 'bg-brand-600' : 'bg-gray-300' }}" role="switch" aria-checked="{{ $isRtl ? 'true' : 'false' }}">
                            <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-sm transition duration-200 ease-in-out {{ $isRtl ? 'translate-x-[18px]' : 'translate-x-[3px]' }}"></span>
                        </button>
                        <span class="text-xs font-medium {{ $isRtl ? 'text-brand-600' : 'text-surface-500' }}">{{ $isRtl ? 'AR' : 'EN' }}</span>
                    </form>
                </div>

                <button id="mobile-menu-btn" class="sm:hidden p-2 text-surface-600" onclick="toggleMobile()" aria-expanded="false" aria-controls="mobile-menu" aria-label="{{ __('app.landing.toggle_menu') }}">
                    <svg id="mobile-menu-icon-open" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    <svg id="mobile-menu-icon-close" class="w-5 h-5 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <div id="mobile-menu" class="hidden sm:hidden border-t border-surface-100 bg-white">
            <div class="px-5 py-4 space-y-3">
                <a href="#product" data-nav-link class="block text-sm font-medium text-surface-600 py-2">{{ __('app.landing.product') }}</a>
                <a href="#how-it-works" data-nav-link class="block text-sm font-medium text-surface-600 py-2">{{ __('app.landing.how_it_works') }}</a>
                <a href="#pricing" data-nav-link class="block text-sm font-medium text-surface-600 py-2">{{ __('app.landing.pricing') }}</a>
                <a href="/login" class="block text-sm font-medium text-brand-600 py-2">{{ __('app.landing.sign_in') }}</a>
                <a href="/register" class="block w-full cta-btn cta-primary text-sm">{{ __('app.landing.get_started') }}</a>
                <form method="POST" action="{{ route('language.switch', $isRtl ? 'en' : 'ar') }}" class="flex items-center gap-1.5 pt-2">
                    @csrf
                    <button type="submit" class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 ease-in-out focus:outline-none {{ $isRtl ? 'bg-brand-600' : 'bg-gray-300' }}" role="switch" aria-checked="{{ $isRtl ? 'true' : 'false' }}">
                        <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-sm transition duration-200 ease-in-out {{ $isRtl ? 'translate-x-[18px]' : 'translate-x-[3px]' }}"></span>
                    </button>
                    <span class="text-xs font-medium {{ $isRtl ? 'text-brand-600' : 'text-surface-500' }}">{{ $isRtl ? 'العربية' : 'English' }}</span>
                </form>
            </div>
        </div>
    </nav>

    <main id="main-content">

    <!-- ═══════════════════════════════════════════
         IDENTITY SCREEN
         ═══════════════════════════════════════════ -->
    <section class="relative min-h-screen flex items-center pt-24 pb-20 px-5 sm:px-8 overflow-hidden">
        <!-- ambient floating glows -->
        <div class="hero-glow g1 bg-brand-300/40" style="width:460px;height:460px;top:-70px;{{ $isRtl ? 'right' : 'left' }}:-130px;"></div>
        <div class="hero-glow g2 bg-brand-400/25" style="width:540px;height:540px;bottom:-180px;{{ $isRtl ? 'left' : 'right' }}:-150px;"></div>

        <div class="relative z-10 max-w-3xl mx-auto text-center">
            <h1 class="text-[clamp(2.5rem,6vw,4.5rem)] font-extrabold tracking-tight leading-[1.08] mb-6 fade-in">
                <span class="text-surface-900">{{ __('app.landing.run_your_space') }}</span>
                <br>
                <span class="text-gradient">{{ __('app.landing.from_one_place') }}</span>
            </h1>

            <p class="text-base sm:text-lg text-surface-600 max-w-lg mx-auto leading-relaxed mb-10 fade-in" style="transition-delay:0.1s">
                {{ __('app.landing.hero_description') }}
            </p>

            <div class="flex flex-col sm:flex-row gap-3 justify-center mb-4 fade-in" style="transition-delay:0.15s">
                <a href="/register" class="cta-btn cta-primary">
                    {{ __('app.landing.get_started') }}
                    <svg class="w-4 h-4 {{ $isRtl ? 'rotate-180' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                </a>
                <button onclick="document.getElementById('product').scrollIntoView({behavior:'smooth'})" class="cta-btn cta-secondary bg-white/85 backdrop-blur">
                    {{ __('app.landing.see_how_it_works') }}
                </button>
            </div>
            <p class="text-xs text-surface-400 mb-12 fade-in" style="transition-delay:0.18s">{{ __('app.landing.no_credit_card_needed') }}</p>

            <!-- Hero visual: an immediate, tangible glimpse of the product — the
                 fuller, interactive version of this same data lives in the
                 Dashboard Preview section further down the page. Purely
                 decorative/illustrative, so it's exposed to assistive tech as
                 one described image rather than a wall of unrelated numbers. -->
            <div class="fade-in" style="transition-delay:0.22s" role="img" aria-label="{{ __('app.landing.hero_visual_alt') }}">
                <div class="product-screen max-w-2xl mx-auto text-left">
                    <div class="flex items-center gap-1.5 px-4 py-3 border-b border-surface-100">
                        <span class="w-2.5 h-2.5 rounded-full bg-red-300"></span>
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-300"></span>
                        <span class="w-2.5 h-2.5 rounded-full bg-green-300"></span>
                        <span class="ms-3 text-[0.65rem] font-medium text-surface-400 truncate">linkspace.app/dashboard</span>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 p-4 sm:p-5">
                        <div class="mockup-stat text-center">
                            <p class="text-[0.65rem] text-surface-400">Active Users</p>
                            <p class="text-xl font-bold text-surface-900 mt-1">128</p>
                        </div>
                        <div class="mockup-stat text-center">
                            <p class="text-[0.65rem] text-surface-400">Online Now</p>
                            <p class="text-xl font-bold text-brand-600 mt-1">24</p>
                        </div>
                        <div class="mockup-stat text-center">
                            <p class="text-[0.65rem] text-surface-400">Today's Bookings</p>
                            <p class="text-xl font-bold text-amber-600 mt-1">8</p>
                        </div>
                        <div class="mockup-stat text-center">
                            <p class="text-[0.65rem] text-surface-400">Month Revenue</p>
                            <p class="text-xl font-bold text-green-600 mt-1">ج.م 3,240</p>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- scroll cue -->
        <button onclick="document.getElementById('product').scrollIntoView({behavior:'smooth'})" class="scroll-cue absolute bottom-5 left-1/2 -translate-x-1/2 text-surface-500 hover:text-brand-600 transition hidden lg:block" aria-label="{{ __('app.landing.see_how_it_works') }}">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
        </button>
    </section>

    <!-- ═══════════════════════════════════════════
         TRUST / METRICS BAND
         ═══════════════════════════════════════════ -->
    <section class="px-5 sm:px-8 -mt-6 sm:-mt-10">
        <div class="max-w-4xl mx-auto">
            <div class="fade-in bg-white/75 backdrop-blur-md border border-surface-100 rounded-2xl shadow-sm shadow-surface-200/50 px-4 py-6 sm:px-8">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-y-6 gap-x-4 text-center sm:divide-x divide-surface-100">
                    <div class="px-2">
                        <p class="text-2xl sm:text-3xl font-extrabold text-surface-900 tracking-tight">Real-time</p>
                        <p class="text-xs text-surface-400 mt-1">Live sessions &amp; sync</p>
                    </div>
                    <div class="px-2">
                        <p class="text-2xl sm:text-3xl font-extrabold text-surface-900 tracking-tight">3-in-1</p>
                        <p class="text-xs text-surface-400 mt-1">Wi-Fi · Rooms · Booking</p>
                    </div>
                    <div class="px-2">
                        <p class="text-2xl sm:text-3xl font-extrabold text-surface-900 tracking-tight">5&nbsp;min</p>
                        <p class="text-xs text-surface-400 mt-1">Setup time</p>
                    </div>
                    <div class="px-2">
                        <p class="text-2xl sm:text-3xl font-extrabold text-surface-900 tracking-tight">EN · AR</p>
                        <p class="text-xs text-surface-400 mt-1">Fully bilingual</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ═══════════════════════════════════════════
         PRODUCT EXPERIENCE — Interactive Flow
         ═══════════════════════════════════════════ -->
    <section id="product" class="py-20 sm:py-28 px-5 sm:px-8">
        <div class="max-w-6xl mx-auto">
            <div class="text-center mb-16 fade-in">
                <span class="inline-block text-xs font-semibold text-brand-600 bg-brand-50 px-3 py-1 rounded-full mb-4">{{ __('app.landing.product_experience') }}</span>
                <h2 class="text-3xl sm:text-4xl font-bold tracking-tight text-surface-900">{{ __('app.landing.explore_the_system') }}</h2>
                <p class="mt-3 text-surface-500 text-base sm:text-lg">Click through each module to see how Link Space Panel works.</p>
            </div>

            <div class="product-screen fade-in">
                <!-- Screen Header -->
                <div class="flex items-center justify-between px-5 py-4 border-b border-surface-100">
                    <div class="flex items-center gap-2">
                        <img src="/logo.webp"
                             alt="Link Space Panel"
                             class="h-8 w-auto">
                        <span class="text-xs font-medium text-surface-400 hidden sm:inline">·</span>
                        <span class="text-xs font-medium text-surface-400 hidden sm:inline">{{ __('app.landing.product_demo') }}</span>
                    </div>
                    <div class="flex items-center gap-1 bg-surface-50 rounded-lg p-1">
                        <button class="screen-tab active" onclick="switchTab(0)">{{ __('app.landing.wifi_tab') }}</button>
                        <button class="screen-tab" onclick="switchTab(1)">{{ __('app.landing.rooms_tab') }}</button>
                        <button class="screen-tab" onclick="switchTab(2)">{{ __('app.landing.bookings_tab') }}</button>
                    </div>
                </div>

                <!-- Screen Content -->
                <div class="p-5 sm:p-8">
                    <!-- Tab 0: Wi-Fi Management -->
                    <div class="screen-panel active" id="tab-0">
                        <div class="grid sm:grid-cols-5 gap-6">
                            <div class="sm:col-span-3">
                                <div class="bg-surface-50 rounded-2xl p-5 sm:p-6">
                                    <!-- Mockup: Hotspot Users List -->
                                    <div class="flex items-center justify-between mb-4">
                                        <h4 class="text-sm font-semibold text-surface-900">Hotspot Users</h4>
                                        <span class="text-[0.65rem] font-medium text-surface-400 bg-white px-2.5 py-1 rounded-md border border-surface-200">128 active</span>
                                    </div>

                                    <div class="space-y-2">
                                        <div class="mockup-row bg-white border border-surface-200">
                                            <div class="w-8 h-8 rounded-full bg-brand-100 flex items-center justify-center text-[0.6rem] font-bold text-brand-700">AA</div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-medium text-surface-900 truncate">Ahmed Ali</p>
                                                <p class="text-[0.65rem] text-surface-400">+20 100 000 0001</p>
                                            </div>
                                            <span class="text-[0.6rem] font-medium text-green-600 bg-green-50 px-2 py-0.5 rounded-full">Active</span>
                                        </div>
                                        <div class="mockup-row bg-white border border-surface-200">
                                            <div class="w-8 h-8 rounded-full bg-brand-100 flex items-center justify-center text-[0.6rem] font-bold text-brand-700">SM</div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-medium text-surface-900 truncate">Sara Mahmoud</p>
                                                <p class="text-[0.65rem] text-surface-400">+20 101 000 0002</p>
                                            </div>
                                            <span class="text-[0.6rem] font-medium text-green-600 bg-green-50 px-2 py-0.5 rounded-full">Active</span>
                                        </div>
                                        <div class="mockup-row bg-white border border-surface-200">
                                            <div class="w-8 h-8 rounded-full bg-amber-100 flex items-center justify-center text-[0.6rem] font-bold text-amber-700">MK</div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-medium text-surface-900 truncate">Mohamed Khaled</p>
                                                <p class="text-[0.65rem] text-surface-400">+20 102 000 0003</p>
                                            </div>
                                            <span class="text-[0.6rem] font-medium text-surface-400 bg-surface-100 px-2 py-0.5 rounded-full">Offline</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="sm:col-span-2 space-y-4">
                                <div class="mockup-stat">
                                    <p class="text-[0.65rem] font-medium text-surface-400 uppercase tracking-wider">Active Sessions</p>
                                    <p class="text-2xl font-bold text-surface-900 mt-1">24</p>
                                    <div class="flex items-center gap-1.5 mt-2">
                                        <span class="flex items-center gap-1 text-[0.6rem] text-green-600 font-medium">
                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg>
                                            +12%
                                        </span>
                                        <span class="text-[0.6rem] text-surface-400">vs last week</span>
                                    </div>
                                </div>
                                <div class="mockup-stat">
                                    <p class="text-[0.65rem] font-medium text-surface-400 uppercase tracking-wider">Bandwidth Usage</p>
                                    <p class="text-2xl font-bold text-surface-900 mt-1">240 <span class="text-sm font-normal text-surface-400">Mbps</span></p>
                                    <div class="mt-3 h-1.5 bg-surface-200 rounded-full overflow-hidden">
                                        <div class="h-full bg-gradient-to-r from-brand-400 to-brand-600 rounded-full" style="width:24%"></div>
                                    </div>
                                </div>
                                <div class="mockup-stat">
                                    <p class="text-[0.65rem] font-medium text-surface-400 uppercase tracking-wider">Speed Profile</p>
                                    <p class="text-lg font-bold text-surface-900 mt-1">50 Mbps</p>
                                    <p class="text-[0.65rem] text-surface-400 mt-0.5">Standard Plan</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab 1: Room Management -->
                    <div class="screen-panel" id="tab-1">
                        <div class="grid sm:grid-cols-3 gap-4">
                            <div class="bg-surface-50 rounded-xl p-5 border border-surface-100 hover:border-brand-200 transition">
                                <div class="flex items-center justify-between mb-3">
                                    <div class="w-9 h-9 rounded-lg bg-brand-50 flex items-center justify-center">
                                        <svg class="w-4 h-4 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                                    </div>
                                    <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                                </div>
                                <h4 class="text-sm font-semibold text-surface-900">Meeting Room A</h4>
                                <p class="text-[0.7rem] text-surface-400 mt-1">Capacity: 8 · ج.م 150/hr</p>
                                <div class="mt-3 pt-3 border-t border-surface-200">
                                    <p class="text-[0.65rem] text-surface-500">Next: <span class="font-medium text-surface-700">10:00 - 12:00</span></p>
                                </div>
                            </div>
                            <div class="bg-surface-50 rounded-xl p-5 border border-surface-100 hover:border-brand-200 transition">
                                <div class="flex items-center justify-between mb-3">
                                    <div class="w-9 h-9 rounded-lg bg-brand-50 flex items-center justify-center">
                                        <svg class="w-4 h-4 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                                    </div>
                                    <span class="w-2 h-2 bg-amber-500 rounded-full"></span>
                                </div>
                                <h4 class="text-sm font-semibold text-surface-900">Private Office B</h4>
                                <p class="text-[0.7rem] text-surface-400 mt-1">Capacity: 4 · ج.م 200/hr</p>
                                <div class="mt-3 pt-3 border-t border-surface-200">
                                    <p class="text-[0.65rem] text-surface-500">Now: <span class="font-medium text-green-600">Sara M. · Active</span></p>
                                </div>
                            </div>
                            <div class="bg-surface-50 rounded-xl p-5 border border-surface-100 hover:border-brand-200 transition">
                                <div class="flex items-center justify-between mb-3">
                                    <div class="w-9 h-9 rounded-lg bg-green-50 flex items-center justify-center">
                                        <svg class="w-4 h-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                                    </div>
                                    <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                                </div>
                                <h4 class="text-sm font-semibold text-surface-900">Hot Desk Area</h4>
                                <p class="text-[0.7rem] text-surface-400 mt-1">Capacity: 20 · ج.م 50/hr</p>
                                <div class="mt-3 pt-3 border-t border-surface-200">
                                    <p class="text-[0.65rem] text-surface-500">Available: <span class="font-medium text-green-600">12 seats open</span></p>
                                </div>
                            </div>
                        </div>

                        <div class="mt-6 p-5 bg-surface-50 rounded-xl">
                            <div class="flex items-center justify-between mb-4">
                                <h4 class="text-sm font-semibold text-surface-900">Today's Room Schedule</h4>
                                <span class="text-[0.65rem] text-surface-400">3 rooms · 8 bookings</span>
                            </div>
                            <div class="space-y-2">
                                <div class="flex items-center gap-4 p-3 bg-white rounded-lg border border-surface-200">
                                    <span class="text-xs font-mono text-surface-500 w-20 shrink-0">10:00-12:00</span>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-surface-900">Meeting Room A</p>
                                        <p class="text-[0.65rem] text-surface-400">Ahmed Ali</p>
                                    </div>
                                    <span class="text-[0.6rem] font-medium text-blue-600 bg-blue-50 px-2 py-0.5 rounded-full">Confirmed</span>
                                </div>
                                <div class="flex items-center gap-4 p-3 bg-white rounded-lg border border-surface-200">
                                    <span class="text-xs font-mono text-surface-500 w-20 shrink-0">14:00-16:00</span>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-surface-900">Private Office B</p>
                                        <p class="text-[0.65rem] text-surface-400">Sara Mahmoud</p>
                                    </div>
                                    <span class="text-[0.6rem] font-medium text-green-600 bg-green-50 px-2 py-0.5 rounded-full">Active</span>
                                </div>
                                <div class="flex items-center gap-4 p-3 bg-white rounded-lg border border-surface-200">
                                    <span class="text-xs font-mono text-surface-500 w-20 shrink-0">16:00-18:00</span>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-surface-900">Hot Desk Area</p>
                                        <p class="text-[0.65rem] text-surface-400">Omar Youssef</p>
                                    </div>
                                    <span class="text-[0.6rem] font-medium text-amber-600 bg-amber-50 px-2 py-0.5 rounded-full">Pending</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tab 2: Booking Engine -->
                    <div class="screen-panel" id="tab-2">
                        <div class="grid sm:grid-cols-2 gap-6">
                            <div>
                                <h4 class="text-sm font-semibold text-surface-900 mb-4">New Booking</h4>
                                <div class="space-y-3">
                                    <div>
                                        <label class="block text-[0.7rem] font-medium text-surface-500 mb-1">Customer</label>
                                        <div class="w-full bg-white border border-surface-200 rounded-lg px-3 py-2.5 text-sm text-surface-900">Ahmed Ali</div>
                                    </div>
                                    <div>
                                        <label class="block text-[0.7rem] font-medium text-surface-500 mb-1">Room</label>
                                        <div class="w-full bg-white border border-surface-200 rounded-lg px-3 py-2.5 text-sm text-surface-900">Meeting Room A</div>
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-[0.7rem] font-medium text-surface-500 mb-1">Start Time</label>
                                            <div class="bg-white border border-surface-200 rounded-lg px-3 py-2.5 text-sm text-surface-900">10:00</div>
                                        </div>
                                        <div>
                                            <label class="block text-[0.7rem] font-medium text-surface-500 mb-1">End Time</label>
                                            <div class="bg-white border border-surface-200 rounded-lg px-3 py-2.5 text-sm text-surface-900">12:00</div>
                                        </div>
                                    </div>
                                    <div class="pt-2">
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="text-surface-500">Total</span>
                                            <span class="font-bold text-surface-900">ج.م 300</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-sm font-semibold text-surface-900 mb-4">Conflict Prevention</h4>
                                <div class="bg-green-50 border border-green-100 rounded-xl p-4 mb-4">
                                    <div class="flex items-start gap-3">
                                        <svg class="w-4 h-4 text-green-600 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        <div>
                                            <p class="text-sm font-medium text-green-800">No conflicts found</p>
                                            <p class="text-[0.7rem] text-green-600 mt-0.5">Meeting Room A is available for this time slot</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="bg-surface-50 rounded-xl p-4">
                                    <p class="text-[0.7rem] font-medium text-surface-500 mb-3">Today's Availability</p>
                                    <div class="space-y-2">
                                        <div class="flex items-center gap-3">
                                            <span class="text-[0.65rem] font-mono text-surface-400 w-16">08:00</span>
                                            <div class="flex-1 h-2 bg-surface-200 rounded-full overflow-hidden">
                                                <div class="h-full bg-green-400 rounded-full" style="width:100%"></div>
                                            </div>
                                            <span class="text-[0.6rem] text-surface-400 w-12 text-right">Open</span>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <span class="text-[0.65rem] font-mono text-surface-400 w-16">10:00</span>
                                            <div class="flex-1 h-2 bg-surface-200 rounded-full overflow-hidden">
                                                <div class="h-full bg-brand-400 rounded-full" style="width:40%"></div>
                                            </div>
                                            <span class="text-[0.6rem] text-surface-400 w-12 text-right">Booked</span>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <span class="text-[0.65rem] font-mono text-surface-400 w-16">14:00</span>
                                            <div class="flex-1 h-2 bg-surface-200 rounded-full overflow-hidden">
                                                <div class="h-full bg-amber-400 rounded-full" style="width:30%"></div>
                                            </div>
                                            <span class="text-[0.6rem] text-surface-400 w-12 text-right">Active</span>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <span class="text-[0.65rem] font-mono text-surface-400 w-16">16:00</span>
                                            <div class="flex-1 h-2 bg-surface-200 rounded-full overflow-hidden">
                                                <div class="h-full bg-green-400 rounded-full" style="width:100%"></div>
                                            </div>
                                            <span class="text-[0.6rem] text-surface-400 w-12 text-right">Open</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ═══════════════════════════════════════════
         HOW IT WORKS — Guided Flow
         ═══════════════════════════════════════════ -->
    <section id="how-it-works" class="py-20 sm:py-28 px-5 sm:px-8 bg-white/75 backdrop-blur-sm border-y border-white/60">
        <div class="max-w-6xl mx-auto">
            <div class="text-center mb-16 fade-in">
                <span class="inline-block text-xs font-semibold text-brand-600 bg-brand-50 px-3 py-1 rounded-full mb-4">{{ __('app.landing.simple_setup') }}</span>
                <h2 class="text-3xl sm:text-4xl font-bold tracking-tight text-surface-900">{{ __('app.landing.go_live_in_three_steps') }}</h2>
                <p class="mt-3 text-surface-500 text-base sm:text-lg">Connect, configure, manage — in that order.</p>
            </div>

            <div class="grid sm:grid-cols-3 gap-6 fade-in">
                <div class="flow-step">
                    <div class="w-10 h-10 rounded-xl bg-brand-50 flex items-center justify-center mb-5">
                        <span class="text-lg font-bold text-brand-600">1</span>
                    </div>
                    <h3 class="text-base font-bold text-surface-900 mb-2">{{ __('app.landing.connect_your_router') }}</h3>
                    <p class="text-sm text-surface-500 leading-relaxed">Enter your MikroTik router's IP and credentials. We verify the connection instantly — no configuration needed on your end.</p>
                </div>
                <div class="flow-step">
                    <div class="w-10 h-10 rounded-xl bg-brand-50 flex items-center justify-center mb-5">
                        <span class="text-lg font-bold text-brand-600">2</span>
                    </div>
                    <h3 class="text-base font-bold text-surface-900 mb-2">{{ __('app.landing.configure_your_space') }}</h3>
                    <p class="text-sm text-surface-500 leading-relaxed">Define workspaces, rooms, pricing, and speed profiles. Everything is set up visually in your dashboard.</p>
                </div>
                <div class="flow-step">
                    <div class="w-10 h-10 rounded-xl bg-green-50 flex items-center justify-center mb-5">
                        <span class="text-lg font-bold text-green-600">3</span>
                    </div>
                    <h3 class="text-base font-bold text-surface-900 mb-2">{{ __('app.landing.start_managing') }}</h3>
                    <p class="text-sm text-surface-500 leading-relaxed">Take bookings, manage users, monitor live sessions, and grow your coworking business — all from one dashboard.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ═══════════════════════════════════════════
         DASHBOARD PREVIEW
         ═══════════════════════════════════════════ -->
    <section class="py-20 sm:py-28 px-5 sm:px-8">
        <div class="max-w-6xl mx-auto">
            <div class="grid lg:grid-cols-2 gap-12 lg:gap-20 items-center">
                <div class="fade-in">
                    <span class="inline-block text-xs font-semibold text-brand-600 bg-brand-50 px-3 py-1 rounded-full mb-4">Dashboard</span>
                    <h2 class="text-3xl sm:text-4xl font-bold tracking-tight text-surface-900 mb-4">{{ __('app.landing.everything_at_a_glance') }}</h2>
                    <p class="text-surface-500 text-base sm:text-lg mb-8 leading-relaxed">Your command center for the entire coworking operation. Real-time data, quick actions, full visibility.</p>

                    <div class="space-y-5">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-xl bg-brand-50 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-surface-900">Live Usage Stats</p>
                                <p class="text-sm text-surface-400 mt-0.5">Active users, sessions, and bandwidth — all in real-time.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-xl bg-brand-50 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-surface-900">Today's Bookings</p>
                                <p class="text-sm text-surface-400 mt-0.5">Your daily schedule at a glance with status tracking.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-xl bg-green-50 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-surface-900">Monthly Revenue</p>
                                <p class="text-sm text-surface-400 mt-0.5">Track completed bookings with clear financial overview.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="fade-in" style="transition-delay:0.1s">
                    <div class="bg-white rounded-2xl border border-surface-200 p-5 sm:p-7 shadow-lg shadow-surface-200/50">
                        <div class="flex items-center justify-between mb-5 pb-4 border-b border-surface-100">
                            <div>
                                <p class="text-xs text-surface-400 font-medium">Dashboard</p>
                                <p class="text-base font-bold text-surface-900 mt-0.5">Good morning, SpaceHub</p>
                            </div>
                            <span class="text-[0.65rem] font-medium text-green-600 bg-green-50 px-3 py-1.5 rounded-full">All Systems Go</span>
                        </div>

                        <div class="grid grid-cols-2 gap-3 mb-5">
                            <div class="bg-surface-50 rounded-xl p-4">
                                <p class="text-[0.65rem] text-surface-400">Active Users</p>
                                <p class="text-xl font-bold text-surface-900 mt-0.5">128</p>
                            </div>
                            <div class="bg-surface-50 rounded-xl p-4">
                                <p class="text-[0.65rem] text-surface-400">Online Now</p>
                                <p class="text-xl font-bold text-brand-600 mt-0.5">24</p>
                            </div>
                            <div class="bg-surface-50 rounded-xl p-4">
                                <p class="text-[0.65rem] text-surface-400">Today's Bookings</p>
                                <p class="text-xl font-bold text-amber-600 mt-0.5">8</p>
                            </div>
                            <div class="bg-surface-50 rounded-xl p-4">
                                <p class="text-[0.65rem] text-surface-400">Month Revenue</p>
                                <p class="text-xl font-bold text-green-600 mt-0.5">ج.م 3,240</p>
                            </div>
                        </div>

                        <div class="flex gap-3">
                            <button onclick="openDemoModal()" class="flex-1 bg-brand-600 hover:bg-brand-700 text-white text-center text-sm font-semibold py-3 rounded-xl transition">New Booking</button>
                            <button onclick="openDemoModal()" class="flex-1 bg-surface-100 hover:bg-surface-200 text-surface-700 text-center text-sm font-semibold py-3 rounded-xl transition">View Reports</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ═══════════════════════════════════════════
         WHY LINKSPACE
         ═══════════════════════════════════════════ -->
    <section class="relative overflow-hidden py-20 sm:py-28 px-5 sm:px-8 bg-surface-900/90 backdrop-blur-sm text-white">
        <div class="absolute -top-24 left-1/2 -translate-x-1/2 w-[640px] h-[420px] bg-brand-600/20 blur-[130px] rounded-full pointer-events-none"></div>
        <div class="absolute bottom-0 right-0 w-[420px] h-[320px] bg-brand-600/10 blur-[120px] rounded-full pointer-events-none"></div>
        <div class="relative max-w-6xl mx-auto">
            <div class="text-center mb-16 fade-in">
                <span class="inline-block text-xs font-semibold text-brand-300 bg-white/5 px-3 py-1 rounded-full mb-4">{{ __('app.landing.why_linkspace') }}</span>
                <h2 class="text-3xl sm:text-4xl font-bold tracking-tight">{{ __('app.landing.built_for_spaces') }}</h2>
            </div>

            <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5 fade-in">
                <div class="bg-white/5 backdrop-blur-sm rounded-2xl p-7 border border-white/5 hover:bg-white/[0.07] transition">
                    <div class="w-11 h-11 rounded-xl bg-brand-500/10 flex items-center justify-center mb-4">
                        <svg class="w-5 h-5 text-brand-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    </div>
                    <h3 class="text-base font-bold mb-2">{{ __('app.landing.no_technical_skills') }}</h3>
                    <p class="text-sm text-surface-400 leading-relaxed">Forget CLI commands. Link Space Panel connects via API and gives you a beautiful interface to manage everything.</p>
                </div>
                <div class="bg-white/5 backdrop-blur-sm rounded-2xl p-7 border border-white/5 hover:bg-white/[0.07] transition">
                    <div class="w-11 h-11 rounded-xl bg-brand-500/10 flex items-center justify-center mb-4">
                        <svg class="w-5 h-5 text-brand-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </div>
                    <h3 class="text-base font-bold mb-2">{{ __('app.landing.real_time_everything') }}</h3>
                    <p class="text-sm text-surface-400 leading-relaxed">User changes sync instantly. Live session data, booking updates — no delays, no manual steps.</p>
                </div>
                <div class="bg-white/5 backdrop-blur-sm rounded-2xl p-7 border border-white/5 hover:bg-white/[0.07] transition sm:col-span-2 lg:col-span-1">
                    <div class="w-11 h-11 rounded-xl bg-brand-500/10 flex items-center justify-center mb-4">
                        <svg class="w-5 h-5 text-brand-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    </div>
                    <h3 class="text-base font-bold mb-2">{{ __('app.landing.secure_multi_tenant') }}</h3>
                    <p class="text-sm text-surface-400 leading-relaxed">Every space is isolated. Your data, customers, and configurations stay private and completely separate.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- ═══════════════════════════════════════════
         PRICING
         ═══════════════════════════════════════════ -->
    <section id="pricing" class="py-20 sm:py-28 px-5 sm:px-8">
        <div class="max-w-6xl mx-auto">
            <div class="text-center mb-16 fade-in">
                <span class="inline-block text-xs font-semibold text-brand-600 bg-brand-50 px-3 py-1 rounded-full mb-4">{{ __('app.landing.pricing') }}</span>
                <h2 class="text-3xl sm:text-4xl font-bold tracking-tight text-surface-900">{{ __('app.landing.pricing_title') }}</h2>
                <p class="mt-3 text-surface-500 text-base sm:text-lg max-w-2xl mx-auto">{{ __('app.landing.pricing_subtitle') }}</p>
            </div>

            @php
                $plans = [
                    [
                        'name'        => __('app.landing.plan_starter'),
                        'users'       => __('app.landing.plan_starter_users'),
                        'price'       => __('app.landing.plan_starter_price'),
                        'unit'        => __('app.landing.plan_forever'),
                        'price_class' => 'text-green-600',
                        'featured'    => false,
                        'cta'         => __('app.landing.get_started'),
                        'href'        => '/register',
                        'features'    => [__('app.landing.plan_starter_f1'), __('app.landing.plan_starter_f2'), __('app.landing.plan_starter_f3')],
                    ],
                    [
                        'name'        => __('app.landing.plan_growth'),
                        'users'       => __('app.landing.plan_growth_users'),
                        'price'       => __('app.landing.plan_growth_price'),
                        'unit'        => __('app.landing.per_month'),
                        'price_class' => 'text-brand-400',
                        'featured'    => true,
                        'cta'         => __('app.landing.get_started'),
                        'features'    => [__('app.landing.plan_growth_f1'), __('app.landing.plan_growth_f2'), __('app.landing.plan_growth_f3'), __('app.landing.plan_growth_f4')],
                    ],
                    [
                        'name'        => __('app.landing.plan_scale'),
                        'users'       => __('app.landing.plan_scale_users'),
                        'price'       => __('app.landing.plan_scale_price'),
                        'unit'        => __('app.landing.per_month'),
                        'price_class' => 'text-brand-600',
                        'featured'    => false,
                        'cta'         => __('app.landing.get_started'),
                        'features'    => [__('app.landing.plan_scale_f1'), __('app.landing.plan_scale_f2'), __('app.landing.plan_scale_f3'), __('app.landing.plan_scale_f4')],
                    ],
                    [
                        'name'        => __('app.landing.plan_enterprise'),
                        'users'       => __('app.landing.plan_enterprise_users'),
                        'price'       => __('app.landing.plan_enterprise_price'),
                        'unit'        => __('app.landing.plan_lets_talk'),
                        'price_class' => 'text-brand-600',
                        'featured'    => false,
                        'cta'         => __('app.landing.contact_us'),
                        'features'    => [__('app.landing.plan_enterprise_f1'), __('app.landing.plan_enterprise_f2'), __('app.landing.plan_enterprise_f3'), __('app.landing.plan_enterprise_f4')],
                    ],
                ];
            @endphp
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-6 max-w-6xl mx-auto fade-in items-stretch">
                @foreach($plans as $plan)
                    @php $feat = $plan['featured']; @endphp
                    <div class="pricing-card flex flex-col {{ $feat ? 'featured relative !bg-brand-900 !border-transparent' : '' }}">
                        @if($feat)
                            <span class="absolute -top-3 {{ $isRtl ? 'right-1/2 translate-x-1/2' : 'left-1/2 -translate-x-1/2' }} text-[0.62rem] font-bold text-brand-900 bg-brand-400 px-3.5 py-1 rounded-full shadow-md shadow-brand-900/20 whitespace-nowrap uppercase tracking-wide">{{ __('app.landing.most_popular') }}</span>
                        @endif
                        <h3 class="text-base font-bold mb-0.5 {{ $feat ? 'text-white' : 'text-surface-900' }}">{{ $plan['name'] }}</h3>
                        <p class="text-sm mb-5 {{ $feat ? 'text-white/60' : 'text-surface-400' }}">{{ $plan['users'] }}</p>
                        <p class="text-4xl font-black leading-none mb-1 {{ $plan['price_class'] }}">{{ $plan['price'] }}</p>
                        <p class="text-sm mb-5 {{ $feat ? 'text-white/60' : 'text-surface-400' }}">{{ $plan['unit'] }}</p>
                        <ul class="space-y-3 mb-8 flex-1 pt-5 border-t {{ $feat ? 'border-white/10' : 'border-surface-100' }}">
                            @foreach($plan['features'] as $feature)
                                <li class="flex items-center gap-2.5 text-sm {{ $feat ? 'text-white font-medium' : 'text-surface-600' }}">
                                    <svg class="w-4 h-4 shrink-0 {{ $feat ? 'text-green-400' : 'text-green-500' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                    {{ $feature }}
                                </li>
                            @endforeach
                        </ul>
                        @if(!empty($plan['href']))
                            <a href="{{ $plan['href'] }}" class="w-full font-semibold {{ $feat ? 'inline-flex items-center justify-center bg-white text-brand-900 hover:bg-brand-50 rounded-xl px-4 py-3 transition' : 'cta-btn cta-secondary' }}">{{ $plan['cta'] }}</a>
                        @else
                            <button onclick="openDemoModal()" class="w-full font-semibold {{ $feat ? 'inline-flex items-center justify-center bg-white text-brand-900 hover:bg-brand-50 rounded-xl px-4 py-3 transition' : 'cta-btn cta-secondary' }}">{{ $plan['cta'] }}</button>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <!-- ═══════════════════════════════════════════
         FINAL CTA
         ═══════════════════════════════════════════ -->
    <section class="py-20 sm:py-28 px-5 sm:px-8">
        <div class="max-w-5xl mx-auto fade-in">
            <div class="relative overflow-hidden rounded-3xl px-6 py-16 sm:py-20 text-center shadow-2xl shadow-brand-500/20"
                 style="background: radial-gradient(120% 120% at 0% 0%, #3f68af 0%, transparent 45%), radial-gradient(120% 120% at 100% 100%, #163c85 0%, transparent 48%), linear-gradient(135deg, #163c85 0%, #3f68af 55%, #6f96d1 100%);">
                <div class="absolute inset-0 opacity-[0.14]" style="background-image:url('/images/hero-bg.svg'); background-size:cover; mix-blend-mode:soft-light;"></div>
                <div class="absolute -top-16 -left-16 w-64 h-64 rounded-full bg-white/10 blur-3xl pointer-events-none"></div>
                <div class="absolute -bottom-20 -right-10 w-72 h-72 rounded-full bg-brand-300/20 blur-3xl pointer-events-none"></div>

                <div class="relative z-10">
                    <h2 class="text-3xl sm:text-4xl md:text-5xl font-bold tracking-tight text-white mb-4 max-w-2xl mx-auto" style="text-wrap:balance">{{ __('app.landing.ready_to_transform') }}</h2>
                    <p class="text-brand-100 text-base sm:text-lg mb-9 max-w-xl mx-auto leading-relaxed">{{ __('app.landing.cta_subtitle') }}</p>
                    <div class="flex flex-col sm:flex-row gap-3 justify-center">
                        <a href="/register" class="group inline-flex items-center justify-center gap-2 bg-white text-brand-700 font-semibold text-sm px-7 py-3.5 rounded-xl hover:bg-brand-50 transition shadow-lg shadow-brand-900/30">
                            {{ __('app.landing.start_free_trial') }}
                            <svg class="w-4 h-4 transition group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                        </a>
                        <button onclick="openDemoModal()" class="inline-flex items-center justify-center gap-2 border border-white/40 text-white font-semibold text-sm px-7 py-3.5 rounded-xl hover:bg-white/10 transition">
                            {{ __('app.landing.request_demo') }}
                        </button>
                    </div>
                    <p class="text-xs text-brand-200 mt-5">{{ __('app.landing.no_credit_card_needed') }} · 14-day free trial · Cancel anytime</p>
                </div>
            </div>
        </div>
    </section>

    </main>

    <!-- ═══════════════════════════════════════════
         FOOTER
         ═══════════════════════════════════════════ -->
    <footer class="border-t border-surface-200 py-10 px-5 sm:px-8">
        <div class="max-w-6xl mx-auto">
            <div class="flex flex-col sm:flex-row items-center justify-between gap-5">
                <div class="flex items-center gap-3">
                    <img src="/logo.webp"
                         alt="Link Space Panel"
                         class="h-10 w-auto">
                </div>
                <p class="text-sm text-surface-400 text-center">{{ __('app.landing.footer_text') }}</p>
                <div class="flex items-center gap-5 text-sm text-surface-400">
                    <a href="/login" class="hover:text-surface-900 transition font-medium">{{ __('app.landing.sign_in') }}</a>
                    <a href="/register" class="hover:text-surface-900 transition font-medium">{{ __('app.auth.register') }}</a>
                    <a href="mailto:bahgatayman10@gmail.com" class="hover:text-surface-900 transition font-medium">Contact</a>
                </div>
            </div>
            <div class="mt-6 pt-5 border-t border-surface-100 text-center">
                <p class="text-xs text-surface-400">&copy; {{ date('Y') }} Link Space Panel. {{ __('app.landing.all_rights_reserved') }}</p>
            </div>
        </div>
    </footer>

    <!-- ═══════════════════════════════════════════
         Flash Message
         ═══════════════════════════════════════════ -->
    @if (session('success'))
        <div id="flash-message" class="fixed top-24 left-1/2 -translate-x-1/2 z-50 max-w-md w-full mx-4">
            <div class="bg-white rounded-2xl shadow-2xl border border-green-100 px-6 py-4 flex items-center gap-3">
                <svg class="w-5 h-5 text-green-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <p class="text-sm text-surface-800 font-medium">{{ session('success') }}</p>
                <button onclick="this.parentElement.parentElement.remove()" class="ml-auto text-surface-400 hover:text-surface-600 transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <script>setTimeout(() => { const el = document.getElementById('flash-message'); if (el) el.remove(); }, 6000);</script>
    @endif

    <!-- ═══════════════════════════════════════════
         Demo Modal
         ═══════════════════════════════════════════ -->
    <div id="demo-modal" class="fixed inset-0 z-50 hidden items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="demo-modal-title">
        <div onclick="closeDemoModal()" class="absolute inset-0 modal-overlay"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl max-w-lg w-full p-6 sm:p-8 max-h-[90vh] overflow-y-auto">
            <button type="button" onclick="closeDemoModal()" class="absolute top-4 right-4 text-surface-400 hover:text-surface-600 transition" aria-label="{{ __('app.common.close') }}">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>

            <div class="text-center mb-6">
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-brand-400 to-brand-600 flex items-center justify-center mx-auto mb-4 shadow-lg shadow-brand-500/20">
                    <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                </div>
                <h3 id="demo-modal-title" class="text-xl font-bold text-surface-900">{{ __('app.landing.demo_modal_title') }}</h3>
                <p class="text-sm text-surface-500 mt-1">{{ __('app.landing.demo_modal_subtitle') }}</p>
            </div>

            <form method="POST" action="/demo-request" class="space-y-4" onsubmit="return handleDemoSubmit(this)">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-surface-700 mb-1.5">{{ __('app.landing.your_full_name') }} *</label>
                    <input type="text" name="name" required placeholder="{{ __('app.placeholder.full_name') }}"
                           class="w-full border border-surface-200 rounded-xl px-4 py-3 text-sm text-surface-900 placeholder-surface-400 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/10 transition">
                </div>
                <div>
                    <label class="block text-sm font-medium text-surface-700 mb-1.5">{{ __('app.landing.your_email') }} *</label>
                    <input type="email" name="email" required placeholder="{{ __('app.placeholder.email') }}"
                           class="w-full border border-surface-200 rounded-xl px-4 py-3 text-sm text-surface-900 placeholder-surface-400 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/10 transition">
                </div>
                <div>
                    <label class="block text-sm font-medium text-surface-700 mb-1.5">{{ __('app.landing.your_phone') }}</label>
                    <input type="text" name="phone" placeholder="{{ __('app.placeholder.phone') }}"
                           class="w-full border border-surface-200 rounded-xl px-4 py-3 text-sm text-surface-900 placeholder-surface-400 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/10 transition">
                </div>
                <div>
                    <label class="block text-sm font-medium text-surface-700 mb-1.5">{{ __('app.landing.company_space_name') }}</label>
                    <input type="text" name="company" placeholder="{{ __('app.placeholder.business_name') }}"
                           class="w-full border border-surface-200 rounded-xl px-4 py-3 text-sm text-surface-900 placeholder-surface-400 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/10 transition">
                </div>
                <div>
                    <label class="block text-sm font-medium text-surface-700 mb-1.5">{{ __('app.landing.message') }}</label>
                    <textarea name="message" rows="3" placeholder="{{ __('app.placeholder.space_description') }}"
                              class="w-full border border-surface-200 rounded-xl px-4 py-3 text-sm text-surface-900 placeholder-surface-400 focus:outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-500/10 transition"></textarea>
                </div>
                <button type="submit" class="w-full cta-btn cta-primary !py-3.5">
                    {{ __('app.landing.send_request') }}
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </button>
            </form>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════
         JavaScript
         ═══════════════════════════════════════════ -->
    <script>
        // Tab switching
        function switchTab(index) {
            document.querySelectorAll('.screen-tab').forEach((tab, i) => {
                tab.classList.toggle('active', i === index);
            });
            document.querySelectorAll('.screen-panel').forEach((panel, i) => {
                panel.classList.toggle('active', i === index);
            });
        }

        // Modal
        function openDemoModal() {
            const modal = document.getElementById('demo-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
            const firstField = modal.querySelector('input[name="name"]');
            if (firstField) setTimeout(() => firstField.focus(), 50);
        }
        function closeDemoModal() {
            document.getElementById('demo-modal').classList.add('hidden');
            document.getElementById('demo-modal').classList.remove('flex');
            document.body.style.overflow = '';
        }
        function handleDemoSubmit(form) {
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.7';
            }
            return true;
        }
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDemoModal(); });
        document.addEventListener('click', e => { if (e.target.id === 'demo-modal') closeDemoModal(); });

        // Mobile menu
        const mobileMenu       = document.getElementById('mobile-menu');
        const mobileMenuBtn    = document.getElementById('mobile-menu-btn');
        const mobileIconOpen   = document.getElementById('mobile-menu-icon-open');
        const mobileIconClose  = document.getElementById('mobile-menu-icon-close');

        function toggleMobile() {
            const willOpen = mobileMenu.classList.contains('hidden');
            mobileMenu.classList.toggle('hidden');
            mobileIconOpen.classList.toggle('hidden', willOpen);
            mobileIconClose.classList.toggle('hidden', !willOpen);
            mobileMenuBtn.setAttribute('aria-expanded', String(willOpen));
            document.body.style.overflow = willOpen ? 'hidden' : '';
        }

        // Tapping any link inside the mobile menu should close it — a plain
        // anchor click otherwise leaves the menu open, covering the section
        // it just scrolled the page to.
        mobileMenu.addEventListener('click', e => { if (e.target.tagName === 'A') toggleMobile(); });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && !mobileMenu.classList.contains('hidden')) toggleMobile();
        });

        // Scroll animations
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) entry.target.classList.add('visible');
            });
        }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });
        document.querySelectorAll('.fade-in').forEach(el => observer.observe(el));

        // Nav elevation on scroll
        const siteNav = document.getElementById('site-nav');
        if (siteNav) {
            const onScroll = () => siteNav.classList.toggle('nav-scrolled', window.scrollY > 8);
            onScroll();
            window.addEventListener('scroll', onScroll, { passive: true });
        }

        // Scroll-spy: highlight whichever section's nav link matches what's
        // currently in view, so a visitor scrolling a long one-page site
        // always has a sense of where they are.
        const navLinks = document.querySelectorAll('a[data-nav-link]');
        const spySections = ['product', 'how-it-works', 'pricing']
            .map(id => document.getElementById(id))
            .filter(Boolean);
        if (navLinks.length && spySections.length) {
            const spyObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (!entry.isIntersecting) return;
                    navLinks.forEach(link => {
                        link.classList.toggle('nav-active', link.getAttribute('href') === `#${entry.target.id}`);
                    });
                });
            }, { rootMargin: '-45% 0px -50% 0px' });
            spySections.forEach(section => spyObserver.observe(section));
        }
    </script>
</body>
</html>
