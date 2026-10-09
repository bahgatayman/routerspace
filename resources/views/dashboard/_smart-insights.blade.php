{{--
    "What You Should Know" — a small pool of plain-language sentences
    assembled in DashboardController::buildSmartInsights() from every other
    analytics source on this page. Each sentence already decided for itself
    whether it has enough data to be worth showing; this partial only
    renders what survived that check, capped at 6.
--}}
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6 mb-6" style="border-inline-start: 3px solid var(--color-primary, #2e4f8f);">
    <h3 class="font-semibold text-gray-900 mb-3 flex items-center gap-2">
        <svg class="w-4 h-4 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 18h6m-5 3h4m-6-6a7 7 0 1114 0c0 2.5-1.5 3.5-2 4.5-.3.6-.5 1-.5 1.5H8.5c0-.5-.2-.9-.5-1.5-.5-1-2-2-2-4.5z"/>
        </svg>
        {{ __('app.dashboard.smart_insights') }}
    </h3>
    <ul class="space-y-2">
        @foreach ($smartInsights as $insight)
            <li class="text-sm text-gray-700 flex gap-2">
                <span class="text-brand-500 shrink-0">•</span>
                <span>{{ $insight }}</span>
            </li>
        @endforeach
    </ul>
</div>
