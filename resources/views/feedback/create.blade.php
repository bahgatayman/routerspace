<!DOCTYPE html>
@php
    use App\Models\Feedback;
    $locale = app()->getLocale();
    $isRtl = $locale === 'ar';
    $f = fn (string $k, array $r = []) => __('app.feedback.'.$k, $r);
    $oldRating = (int) old('rating');
    $oldCategory = old('category');
    $messageMax = Feedback::MESSAGE_MAX;
    $messageMin = Feedback::MESSAGE_MIN;
    $categoryIcons = [
        'general' => '<path d="M4 5.5h16a1 1 0 0 1 1 1V16a1 1 0 0 1-1 1h-9l-4.5 3.5V17H4a1 1 0 0 1-1-1V6.5a1 1 0 0 1 1-1z"/>',
        'feature' => '<path d="M9.5 18h5M10 21h4M12 3a6 6 0 0 0-3.6 10.8c.7.5 1.1 1.3 1.1 2.2h5c0-.9.4-1.7 1.1-2.2A6 6 0 0 0 12 3z"/>',
        'bug' => '<rect x="7" y="7" width="10" height="13" rx="5"/><path d="M12 11v9M7 13H3.5M20.5 13H17M8 8 5.5 5.5M16 8l2.5-2.5M7.5 17.5 4.5 20M16.5 17.5l3 2.5M9.5 7a2.5 2.5 0 0 1 5 0"/>',
        'complaint' => '<circle cx="12" cy="12" r="9"/><path d="M8.5 16.5c1-1.2 2.2-1.8 3.5-1.8s2.5.6 3.5 1.8M9 9.5h.01M15 9.5h.01"/>',
        'other' => '<circle cx="12" cy="12" r="9"/><path d="M8 12h.01M12 12h.01M16 12h.01" stroke-width="2.6"/>',
    ];
    $starPath = 'm12 3.2 2.7 5.5 6 .9-4.35 4.25 1.03 6-5.38-2.83-5.38 2.83 1.03-6L3.3 9.6l6-.9z';
    $jsText = [
        'labels' => __('app.feedback.rating.labels'),
        'pick' => $f('rating.pick'),
        'sending' => $f('sending'),
        'errRating' => $f('errors.rating'),
        'errCategory' => $f('errors.category'),
        'errMessage' => $f('errors.message', ['min' => $messageMin]),
        'errEmail' => $f('errors.email'),
        'errTitle' => $f('errors.title'),
    ];
