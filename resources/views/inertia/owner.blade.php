<!DOCTYPE html>
@php $locale = app()->getLocale(); $isRtl = $locale === 'ar'; @endphp
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" data-theme="light" data-server-now="{{ now()->getTimestampMs() }}">
<head>
    {{-- Resolve the theme (light | dark | system) and sidebar state before first paint — no flash. --}}
    <script>(function(){var r=document.documentElement;try{var p=localStorage.getItem('ls-theme')||'system';var d=p==='dark'||(p==='system'&&window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches);r.dataset.theme=d?'dark':'light';r.dataset.themePref=p;if(localStorage.getItem('ls-nav')==='collapsed')r.dataset.nav='collapsed';}catch(e){}})();</script>
    <title inertia>Link Space Panel</title>
    @include('inertia._head')
    @inertiaHead
</head>
<body class="ls-app antialiased">
    @inertia

    {{-- Quick booking modal (still Blade this phase): it intercepts any plain link to /bookings/create. --}}
    @php
        $owner = \App\Support\TenantContext::user();
        $actingStaff = auth('staff')->user();
        $canQuickBook = $owner && $owner->hasFeature('booking')
            && (! $actingStaff || ($actingStaff->hasPermission('bookings.create') && $actingStaff->hasPermission('bookings.view')));
    @endphp
    @if ($canQuickBook)
        @include('bookings._quick-modal')
    @endif
</body>
</html>
