@extends('layouts.admin')

@section('page-title', $owner->business_name ?: $owner->name)

@php
    $money = fn ($v) => app()->getLocale() === 'ar' ? number_format((float) $v, 2).' ج.م' : 'EGP '.number_format((float) $v, 2);
    $maxMembers = (int) ($owner->plan?->max_members ?? 0);
    $cards = [
        ['label' => __('app.admin_biz.cards.locations'), 'value' => $summary['locations'], 'scope' => 'business'],
        ['label' => __('app.admin_biz.cards.rooms'), 'value' => $summary['rooms'], 'scope' => 'location'],
        ['label' => __('app.admin_biz.cards.members'), 'value' => $summary['members'].($maxMembers ? ' / '.$maxMembers : ''), 'scope' => 'business'],
        ['label' => __('app.admin_biz.cards.staff'), 'value' => $summary['staff'], 'scope' => 'business'],
        ['label' => __('app.admin_biz.cards.active_sessions'), 'value' => $summary['active_sessions'], 'scope' => 'location', 'tone' => $summary['active_sessions'] ? 'brand' : null],
        ['label' => __('app.admin_biz.cards.bookings_today'), 'value' => $summary['bookings_today'], 'scope' => 'location'],
        ['label' => __('app.admin_biz.cards.revenue_today'), 'value' => $money($summary['revenue_today']), 'scope' => 'location', 'tone' => 'revenue'],
        ['label' => __('app.admin_biz.cards.revenue_month'), 'value' => $money($summary['revenue_month']), 'scope' => 'location', 'tone' => 'revenue'],
        ['label' => __('app.admin_biz.cards.expenses_month'), 'value' => $money($summary['expenses_month']), 'scope' => 'business'],
        ['label' => __('app.admin_biz.cards.outstanding'), 'value' => $money($summary['outstanding']), 'scope' => 'location', 'tone' => $summary['outstanding'] > 0 ? 'warn' : null],
    ];
@endphp

@section('content')
<div class="ls-biz-page">
    @include('admin.business._header', ['active' => 'overview', 'locationAware' => true])

    @if ($workspace)
        <p class="ls-biz-note">{{ __('app.admin_biz.location_note', ['name' => $workspace->name]) }}</p>
    @endif

    <section class="ls-biz-cards" aria-label="{{ __('app.admin_biz.tabs.overview') }}">
        @foreach ($cards as $card)
            <div class="ls-biz-card">
                <span class="ls-biz-card-label">{{ $card['label'] }}@if ($workspace && $card['scope'] === 'business')<small> · {{ __('app.admin_biz.business_wide') }}</small>@endif</span>
                <b class="ls-biz-card-value {{ isset($card['tone']) && $card['tone'] ? 'is-'.$card['tone'] : '' }}">{{ $card['value'] }}</b>
            </div>
        @endforeach
    </section>

    <div class="ls-biz-cols">
        <section class="ls-card ls-biz-panel" aria-labelledby="health-title">
            <div class="ls-card-head"><h2 class="ls-card-title" id="health-title">{{ __('app.admin_biz.health_title') }}</h2></div>
            <div class="ls-card-body">
                @if (empty($health))
                    <p class="ls-biz-healthy"><x-ui.icon name="check-circle" /> {{ __('app.admin_biz.healthy') }}</p>
                @else
                    <ul class="ls-biz-health">
                        @foreach ($health as $item)
                            <li class="is-{{ $item['level'] }}" data-health="{{ $item['key'] }}">
                                <i class="ls-biz-dot" aria-hidden="true"></i>
                                <span>{{ $item['text'] }}</span>
                                @if ($item['url'])<a href="{{ $item['url'] }}" class="ls-link">{{ __('app.admin_biz.open') }}</a>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>

        <section class="ls-card ls-biz-panel" aria-labelledby="activity-title">
            <div class="ls-card-head">
                <h2 class="ls-card-title" id="activity-title">{{ __('app.admin_biz.recent_activity') }}</h2>
                <a href="/admin/owners/{{ $owner->id }}/audit" class="ls-link">{{ __('app.admin_biz.admin_actions') }}</a>
            </div>
            <div class="ls-card-body">
                @if ($activity->isEmpty())
                    <p class="ls-faint">{{ __('app.admin_biz.no_activity') }}</p>
                @else
                    <ol class="ls-biz-feed">
                        @foreach ($activity as $a)
                            <li class="is-{{ $a['kind'] }}">
                                <time datetime="{{ $a['at']?->toIso8601String() }}">{{ $a['at']?->translatedFormat('M j · g:i A') }}</time>
                                <span><b>{{ $a['actor'] }}</b> — {{ $a['text'] }}</span>
                                @if ($a['url'])<a href="{{ $a['url'] }}" class="ls-link">{{ __('app.admin_biz.open') }}</a>@endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        </section>
    </div>

    @if ($owner->workspaces->isNotEmpty())
        <section class="ls-card ls-biz-panel" aria-labelledby="locations-title">
            <div class="ls-card-head"><h2 class="ls-card-title" id="locations-title">{{ __('app.admin_biz.locations_title') }}</h2></div>
            <div class="ls-card-body">
                <ul class="ls-biz-locs">
                    @foreach ($owner->workspaces as $ws)
                        <li>
                            <a href="/admin/locations/{{ $ws->id }}" class="ls-link">{{ $ws->name }}</a>
                            <span class="ls-faint">{{ collect([$ws->city, $ws->address, $ws->phone])->filter()->implode(' · ') ?: '—' }}</span>
                            @unless ($ws->is_active)<x-ui.badge tone="neutral" :dot="false">{{ __('app.status.inactive') }}</x-ui.badge>@endunless
                        </li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif
</div>
@endsection
