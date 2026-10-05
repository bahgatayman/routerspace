<!DOCTYPE html>
@php $locale = app()->getLocale(); $isRtl = $locale === 'ar'; @endphp
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', __('app.auth.login')) · Link Space Panel</title>
    @include('partials.favicon')
    <link rel="preload" as="image" href="/images/auth-bg.svg">
    @include('partials.theme')
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        @if($isRtl)
        @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap');
        @endif
        body { font-family: 'Inter', system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
        @if($isRtl) body { font-family: 'Cairo', 'Inter', system-ui, sans-serif; } @endif

        .field {
            width: 100%; border: 1px solid #e2e4ee; border-radius: 0.65rem;
            padding: 0.65rem 0.85rem; font-size: 0.9rem; color: #1c1917; background: #fff;
            transition: border-color .15s, box-shadow .15s;
        }
        .field::placeholder { color: #b3b0ab; }
        .field:focus { outline: none; border-color: #163c85; box-shadow: 0 0 0 3px rgba(22,60,133,.14); }
        .lbl { display: block; font-size: 0.8rem; font-weight: 600; color: #44403c; margin-bottom: 0.4rem; }
        .group-lbl { font-size: 0.68rem; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; color: #a8a29e; }

        .btn-primary {
            width: 100%; display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
            padding: 0.72rem 1rem; border-radius: 0.7rem; font-weight: 600; font-size: 0.9rem;
            color: #fff; background: linear-gradient(135deg, #163c85 0%, #3f68af 100%);
            box-shadow: 0 10px 22px -10px rgba(22,60,133,.55); border: none; cursor: pointer;
            transition: box-shadow .2s, transform .2s;
        }
        .btn-primary:hover { box-shadow: 0 14px 28px -10px rgba(22,60,133,.65); transform: translateY(-1px); }
        .btn-primary svg { transition: transform .2s; }
        .btn-primary:hover svg { transform: translateX(3px); }

        /* Full-bleed branded scene behind the centered card. */
        .auth-scene {
            position: fixed; inset: 0; z-index: -1;
            background: #0d244e url('/images/auth-bg.svg') center / cover no-repeat;
        }
        .auth-scene::after {
            content: ''; position: absolute; inset: 0;
            background: radial-gradient(70% 60% at 50% 45%, rgba(8,24,51,.30) 0%, rgba(8,24,51,.62) 100%);
        }

        .auth-card {
            background: rgba(255,255,255,.97);
            border: 1px solid rgba(255,255,255,.6);
            border-radius: 1.25rem;
            box-shadow: 0 30px 70px -25px rgba(4,12,28,.65), 0 0 0 1px rgba(8,24,51,.05);
            backdrop-filter: blur(6px);
        }

        /* Lock the shell to the viewport: the page itself never scrolls.
           dvh so mobile URL bars don't add phantom overflow on top of 100vh. */
        html, body { height: 100%; }
        .auth-shell {
            height: 100vh; height: 100dvh;
            display: grid; grid-template-rows: auto 1fr auto;
            overflow: hidden;
        }
        /* Only this row scrolls, and only when the card outgrows it (long forms
           on short screens). `margin:auto` centers instead of `align-items`,
           which would make the overflowing top unreachable. */
        .auth-main { display: flex; overflow-y: auto; overscroll-behavior: contain; }
        .auth-main > * { margin: auto; }

        /* Reclaim height on short viewports before resorting to a scrollbar. */
        @media (max-height: 700px) {
            .auth-bar { padding-top: .6rem; padding-bottom: .6rem; }
            .auth-card { padding-top: 1.75rem; padding-bottom: 1.75rem; }
        }

        .auth-fade { animation: authIn .6s cubic-bezier(.16,1,.3,1) both; }
        @keyframes authIn { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
        @media (prefers-reduced-motion: reduce) { .auth-fade { animation: none; } }
    </style>
</head>
<body class="text-surface-900">
    <div class="auth-scene"></div>

    <div class="auth-shell">

        <!-- Top bar: brand + language -->
        <header class="auth-bar flex items-center justify-between gap-4 px-6 py-4 sm:px-10">
            <img src="/logo.webp" alt="Link Space Panel" class="h-8 w-auto brightness-0 invert opacity-95">
            <form method="POST" action="{{ route('language.switch', $isRtl ? 'en' : 'ar') }}" class="flex items-center gap-1.5">
                @csrf
                <button type="submit" class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 focus:outline-none {{ $isRtl ? 'bg-brand-400' : 'bg-white/25' }}" role="switch" aria-checked="{{ $isRtl ? 'true' : 'false' }}">
                    <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-sm transition duration-200 {{ $isRtl ? 'translate-x-[18px]' : 'translate-x-[3px]' }}"></span>
                </button>
                <span class="text-xs font-medium text-white/80">{{ $isRtl ? 'AR' : 'EN' }}</span>
            </form>
        </header>

        <!-- Centered card -->
        <main class="auth-main px-4 py-6 sm:px-6">
            <div class="w-full @yield('formWidth', 'max-w-md') auth-fade">
                <div class="auth-card px-6 py-8 sm:px-9 sm:py-10">
                    @hasSection('heading')
                        <div class="mb-7 text-center">
                            <h1 class="text-2xl sm:text-[1.7rem] font-bold tracking-tight text-surface-900">@yield('heading')</h1>
                            <p class="text-sm text-surface-500 mt-1.5">@yield('subheading')</p>
                        </div>
                    @endif
                    @yield('content')
                </div>
            </div>
        </main>

        <footer class="auth-bar px-6 py-4 text-center text-xs text-white/50">
            &copy; {{ date('Y') }} Link Space Panel
        </footer>
    </div>

    <script>
        document.querySelectorAll('.pw-toggle').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = btn.parentElement.querySelector('input');
                input.type = input.type === 'password' ? 'text' : 'password';
                btn.querySelectorAll('svg').forEach(s => s.classList.toggle('hidden'));
            });
        });
    </script>
</body>
</html>
