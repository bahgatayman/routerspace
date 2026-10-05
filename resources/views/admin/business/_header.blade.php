{{--
    Business 360° header — shared by every tab of one business (Owner = tenant).
    Expects: $owner (with plan, workspaces), $active (tab key), $workspace (nullable,
    already owner-scoped by the controller), $locationAware (bool: does this tab
    honour the location filter?).
--}}
@php
    $status = $owner->subscriptionStatus();
    $tone = match ($status) { 'active' => 'ok', 'expiring_soon' => 'warn', 'never' => 'neutral', default => 'danger' };
    $base = "/admin/owners/{$owner->id}";
    $q = fn (?int $wsId) => $wsId ? '?workspace='.$wsId : '';
    $tabs = [
        'overview' => ['href' => $base, 'label' => __('app.admin_biz.tabs.overview')],
        'members' => ['href' => $base.'/users', 'label' => __('app.admin_biz.tabs.members')],
        'subscription' => ['href' => $base.'/subscription', 'label' => __('app.admin_biz.tabs.subscription')],
        'audit' => ['href' => $base.'/audit', 'label' => __('app.admin_biz.tabs.audit')],
    ];
    $locationAware = $locationAware ?? false;
@endphp
<div class="ls-biz">
    <a href="/admin/owners" class="ls-biz-back">&larr; {{ __('app.admin_biz.all_businesses') }}</a>

    <header class="ls-biz-head">
        <x-ui.avatar :name="$owner->business_name ?: $owner->name" size="lg" />
        <div class="ls-biz-id">
            <h1 class="ls-biz-name">
                {{ $owner->business_name ?: $owner->name }}
                <x-ui.badge :tone="$tone">{{ __('app.admin_biz.status.'.$status) }}</x-ui.badge>
            </h1>
            <p class="ls-biz-meta">
                <span>{{ $owner->name }}</span> ·
                <bdi dir="ltr">{{ $owner->email }}</bdi> ·
                <span>{{ $owner->plan?->name ?? __('app.admin_biz.no_plan') }}</span>
                @if ($owner->subscription_expires_at)
                    · <span>{{ __('app.admin_biz.expires', ['date' => $owner->subscription_expires_at->translatedFormat('M j, Y')]) }}</span>
                @endif
                · <span>{{ __('app.admin_biz.joined', ['date' => $owner->created_at->translatedFormat('M j, Y')]) }}</span>
            </p>
        </div>
        <div class="ls-biz-actions">
            <x-ui.button size="sm" :href="$base.'/subscription'">{{ __('app.admin_biz.renew_plan') }}</x-ui.button>
            {{-- Suspend / activate: confirmed, with an optional reason kept in the audit log. --}}
            <form method="POST" action="{{ $base }}/toggle-active" class="ls-biz-toggle"
                  onsubmit="const r = prompt(@js($owner->is_active ? __('app.admin_biz.suspend_prompt') : __('app.admin_biz.activate_prompt')), ''); if (r === null) return false; this.reason.value = r; return true;">
                @csrf
                @method('PUT')
                <input type="hidden" name="reason" value="">
                <x-ui.button type="submit" size="sm" :variant="$owner->is_active ? 'danger-quiet' : 'primary'">
                    {{ $owner->is_active ? __('app.admin_biz.suspend') : __('app.admin_biz.activate') }}
                </x-ui.button>
            </form>
        </div>
    </header>

    @if ($locationAware && $owner->workspaces->count() > 1)
        {{-- Location filter: rooms / bookings / sessions / room revenue. Everything else is business-wide. --}}
        <nav class="ls-chips ls-biz-locations" aria-label="{{ __('app.admin_biz.location') }}">
            <a href="{{ request()->url() }}" class="ls-chip {{ $workspace ? '' : 'is-active' }}">{{ __('app.admin_biz.all_locations') }}</a>
            @foreach ($owner->workspaces as $ws)
                <a href="{{ request()->url().$q($ws->id) }}" class="ls-chip {{ $workspace?->id === $ws->id ? 'is-active' : '' }}">{{ $ws->name }}</a>
            @endforeach
        </nav>
    @endif

    <nav class="ls-biz-tabs" aria-label="{{ __('app.admin_biz.sections') }}">
        @foreach ($tabs as $key => $tab)
            <a href="{{ $tab['href'] }}" class="ls-biz-tab {{ $active === $key ? 'is-active' : '' }}" @if ($active === $key) aria-current="page" @endif>{{ $tab['label'] }}</a>
        @endforeach
    </nav>
</div>
