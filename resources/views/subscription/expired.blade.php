<!DOCTYPE html>
@php $locale = app()->getLocale(); $isRtl = $locale === 'ar'; @endphp
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('app.status.expired') }} - {{ __('app.auth.linkspace') }}</title>
    @include('partials.favicon')
    @include('partials.theme')
    @if($isRtl)
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Cairo', sans-serif; }</style>
    @endif
</head>
{{-- dvh, not vh: mobile URL bars make 100vh taller than the visible area, which
     adds a few pixels of pointless scroll when the content is short. --}}
<body class="min-h-[100dvh] bg-gradient-to-br from-gray-50 to-red-50 p-4 sm:p-8">
    <div class="w-full max-w-4xl mx-auto">

        <!-- Status -->
        <div class="bg-white rounded-2xl shadow-lg p-6 sm:p-8 text-center">
            <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                </svg>
            </div>

            <h1 class="text-2xl font-bold text-gray-900">{{ __('app.label.subscription') }} {{ __('app.status.expired') }}</h1>
            <p class="text-gray-600 text-sm mt-2 max-w-lg mx-auto">{{ __('app.subscription.expired_hint') }}</p>

            <dl class="mt-6 inline-flex flex-wrap justify-center gap-x-8 gap-y-2 text-sm">
                <div class="flex items-center gap-2">
                    <dt class="text-gray-500">{{ __('app.label.business') }}</dt>
                    <dd class="text-gray-900 font-medium">{{ $owner->business_name }}</dd>
                </div>
                @if ($owner->plan)
                    <div class="flex items-center gap-2">
                        <dt class="text-gray-500">{{ __('app.profile.current_plan') }}</dt>
                        <dd class="text-gray-900 font-medium">{{ $owner->plan->name }}</dd>
                    </div>
                @endif
                @if ($owner->subscription_expires_at)
                    <div class="flex items-center gap-2">
                        <dt class="text-gray-500">{{ __('app.label.expires') }}</dt>
                        <dd class="text-red-600 font-medium">{{ $owner->subscription_expires_at->format('d M Y') }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        @if (session('success'))
            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mt-4">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mt-4">{{ session('error') }}</div>
        @endif
        @if ($errors->any())
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mt-4 space-y-1">
                @foreach ($errors->all() as $error)
                    <p class="text-sm">{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <!-- Plans -->
        <div class="bg-white rounded-2xl shadow-lg p-6 sm:p-8 mt-6">
            <h2 class="text-lg font-semibold text-gray-900 text-center">{{ __('app.subscription.choose_plan') }}</h2>
            <p class="text-sm text-gray-500 text-center mt-1 mb-6">{{ __('app.subscription.choose_plan_hint') }}</p>

            @include('partials.plan-picker')
        </div>

        <div class="text-center mt-6">
            <form method="POST" action="/logout">
                @csrf
                <button type="submit" class="text-sm text-gray-500 hover:text-gray-700 font-medium">
                    {{ __('app.nav.logout') }}
                </button>
            </form>
        </div>
    </div>
</body>
</html>
