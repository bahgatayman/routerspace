@extends('layouts.admin')

@section('page-title', __('app.admin_platform.nav.workspaces'))

@php
    use App\Support\Money;
    $t = fn (string $key, array $r = []) => __('app.admin_platform.'.$key, $r);
    $statusTone = ['active' => 'ok', 'expiring_soon' => 'warn', 'expired' => 'danger', 'disabled' => 'danger', 'never' => 'neutral'];
    $chip = fn (?string $s) => request()->fullUrlWithQuery(['status' => $s, 'page' => null]);
    $hasFilters = request()->hasAny(['q', 'status', 'plan', 'joined_from', 'joined_to', 'preset']);
    $moreOpen = filled($filters['joined_from']) || filled($filters['joined_to']);

    // "Sort by" replaces the old sortable table headers: same sort/dir query params.
    $sortOptions = collect(['created_desc', 'created_asc', 'name_asc', 'earnings_desc', 'bookings_desc', 'activity_desc', 'expires_asc', 'rooms_desc'])
        ->mapWithKeys(function ($o) {
            [$key, $dir] = explode('_', $o);

            return [$o => ['url' => request()->fullUrlWithQuery(['sort' => $key, 'dir' => $dir, 'page' => null]), 'on' => false]];
        });
    $currentSort = $sort.'_'.$dir;
@endphp

