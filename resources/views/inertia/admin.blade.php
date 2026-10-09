<!DOCTYPE html>
@php $locale = app()->getLocale(); $isRtl = $locale === 'ar'; @endphp
{{-- Super Admin: light-only original shell (data-theme-lock stops panel.js applying a saved dark theme). --}}
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" data-theme="light" data-theme-lock="light" data-server-now="{{ now()->getTimestampMs() }}">
<head>
    <title inertia>Link Space Panel Admin</title>
    @include('inertia._head')
    @if ($isRtl)
        <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>body { font-family: 'Cairo', sans-serif; }</style>
    @endif
    @inertiaHead
</head>
<body class="ls-admin bg-[#f8fafc] font-sans antialiased">
    @inertia
</body>
</html>