@endphp
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" data-theme="light" data-theme-lock="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>{{ $f('page_title') }} · Link Space Panel</title>
    @include('partials.favicon')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400..700&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/panel.css?v={{ @filemtime(public_path('css/panel.css')) }}">
    <style>
        /* Public feedback page — composes panel.css tokens; only page layout + rating/category controls live here. */
        html, body { min-height: 100%; }
        .fb *, .fb *::before, .fb *::after { box-sizing: border-box; }
        .fb [hidden] { display: none !important; }
        body.fb { margin: 0; font-family: var(--ls-font); color: var(--color-text); -webkit-font-smoothing: antialiased;
            background:
                radial-gradient(60rem 30rem at 85% -10%, rgba(67,110,201,.16), transparent 60%),
                radial-gradient(50rem 28rem at -10% 10%, rgba(46,79,143,.10), transparent 60%),
                linear-gradient(180deg, #f6f8fc 0%, #f4f4f2 100%);
            background-attachment: fixed; }
        .fb-bar { max-width: 760px; margin: 0 auto; padding: 18px 20px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .fb-bar img { height: 30px; width: auto; display: block; }
        .fb-lang { display: inline-flex; padding: 3px; gap: 2px; border-radius: 999px; background: rgba(255,255,255,.8); border: 1px solid var(--color-border); box-shadow: 0 1px 2px rgba(0,0,0,.04); }
        .fb-lang form { display: contents; }
        .fb-lang button, .fb-lang span { font: inherit; font-size: 13px; font-weight: 600; padding: 6px 14px; border-radius: 999px; border: 0; background: none; color: var(--color-text-secondary); cursor: pointer; line-height: 1.2; }
        .fb-lang button:hover { color: var(--color-primary); background: var(--color-accent-soft); }
        .fb-lang span[aria-current] { background: var(--color-primary); color: #fff; cursor: default; }
        .fb-lang button:focus-visible { outline: 2px solid var(--color-accent); outline-offset: 2px; }

        .fb-wrap { max-width: 760px; margin: 0 auto; padding: 8px 20px 56px; }
        .fb-card { background: var(--color-surface); border: 1px solid var(--color-border); border-radius: 22px; box-shadow: 0 30px 60px -36px rgba(22,48,96,.35), 0 2px 6px rgba(28,25,23,.04); overflow: hidden; }
        /* Hero: the landing page's coworking photo under a Link Space navy overlay.
           One layered background (overlay gradient + photo): no extra markup. The
           overlay is darkest on the text side (inline-start) and mirrors in RTL. */
        .fb-hero { --fb-shade: linear-gradient(90deg, rgba(10,30,68,.93) 0%, rgba(16,46,102,.84) 45%, rgba(22,60,133,.58) 100%);
            position: relative; padding: 40px 36px 30px; color: #fff; border-bottom: 1px solid var(--color-border-subtle); overflow: hidden;
            background: var(--fb-shade), #123068 url('/images/workspace-bg.jpg') center / cover no-repeat; }
        [dir="rtl"] .fb-hero { --fb-shade: linear-gradient(270deg, rgba(10,30,68,.93) 0%, rgba(16,46,102,.84) 45%, rgba(22,60,133,.58) 100%); }
        .fb-hero::after { content: ''; position: absolute; inset: auto 0 0 0; height: 40%; background: linear-gradient(180deg, transparent, rgba(10,30,68,.35)); pointer-events: none; }
        .fb-hero > * { position: relative; z-index: 1; }
        .fb-eyebrow { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: #fff; background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.28); padding: 4px 10px; border-radius: 999px; -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px); }
        .fb-eyebrow svg { width: 14px; height: 14px; color: #ffd166; }
        .fb-title { margin: 14px 0 8px; font-size: clamp(24px, 4vw, 32px); line-height: 1.25; font-weight: 700; letter-spacing: -.015em; color: #fff; text-shadow: 0 1px 12px rgba(4,14,34,.35); }
        .fb-sub { margin: 0; max-width: 560px; font-size: 15px; line-height: 1.7; color: rgba(255,255,255,.9); }
        .fb-meta { margin-top: 16px; display: flex; flex-wrap: wrap; gap: 8px 16px; font-size: 12.5px; color: rgba(255,255,255,.85); }
        .fb-meta span { display: inline-flex; align-items: center; gap: 6px; }
        .fb-meta svg { width: 14px; height: 14px; }
        .fb-signed { margin-top: 14px; font-size: 13px; color: #fff; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.24); border-radius: 12px; padding: 9px 12px; }

        .fb-form { padding: 28px 36px 32px; display: grid; gap: 28px; }
        .fb-q { border: 0; margin: 0; padding: 0; min-width: 0; display: grid; gap: 12px; }
        .fb-q > legend, .fb-qlabel { padding: 0; font-size: 15px; font-weight: 600; color: var(--color-text); display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
        .fb-tag { font-size: 11.5px; font-weight: 500; color: var(--color-text-muted); }
        .fb-tag.is-req { color: var(--color-danger); }
        .fb-err { display: flex; align-items: flex-start; gap: 6px; font-size: 13px; color: var(--color-danger); margin: 0; }
        .fb-err svg { width: 15px; height: 15px; flex: none; margin-top: 2px; }
        .fb-err[hidden] { display: none; }

        /* Stars: real radio buttons (keyboard arrows + screen readers), painted by JS on hover. */
        .fb-stars { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .fb-star { position: relative; cursor: pointer; border-radius: 12px; padding: 4px; line-height: 0; transition: transform .15s ease; }
        .fb-star input { position: absolute; inset: 0; opacity: 0; margin: 0; cursor: pointer; }
        .fb-star svg { width: 40px; height: 40px; fill: #e7e5e4; stroke: #d6d3d1; stroke-width: 1; transition: fill .15s ease, stroke .15s ease; }
        .fb-star.is-on svg { fill: #f5b301; stroke: #e0a100; }
        .fb-star.is-hover svg { fill: #fbcf4f; stroke: #e9b52a; }
        .fb-star:hover { transform: scale(1.08); }
        .fb-star:has(input:focus-visible) { outline: 2px solid var(--color-accent); outline-offset: 1px; }
        .fb-rating-label { min-height: 24px; font-size: 14px; font-weight: 600; color: var(--color-text-muted); margin-inline-start: 8px; }
        .fb-rating-label.is-set { color: var(--color-text); }

        .fb-cats { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; }
        @media (max-width: 760px) { .fb-cats { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        .fb-cat { position: relative; display: flex; flex-direction: column; gap: 6px; padding: 14px; border-radius: 14px; border: 1.5px solid var(--color-border); background: var(--color-surface); cursor: pointer; transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease; min-width: 0; }
        .fb-cat:hover { border-color: var(--color-border-strong); background: var(--color-surface-subtle); }
        .fb-cat input { position: absolute; opacity: 0; inset: 0; margin: 0; cursor: pointer; }
        .fb-cat-icon { width: 34px; height: 34px; border-radius: 10px; display: grid; place-items: center; background: var(--color-surface-subtle); color: var(--color-text-secondary); transition: inherit; }
        .fb-cat-icon svg { width: 18px; height: 18px; }
        .fb-cat b { font-size: 14px; font-weight: 600; color: var(--color-text); }
        .fb-cat small { font-size: 12px; color: var(--color-text-muted); line-height: 1.35; }
        .fb-cat:has(input:checked) { border-color: var(--color-primary); background: #f3f6fc; box-shadow: 0 0 0 3px rgba(46,79,143,.10); }
        .fb-cat:has(input:checked) .fb-cat-icon { background: var(--color-primary); color: #fff; }
        .fb-cat:has(input:focus-visible) { outline: 2px solid var(--color-accent); outline-offset: 2px; }
        .fb-cat-check { position: absolute; top: 10px; inset-inline-end: 10px; width: 18px; height: 18px; border-radius: 50%; border: 1.5px solid var(--color-border-strong); display: grid; place-items: center; }
        .fb-cat:has(input:checked) .fb-cat-check { background: var(--color-primary); border-color: var(--color-primary); }
        .fb-cat-check svg { width: 11px; height: 11px; color: #fff; opacity: 0; }
        .fb-cat:has(input:checked) .fb-cat-check svg { opacity: 1; }
        .fb-q.is-invalid .fb-cat { border-color: var(--color-danger); }

        .fb-details { display: grid; gap: 8px; min-width: 0; padding: 14px; border-radius: 14px; background: var(--color-surface-subtle); border: 1px dashed var(--color-border-strong); animation: fbIn .2s ease; }
        .fb-details[hidden] { display: none; }
        @keyframes fbIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }

        .fb-textarea { min-height: 140px; font-size: 15px; }
        .fb-count { display: flex; justify-content: space-between; gap: 8px; font-size: 12px; color: var(--color-text-muted); }
        .fb-count .is-warn { color: var(--color-warning); } .fb-count .is-over { color: var(--color-danger); font-weight: 600; }
        .fb-two { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .fb-field { display: grid; gap: 6px; min-width: 0; }
        .fb-hint { margin: -4px 0 0; font-size: 12.5px; color: var(--color-text-muted); }
        .fb-hp { position: absolute !important; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

        .fb-actions { display: grid; gap: 10px; justify-items: center; padding-top: 4px; }
        .fb-submit { width: 100%; height: 50px; font-size: 15.5px; border-radius: 14px; gap: 8px; }
        .fb-submit svg { width: 18px; height: 18px; }
        [dir="rtl"] .fb-submit svg { transform: scaleX(-1); }
        .fb-submit[disabled] { opacity: .75; cursor: progress; }
        .fb-spin { width: 16px; height: 16px; border-radius: 50%; border: 2px solid rgba(255,255,255,.4); border-top-color: #fff; animation: fbSpin .7s linear infinite; }
        @keyframes fbSpin { to { transform: rotate(360deg); } }
        .fb-privacy { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--color-text-muted); }
        .fb-privacy svg { width: 14px; height: 14px; }
        .fb-alert { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; border-radius: 12px; background: var(--color-danger-soft); color: var(--color-danger); font-size: 13.5px; font-weight: 500; }
        .fb-alert svg { width: 18px; height: 18px; flex: none; margin-top: 1px; }

        .fb-thanks { padding: 52px 36px 48px; text-align: center; display: grid; justify-items: center; gap: 12px; }
        .fb-thanks-icon { width: 76px; height: 76px; border-radius: 50%; display: grid; place-items: center; background: var(--color-success-soft); color: var(--color-success); box-shadow: 0 0 0 10px rgba(47,110,79,.06); animation: fbPop .45s cubic-bezier(.2,1.4,.4,1) both; }
        .fb-thanks-icon svg { width: 38px; height: 38px; }
        @keyframes fbPop { from { transform: scale(.6); opacity: 0; } to { transform: none; opacity: 1; } }
        .fb-thanks h1 { margin: 10px 0 0; font-size: clamp(22px, 3.6vw, 28px); font-weight: 700; line-height: 1.25; letter-spacing: -.01em; max-width: 520px; }
        .fb-thanks p { margin: 0; max-width: 480px; font-size: 15px; line-height: 1.6; color: var(--color-text-secondary); }
        .fb-thanks-actions { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin-top: 12px; }
        .fb-foot { text-align: center; font-size: 12px; color: var(--color-text-muted); margin-top: 20px; }

        @media (max-width: 600px) {
            .fb-hero, [dir="rtl"] .fb-hero { padding: 30px 20px 24px; --fb-shade: linear-gradient(180deg, rgba(10,30,68,.9) 0%, rgba(16,46,102,.86) 100%); } .fb-form { padding: 22px 20px 26px; gap: 24px; } .fb-thanks { padding: 40px 20px; }
            .fb-two { grid-template-columns: 1fr; }
            .fb-cats { grid-template-columns: 1fr 1fr; }
            .fb-cats .fb-cat:last-child { grid-column: 1 / -1; }
            .fb-star svg { width: 36px; height: 36px; }
            .fb-rating-label { flex-basis: 100%; margin-inline-start: 4px; }
        }
        @media (prefers-reduced-motion: reduce) { .fb-star, .fb-details, .fb-thanks-icon { animation: none; transition: none; } .fb-star:hover { transform: none; } }
    </style>
</head>
<body class="fb">
    <header class="fb-bar">
        <a href="/" aria-label="Link Space Panel"><img src="/logo.webp" alt="Link Space Panel"></a>
        {{-- Both languages are always visible; the current one is marked. --}}
        <nav class="fb-lang" aria-label="{{ $f('language') }}">
            @foreach (['en' => 'English', 'ar' => 'العربية'] as $code => $name)
                @if ($code === $locale)
                    <span aria-current="true" lang="{{ $code }}">{{ $name }}</span>
                @else
                    <form method="POST" action="{{ route('language.switch', $code) }}">
                        @csrf
                        <button type="submit" lang="{{ $code }}">{{ $name }}</button>
                    </form>
                @endif
            @endforeach
        </nav>
    </header>

    <main class="fb-wrap">
        <div class="fb-card">
            @if ($sent)
                <section class="fb-thanks" aria-live="polite">
                    <div class="fb-thanks-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></div>
                    <h1>{{ $f('thanks.title') }}</h1>
                    <p>{{ $f('thanks.text') }}</p>
                    <div class="fb-thanks-actions">
                        <a href="{{ route('feedback.create') }}" class="ls-btn ls-btn--primary">{{ $f('thanks.again') }}</a>
                        <a href="/" class="ls-btn ls-btn--secondary">{{ $f('back_home') }}</a>
                    </div>
                </section>
            @else
                <div class="fb-hero">
                    <span class="fb-eyebrow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="{{ $starPath }}"/></svg>{{ $f('eyebrow') }}</span>
                    <h1 class="fb-title">{{ $f('title') }}</h1>
                    <p class="fb-sub">{{ $f('subtitle') }}</p>
                    <div class="fb-meta">
                        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>{{ $f('takes') }}</span>
                        <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/></svg>{{ $f('privacy') }}</span>
                    </div>
                    @if ($signedInAs)
                        <p class="fb-signed">{{ $f('signed_in', ['name' => $signedInAs, 'business' => $businessName ?: $signedInAs]) }}</p>
                    @endif
                </div>

                <form method="POST" action="{{ route('feedback.store') }}" class="fb-form" id="fb-form" novalidate>
                    @csrf
                    <input type="hidden" name="started_at" value="{{ $startedAt }}">
                    {{-- Honeypot: invisible to people, tempting to bots. --}}
                    <div class="fb-hp" aria-hidden="true">
                        <label for="fb-website">Website</label>
                        <input type="text" id="fb-website" name="website" tabindex="-1" autocomplete="off">
                    </div>

                    <div class="fb-alert" id="fb-alert" role="alert" @if (! $errors->any()) hidden @endif>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
                        <span id="fb-alert-text">{{ $errors->first('form') ?: $f('errors.title') }}</span>
                    </div>

                    {{-- A. Rating --}}
                    <fieldset class="fb-q {{ $errors->has('rating') ? 'is-invalid' : '' }}" aria-describedby="fb-rating-err">
                        <legend>{{ $f('rating.question') }} <span class="fb-tag is-req">{{ $f('required') }}</span></legend>
                        <div class="fb-stars" id="fb-stars">
                            @for ($i = 1; $i <= 5; $i++)
                                <label class="fb-star {{ $oldRating >= $i ? 'is-on' : '' }}" data-value="{{ $i }}">
                                    <input type="radio" name="rating" value="{{ $i }}" @checked($oldRating === $i) required
                                           aria-label="{{ trans_choice('app.feedback.rating.star', $i, ['count' => $i]) }} — {{ __('app.feedback.rating.labels.'.$i) }}">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $starPath }}" stroke-linejoin="round"/></svg>
                                </label>
                            @endfor
                            <span class="fb-rating-label {{ $oldRating ? 'is-set' : '' }}" id="fb-rating-label" aria-live="polite">{{ $oldRating ? __('app.feedback.rating.labels.'.$oldRating) : $f('rating.pick') }}</span>
                        </div>
                        <p class="fb-err" id="fb-rating-err" @unless ($errors->has('rating')) hidden @endunless><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg><span>{{ $errors->first('rating') }}</span></p>
                    </fieldset>

                    {{-- B. Category (+ its optional follow-up question) --}}
                    <fieldset class="fb-q {{ $errors->has('category') ? 'is-invalid' : '' }}" aria-describedby="fb-category-err">
                        <legend>{{ $f('category.question') }} <span class="fb-tag is-req">{{ $f('required') }}</span></legend>
                        <div class="fb-cats">
                            @foreach (Feedback::CATEGORIES as $cat)
                                <label class="fb-cat">
                                    <input type="radio" name="category" value="{{ $cat }}" @checked($oldCategory === $cat) required>
                                    <span class="fb-cat-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! $categoryIcons[$cat] !!}</svg></span>
                                    <b>{{ $f('category.options.'.$cat) }}</b>
                                    <small>{{ $f('category.hints.'.$cat) }}</small>
                                    <span class="fb-cat-check" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></span>
                                </label>
                            @endforeach
                        </div>
                        <p class="fb-err" id="fb-category-err" @unless ($errors->has('category')) hidden @endunless><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg><span>{{ $errors->first('category') }}</span></p>

                        @foreach (Feedback::CATEGORIES_WITH_DETAILS as $cat)
                            <div class="fb-details" data-details-for="{{ $cat }}" @if ($oldCategory !== $cat) hidden @endif>
                                <label class="ls-label" for="fb-details-{{ $cat }}">{{ $f('details.'.$cat) }} <span class="fb-tag">{{ $f('optional') }}</span></label>
                                <textarea id="fb-details-{{ $cat }}" class="ls-textarea {{ $errors->has('details') && $oldCategory === $cat ? 'is-invalid' : '' }}" rows="3" maxlength="{{ $messageMax }}"
                                          placeholder="{{ $f('details.'.$cat.'_ph') }}" @if ($oldCategory === $cat) name="details" @endif data-details-input>{{ $oldCategory === $cat ? old('details') : '' }}</textarea>
                                @if ($errors->has('details') && $oldCategory === $cat)<p class="fb-err">{{ $errors->first('details') }}</p>@endif
                            </div>
                        @endforeach
                    </fieldset>

                    {{-- C. Product area --}}
                    <div class="fb-q">
                        <label class="fb-qlabel" for="fb-area">{{ $f('area.question') }} <span class="fb-tag">{{ $f('optional') }}</span></label>
                        <select id="fb-area" name="product_area" class="ls-select {{ $errors->has('product_area') ? 'is-invalid' : '' }}">
                            <option value="">{{ $f('area.placeholder') }}</option>
                            @foreach (Feedback::AREAS as $area)
                                <option value="{{ $area }}" @selected(old('product_area') === $area)>{{ $f('area.options.'.$area) }}</option>
                            @endforeach
                        </select>
                        @error('product_area')<p class="fb-err">{{ $message }}</p>@enderror
                    </div>

                    {{-- D. Message --}}
                    <div class="fb-q">
                        <label class="fb-qlabel" for="fb-message">{{ $f('message.label') }} <span class="fb-tag is-req">{{ $f('required') }}</span></label>
                        <textarea id="fb-message" name="message" class="ls-textarea fb-textarea {{ $errors->has('message') ? 'is-invalid' : '' }}" rows="5" required
                                  minlength="{{ $messageMin }}" maxlength="{{ $messageMax }}" placeholder="{{ $f('message.placeholder') }}"
                                  aria-describedby="fb-message-err fb-message-count">{{ old('message') }}</textarea>
                        <div class="fb-count">
                            <p class="fb-err" id="fb-message-err" @unless ($errors->has('message')) hidden @endunless><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg><span>{{ $errors->first('message') }}</span></p>
                            <span id="fb-message-count" style="margin-inline-start:auto" aria-live="off"><bdi>{{ $f('message.counter', ['count' => mb_strlen((string) old('message')), 'max' => $messageMax]) }}</bdi></span>
                        </div>
                    </div>

                    {{-- E. Contact (optional) --}}
                    <fieldset class="fb-q">
                        <legend>{{ $f('contact.title') }} <span class="fb-tag">{{ $f('optional') }}</span></legend>
                        <p class="fb-hint">{{ $f('contact.hint') }}</p>
                        <div class="fb-two">
                            <div class="fb-field">
                                <label class="ls-label" for="fb-name">{{ $f('contact.name') }}</label>
                                <input id="fb-name" type="text" name="contact_name" value="{{ old('contact_name') }}" maxlength="100" autocomplete="name" class="ls-input {{ $errors->has('contact_name') ? 'is-invalid' : '' }}" placeholder="{{ $f('contact.name_ph') }}">
                                @error('contact_name')<p class="fb-err">{{ $message }}</p>@enderror
                            </div>
                            <div class="fb-field">
                                <label class="ls-label" for="fb-email">{{ $f('contact.email') }}</label>
                                <input id="fb-email" type="email" name="contact_email" value="{{ old('contact_email') }}" maxlength="255" autocomplete="email" dir="ltr" class="ls-input {{ $errors->has('contact_email') ? 'is-invalid' : '' }}" placeholder="{{ $f('contact.email_ph') }}" aria-describedby="fb-email-err">
                                <p class="fb-err" id="fb-email-err" @unless ($errors->has('contact_email')) hidden @endunless><span>{{ $errors->first('contact_email') }}</span></p>
                            </div>
                        </div>
                    </fieldset>

                    <div class="fb-actions">
                        <button type="submit" class="ls-btn ls-btn--primary fb-submit" id="fb-submit">
                            <span data-label>{{ $f('submit') }}</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                        </button>
                        <span class="fb-privacy"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/></svg>{{ $f('privacy') }}</span>
                    </div>
                </form>
            @endif
        </div>
        <p class="fb-foot">&copy; {{ date('Y') }} Link Space Panel</p>
    </main>

    @unless ($sent)
    <script>
        (() => {
            const T = @json($jsText);
            const MIN = {{ $messageMin }}, MAX = {{ $messageMax }};
            const form = document.getElementById('fb-form');
            if (!form) return;

            // ── Stars: hover preview, click/keyboard select, spoken label ──
            const stars = [...form.querySelectorAll('.fb-star')];
            const label = document.getElementById('fb-rating-label');
            const current = () => +(form.querySelector('input[name=rating]:checked')?.value || 0);
            const paint = (value, cls) => stars.forEach(s => s.classList.toggle(cls, +s.dataset.value <= value));
            const showLabel = (v) => { label.textContent = v ? T.labels[v] : T.pick; label.classList.toggle('is-set', !!v); };
            stars.forEach(s => {
                s.addEventListener('mouseenter', () => { paint(+s.dataset.value, 'is-hover'); showLabel(+s.dataset.value); });
                s.querySelector('input').addEventListener('change', () => { paint(current(), 'is-on'); showLabel(current()); clearErr('rating'); });
            });
            document.getElementById('fb-stars').addEventListener('mouseleave', () => { paint(0, 'is-hover'); showLabel(current()); });

            // ── Category → its optional follow-up question ──
            const details = [...form.querySelectorAll('[data-details-for]')];
            const syncDetails = () => {
                const cat = form.querySelector('input[name=category]:checked')?.value;
                details.forEach(d => {
                    const on = d.dataset.detailsFor === cat;
                    d.hidden = !on;
                    const ta = d.querySelector('[data-details-input]');
                    on ? ta.setAttribute('name', 'details') : ta.removeAttribute('name'); // only the visible one is sent
                });
            };
            form.querySelectorAll('input[name=category]').forEach(r => r.addEventListener('change', () => { syncDetails(); clearErr('category'); }));
            syncDetails();

            // ── Message counter ──
            const msg = document.getElementById('fb-message');
            const counter = document.getElementById('fb-message-count');
            const count = () => {
                const n = msg.value.length;
                counter.innerHTML = '<bdi class="' + (n > MAX ? 'is-over' : n > MAX * 0.9 ? 'is-warn' : '') + '">' + n + ' / ' + MAX + '</bdi>';
            };
            msg.addEventListener('input', () => { count(); if (msg.value.trim().length >= MIN) clearErr('message'); });
            count();

            // ── Client-side checks (the server validates everything again) ──
            const errBox = { rating: 'fb-rating-err', category: 'fb-category-err', message: 'fb-message-err', contact_email: 'fb-email-err' };
            function setErr(key, text) {
                const el = document.getElementById(errBox[key]);
                el.querySelector('span').textContent = text; el.hidden = false;
                el.closest('.fb-q')?.classList.add('is-invalid');
                if (key === 'message') msg.classList.add('is-invalid');
                if (key === 'contact_email') document.getElementById('fb-email').classList.add('is-invalid');
            }
            function clearErr(key) {
                const el = document.getElementById(errBox[key]);
                if (!el) return;
                el.hidden = true; el.closest('.fb-q')?.classList.remove('is-invalid');
                if (key === 'message') msg.classList.remove('is-invalid');
                if (key === 'contact_email') document.getElementById('fb-email').classList.remove('is-invalid');
            }
            const email = document.getElementById('fb-email');
            email.addEventListener('input', () => clearErr('contact_email'));

            const btn = document.getElementById('fb-submit');
            form.addEventListener('submit', (e) => {
                if (btn.disabled) { e.preventDefault(); return; } // no double submits
                const problems = [];
                if (!current()) { setErr('rating', T.errRating); problems.push(stars[0].querySelector('input')); }
                if (!form.querySelector('input[name=category]:checked')) { setErr('category', T.errCategory); problems.push(form.querySelector('input[name=category]')); }
                const len = msg.value.trim().length;
                if (len < MIN || len > MAX) { setErr('message', T.errMessage); problems.push(msg); }
                if (email.value.trim() && !email.checkValidity()) { setErr('contact_email', T.errEmail); problems.push(email); }
                const alertBox = document.getElementById('fb-alert');
                if (problems.length) {
                    e.preventDefault();
                    document.getElementById('fb-alert-text').textContent = T.errTitle;
                    alertBox.hidden = false;
                    problems[0].focus({ preventScroll: true });
                    problems[0].closest('.fb-q').scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return;
                }
                alertBox.hidden = true;
                btn.disabled = true;
                btn.setAttribute('aria-busy', 'true');
                btn.innerHTML = '<span class="fb-spin" aria-hidden="true"></span><span>' + T.sending + '</span>';
            });
            // Back/forward cache: never come back to a stuck "Sending…" button.
            window.addEventListener('pageshow', (e) => { if (e.persisted) location.reload(); });

            @if ($errors->any())
                document.querySelector('.fb-q.is-invalid, .is-invalid')?.scrollIntoView({ block: 'center' });
            @endif
        })();
    </script>
    @endunless
</body>
</html>