@section('content')
<div class="ls-adm">
    <header class="ls-page-head ls-adm-head">
        <div>
            <h1 class="ls-title">{{ $t('nav.workspaces') }} <span class="ls-count">{{ number_format($total) }}</span></h1>
            <p class="ls-subtitle">{{ $t('directory_sub') }}</p>
        </div>
        <div class="ls-actions">
            <x-ui.button variant="primary" icon="plus" href="/admin/owners/create">{{ __('app.btn.add_owner') }}</x-ui.button>
        </div>
    </header>

    <nav class="ls-chips" aria-label="{{ __('app.common.status') }}">
        <a href="{{ $chip(null) }}" class="ls-chip {{ $filters['status'] ? '' : 'is-active' }}">{{ __('app.common.all') }} <span class="ls-chip-count">{{ $total }}</span></a>
        @foreach ($counts as $s => $n)
            <a href="{{ $chip($s) }}" class="ls-chip {{ $filters['status'] === $s ? 'is-active' : '' }}" data-status-chip="{{ $s }}">{{ $t('ws_status.'.$s) }} <span class="ls-chip-count">{{ $n }}</span></a>
        @endforeach
    </nav>

    <form method="GET" class="ls-wsd-filters" aria-label="{{ $t('filters') }}">
        @if ($filters['status'])<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
        <input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="dir" value="{{ $dir }}">
        <div class="ls-wsd-filter-row">
            <div class="ls-field ls-filter-field ls-wsd-search">
                <label class="ls-label" for="f-q">{{ __('app.common.search') }}</label>
                <div class="ls-search">
                    <x-ui.icon name="search" />
                    <input id="f-q" type="search" name="q" value="{{ $filters['q'] }}" class="ls-input" placeholder="{{ $t('search_ws') }}" autocomplete="off">
                </div>
            </div>
            <div class="ls-field ls-filter-field">
                <label class="ls-label" for="f-plan">{{ __('app.nav.plans') }}</label>
                <select id="f-plan" name="plan" class="ls-select">
                    <option value="">{{ $t('all_plans') }}</option>
                    @foreach ($plans as $p)<option value="{{ $p->id }}" @selected($filters['plan'] === $p->id)>{{ $p->name }}</option>@endforeach
                </select>
            </div>
            @include('admin.partials.period', $range)
            <div class="ls-filter-actions">
                <button type="submit" class="ls-btn ls-btn--primary">{{ $t('apply') }}</button>
                @if ($hasFilters)<a href="/admin/workspaces" class="ls-btn ls-btn--ghost">{{ $t('reset') }}</a>@endif
            </div>
        </div>
        <details class="ls-wsd-more" @if ($moreOpen) open @endif>
            <summary>{{ $t('ws_card.more_filters') }}</summary>
            <div class="ls-wsd-filter-row">
                <div class="ls-field ls-filter-field">
                    <label class="ls-label" for="f-jf">{{ $t('joined_from') }}</label>
                    <input id="f-jf" type="date" name="joined_from" value="{{ $filters['joined_from'] }}" class="ls-input" dir="ltr">
                </div>
                <div class="ls-field ls-filter-field">
                    <label class="ls-label" for="f-jt">{{ $t('joined_to') }}</label>
                    <input id="f-jt" type="date" name="joined_to" value="{{ $filters['joined_to'] }}" class="ls-input" dir="ltr">
                </div>
            </div>
        </details>
    </form>

    @if ($owners->isEmpty())
        <section class="ls-card">
            <x-ui.empty-state :title="$hasFilters ? $t('no_match') : $t('no_workspaces')" :text="$hasFilters ? $t('no_match_text') : null">
                @if ($hasFilters)<a href="/admin/workspaces" class="ls-btn ls-btn--secondary">{{ $t('reset') }}</a>@endif
            </x-ui.empty-state>
        </section>
    @else
        <div class="ls-wsd-bar">
            <span class="ls-faint">{{ $t('showing', ['from' => $owners->firstItem(), 'to' => $owners->lastItem(), 'total' => $owners->total()]) }}</span>
            <label class="ls-wsd-sort">
                <span>{{ __('app.workspace.sort_by') }}</span>
                <select class="ls-select" onchange="location.href = this.value" aria-label="{{ __('app.workspace.sort_by') }}">
                    @foreach ($sortOptions as $key => $o)
                        <option value="{{ $o['url'] }}" @selected($currentSort === $key)>{{ $t('ws_card.sort.'.$key) }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="ls-wsd-grid" aria-label="{{ $t('nav.workspaces') }}">
            @foreach ($owners as $o)
                @php
                    $st = $o->subscriptionStatus();
                    $label = $o->business_name ?: $o->name;
                    $logo = $o->logoUrl();
                    $earned = (float) $o->booking_earnings + (float) $o->sales_earnings;
                    $exp = $o->subscription_expires_at;
                @endphp
                <article class="ls-wsd-card is-{{ $st }}" aria-labelledby="ws-{{ $o->id }}">
                    <div class="ls-wsd-top">
                        @if ($logo)
                            {{-- Falls back to the initials if the stored file is gone. --}}
                            <img src="{{ $logo }}" alt="" class="ls-wsd-logo" loading="lazy" onerror="this.hidden = true; this.nextElementSibling.hidden = false;">
                            <x-ui.avatar :name="$label" size="lg" hidden />
                        @else
                            <x-ui.avatar :name="$label" size="lg" />
                        @endif
                        <div class="ls-wsd-id">
                            <a id="ws-{{ $o->id }}" href="/admin/owners/{{ $o->id }}" class="ls-wsd-name">{{ $label }}</a>
                            <span class="ls-wsd-owner">#{{ $o->id }} · {{ $o->name }}</span>
                            <bdi dir="ltr" class="ls-wsd-email" title="{{ $o->email }}">{{ $o->email }}</bdi>
                        </div>
                        <x-ui.badge :tone="$statusTone[$st] ?? 'neutral'">{{ __('app.admin_biz.status.'.$st) }}</x-ui.badge>
                    </div>

                    <div class="ls-wsd-plan">
                        <span class="ls-wsd-plan-name"><x-ui.icon name="tag" />{{ $o->plan?->name ?? $t('ws_card.no_plan') }}</span>
                        <span class="ls-wsd-exp is-{{ $st }}">
                            @if (! $exp)
                                {{ $t('ws_card.no_expiry') }}
                            @elseif ($exp->isPast())
                                {{ $t('ws_card.expired_on', ['date' => $exp->translatedFormat('M j, Y')]) }}
                            @else
                                {{ $t('ws_card.expires_on', ['date' => $exp->translatedFormat('M j, Y')]) }} · {{ $exp->diffForHumans(['parts' => 1]) }}
                            @endif
                        </span>
                    </div>

                    {{-- Value first, label under it: keeps 4 tiles readable on a narrow card. --}}
                    <dl class="ls-wsd-stats">
                        <div><dd class="ls-num">{{ number_format($o->locations_count) }}</dd><dt>{{ $t('nav.locations') }}</dt></div>
                        <div><dd class="ls-num">{{ number_format($o->rooms_count) }}</dd><dt>{{ $t('nav.rooms') }}</dt></div>
                        <div><dd class="ls-num">{{ number_format($o->products_count) }}</dd><dt>{{ $t('col.products') }}</dt></div>
                        <div><dd class="ls-num">{{ number_format($o->members_count) }}</dd><dt>{{ __('app.common.members') }}</dt></div>
                    </dl>

                    <div class="ls-wsd-period-box">
                        <span class="ls-wsd-period-label">{{ $t('ws_card.in_period') }}</span>
                        <div class="ls-wsd-period-vals">
                            <div><span>{{ $t('col.bookings_period') }}</span><b class="ls-num">{{ number_format($o->period_bookings) }}</b></div>
                            <div><span>{{ $t('col.earnings_period') }}</span><b class="ls-num {{ $earned > 0 ? 'is-money' : '' }}">{{ Money::format($earned) }}</b></div>
                        </div>
                    </div>

                    <footer class="ls-wsd-foot">
                        <span>
                            {{ $o->last_booking_at ? $t('ws_card.last_booking', ['when' => \Carbon\Carbon::parse($o->last_booking_at)->diffForHumans()]) : $t('ws_card.no_bookings') }}
                            · {{ $t('ws_card.joined_on', ['date' => $o->created_at?->translatedFormat('M j, Y')]) }}
                        </span>
                        <x-ui.icon name="chevron-right" class="ls-wsd-go" />
                    </footer>
                </article>
            @endforeach
        </div>

        <div class="ls-adm-pager">
            <span class="ls-faint">{{ $t('money_note_period') }}</span>
            {{ $owners->onEachSide(1)->links('admin.partials.pager') }}
        </div>
    @endif
</div>
@endsection
