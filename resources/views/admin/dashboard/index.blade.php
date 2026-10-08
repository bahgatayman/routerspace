@extends('layouts.admin')

@section('page-title', __('app.nav.dashboard'))

@php
    use App\Support\Money;
    $k = $kpis;
    $t = fn (string $key, array $r = []) => __('app.admin_platform.'.$key, $r);
    $num = fn ($v) => number_format((float) $v);
    $q = 'preset='.$range['preset'].'&from='.$range['from'].'&to='.$range['to'];
    $money = [
        ['label' => $t('kpi.platform_revenue'), 'value' => Money::format($k['platform_revenue']['value']), 'change' => $k['platform_revenue']['change'], 'help' => $t('help.platform_revenue'), 'tone' => 'brand', 'href' => '/admin/financial?type=subscription&'.$q],
        ['label' => $t('kpi.earnings'), 'value' => Money::format($k['earnings']['value']), 'change' => $k['earnings']['change'], 'help' => $t('help.earnings'), 'tone' => 'revenue', 'href' => '/admin/financial?'.$q],
        ['label' => $t('kpi.gbv'), 'value' => Money::format($k['gbv']['value']), 'change' => $k['gbv']['change'], 'help' => $t('help.gbv')],
        ['label' => $t('kpi.outstanding'), 'value' => Money::format($k['outstanding']['value']), 'help' => $t('help.outstanding'), 'tone' => $k['outstanding']['value'] > 0 ? 'warn' : null, 'sub' => $t('all_time'), 'href' => '/admin/bookings?payment=due'],
        ['label' => $t('kpi.mrr'), 'value' => Money::format($k['mrr']['value']), 'help' => $t('help.mrr'), 'sub' => $t('right_now')],
    ];
    $ops = [
        ['label' => $t('kpi.workspaces'), 'value' => $num($k['active_workspaces']['value']).' / '.$num($k['workspaces']['value']), 'help' => $t('help.workspaces'), 'sub' => $t('active_of_total'), 'href' => '/admin/workspaces'],
        ['label' => $t('kpi.new_workspaces'), 'value' => $num($k['new_workspaces']['value']), 'change' => $k['new_workspaces']['change'], 'sub' => $k['growth_rate']['value'] !== null ? $t('growth', ['pct' => $k['growth_rate']['value']]) : null, 'href' => '/admin/workspaces?sort=created&dir=desc'],
        ['label' => $t('kpi.bookings'), 'value' => $num($k['bookings']['value']), 'change' => $k['bookings']['change'], 'href' => '/admin/bookings?'.$q],
        ['label' => $t('kpi.completed'), 'value' => $num($k['completed']['value']), 'change' => $k['completed']['change'], 'href' => '/admin/bookings?status=completed&'.$q],
        ['label' => $t('kpi.cancellation_rate'), 'value' => number_format($k['cancellation_rate']['value'], 1).'%', 'change' => $k['cancellation_rate']['change'], 'invert' => true, 'help' => $t('help.cancellation_rate'), 'href' => '/admin/bookings?status=cancelled&'.$q],
        ['label' => $t('kpi.avg_booking_value'), 'value' => Money::format($k['avg_booking_value']['value']), 'change' => $k['avg_booking_value']['change'], 'help' => $t('help.avg_booking_value')],
        ['label' => $t('kpi.active_customers'), 'value' => $num($k['active_customers']['value']), 'change' => $k['active_customers']['change'], 'help' => $t('help.active_customers')],
        ['label' => $t('kpi.discounts'), 'value' => Money::format($k['discounts']['value']), 'change' => $k['discounts']['change'], 'help' => $t('help.discounts')],
        ['label' => $t('kpi.package_value'), 'value' => Money::format($k['package_value']['value']), 'change' => $k['package_value']['change'], 'help' => $t('help.package_value')],
        ['label' => $t('kpi.rooms'), 'value' => $num($k['rooms']['value']), 'href' => '/admin/rooms'],
        ['label' => $t('kpi.products'), 'value' => $num($k['products']['value'])],
    ];
    $levelIcon = ['danger' => 'alert', 'warning' => 'alert', 'info' => 'bell', 'positive' => 'check-circle'];
@endphp

@section('content')
<div class="ls-adm">
    <header class="ls-page-head ls-adm-head">
        <div>
            <h1 class="ls-title">{{ $t('dashboard_title') }}</h1>
            <p class="ls-subtitle">{{ $t('dashboard_sub', ['from' => \Carbon\Carbon::parse($range['from'])->translatedFormat('M j, Y'), 'to' => \Carbon\Carbon::parse($range['to'])->translatedFormat('M j, Y')]) }}</p>
        </div>
    </header>

    <form method="GET" class="ls-adm-filters" aria-label="{{ $t('filters') }}">
        @include('admin.partials.period', $range)
        <div class="ls-field ls-filter-field">
            <label class="ls-label" for="f-plan">{{ __('app.nav.plans') }}</label>
            <select id="f-plan" name="plan" class="ls-select" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                <option value="">{{ $t('all_plans') }}</option>
                @foreach ($plans as $p)<option value="{{ $p->id }}" @selected($planId === $p->id)>{{ $p->name }}</option>@endforeach
            </select>
        </div>
        <div class="ls-filter-actions">
            <button type="submit" class="ls-btn ls-btn--primary">{{ $t('apply') }}</button>
            @if (request()->hasAny(['preset', 'plan', 'from', 'to']))<a href="/admin/dashboard" class="ls-btn ls-btn--ghost">{{ $t('reset') }}</a>@endif
        </div>
    </form>

    @if ($pendingRenewals > 0)
        <x-ui.banner tone="warn">
            {{ trans_choice('app.admin_platform.pending_renewals', $pendingRenewals, ['count' => $pendingRenewals]) }}
            <a href="{{ route('admin.subscription-requests.index') }}" class="ls-link">{{ $t('review') }}</a>
        </x-ui.banner>
    @endif

    <section aria-labelledby="money-title">
        <h2 class="ls-adm-section" id="money-title">{{ $t('section.money') }}</h2>
        <div class="ls-akpis ls-akpis--money">
            @foreach ($money as $card)@include('admin.partials.stat', $card)@endforeach
        </div>
    </section>

    <section aria-labelledby="ops-title">
        <h2 class="ls-adm-section" id="ops-title">{{ $t('section.activity') }}</h2>
        <div class="ls-akpis">
            @foreach ($ops as $card)@include('admin.partials.stat', $card)@endforeach
        </div>
    </section>

    <div class="ls-adm-grid">
        @include('admin.partials.chart', ['id' => 'c-earn', 'title' => $t('chart.earnings_title'), 'note' => $t('chart.earnings_note'), 'spec' => $charts['earnings']])
        @include('admin.partials.chart', ['id' => 'c-plat', 'title' => $t('chart.platform_title'), 'note' => $t('chart.platform_note'), 'spec' => $charts['platform']])
        @include('admin.partials.chart', ['id' => 'c-book', 'title' => $t('chart.bookings_title'), 'note' => $t('chart.click_bucket'), 'spec' => $charts['bookings']])
        @include('admin.partials.chart', ['id' => 'c-status', 'title' => $t('chart.status_title'), 'note' => $t('chart.click_slice'), 'spec' => $charts['status']])
        @include('admin.partials.chart', ['id' => 'c-top', 'title' => $t('chart.top_title'), 'note' => $t('chart.top_note'), 'spec' => $charts['top'], 'height' => max(180, 34 * count($charts['top']['labels']) + 40)])
        @include('admin.partials.chart', ['id' => 'c-growth', 'title' => $t('chart.growth_title'), 'note' => $t('chart.growth_note'), 'spec' => $charts['growth']])
    </div>

    <section class="ls-card" aria-labelledby="insights-title">
        <div class="ls-card-head">
            <div>
                <h2 class="ls-card-title" id="insights-title">{{ $t('insights_title') }}</h2>
                <p class="ls-chart-note">{{ $t('insights_sub') }}</p>
            </div>
        </div>
        <div class="ls-card-body">
            @if (empty($insights['items']))
                <x-ui.empty-state :title="$t('no_insights')" :text="$t('no_insights_text')" />
            @else
                <ul class="ls-insights">
                    @foreach ($insights['items'] as $i)
                        <li class="ls-insight is-{{ $i['level'] }}" data-insight="{{ $i['key'] }}">
                            <span class="ls-insight-icon"><x-ui.icon :name="$levelIcon[$i['level']]" /></span>
                            <div class="ls-insight-body">
                                <b>{{ $i['title'] }}</b>
                                <span class="ls-insight-metric">{{ $i['metric'] }}</span>
                                <p>{{ $i['why'] }}</p>
                                <p class="ls-insight-action"><span>{{ $t('suggested') }}:</span> {{ $i['action'] }}</p>
                            </div>
                            @if ($i['url'])<a href="{{ $i['url'] }}" class="ls-btn ls-btn--secondary ls-btn--sm">{{ $t('open') }}</a>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
            <details class="ls-untracked">
                <summary>{{ $t('needs_tracking') }}</summary>
                <ul>
                    @foreach ($insights['untracked'] as $u)<li><b>{{ $t('untracked.'.$u.'.title') }}</b> — {{ $t('untracked.'.$u.'.needs') }}</li>@endforeach
                </ul>
            </details>
        </div>
    </section>

    <div class="ls-adm-grid">
        @include('admin.partials.chart', ['id' => 'c-plans', 'title' => $t('chart.plans_title'), 'note' => $t('chart.plans_note'), 'spec' => $charts['plans']])

        <section class="ls-card" aria-labelledby="planrev-title">
            <div class="ls-card-head"><h2 class="ls-card-title" id="planrev-title">{{ $t('revenue_by_plan') }}</h2></div>
            <div class="ls-card-body ls-card-body--flush">
                @if ($byPlan->isEmpty())
                    <x-ui.empty-state :title="$t('no_plan_data')" />
                @else
                    <div class="ls-table-wrap">
                        <table class="ls-table">
                            <thead><tr>
                                <th scope="col">{{ __('app.nav.plans') }}</th>
                                <th scope="col" class="is-num">{{ $t('chart.active_workspaces') }}</th>
                                <th scope="col" class="is-num">{{ $t('kpi.mrr') }}</th>
                                <th scope="col" class="is-num">{{ $t('chart.renewals') }}</th>
                                <th scope="col" class="is-num">{{ $t('kpi.platform_revenue') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($byPlan as $row)
                                    <tr>
                                        <td><a href="/admin/plans/{{ $row['plan_id'] }}" class="ls-link">{{ $row['name'] }}</a></td>
                                        <td class="is-num">{{ $num($row['active']) }}</td>
                                        <td class="is-money">{{ Money::format($row['mrr']) }}</td>
                                        <td class="is-num">{{ $num($row['renewals']) }}</td>
                                        <td class="is-money">{{ Money::format($row['revenue']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>
    </div>

    <div class="ls-adm-grid ls-adm-grid--3">
        <section class="ls-card" aria-labelledby="recent-ws">
            <div class="ls-card-head"><h2 class="ls-card-title" id="recent-ws">{{ $t('recent_workspaces') }}</h2><a href="/admin/workspaces?sort=created&dir=desc" class="ls-link">{{ $t('view_all') }}</a></div>
            <div class="ls-card-body">
                @forelse ($recentOwners as $o)
                    <a href="/admin/owners/{{ $o->id }}" class="ls-adm-row">
                        <x-ui.avatar :name="$o->business_name ?: $o->name" size="sm" />
                        <span class="ls-adm-row-main"><b class="ls-trunc">{{ $o->business_name ?: $o->name }}</b><small>{{ $o->plan?->name ?? __('app.admin_biz.no_plan') }}</small></span>
                        <time datetime="{{ $o->created_at?->toIso8601String() }}">{{ $o->created_at?->diffForHumans() }}</time>
                    </a>
                @empty
                    <p class="ls-faint">{{ $t('nothing_yet') }}</p>
                @endforelse
            </div>
        </section>

        <section class="ls-card" aria-labelledby="recent-bk">
            <div class="ls-card-head"><h2 class="ls-card-title" id="recent-bk">{{ $t('recent_bookings') }}</h2><a href="/admin/bookings" class="ls-link">{{ $t('view_all') }}</a></div>
            <div class="ls-card-body">
                @forelse ($recentBookings as $b)
                    <a href="/admin/bookings/{{ $b->id }}" class="ls-adm-row">
                        <span class="ls-adm-row-main"><b class="ls-trunc">#{{ $b->id }} · {{ $b->room?->name ?? '—' }}</b><small class="ls-trunc">{{ $b->owner?->business_name }} · {{ $b->hotspotUser?->name ?? __('app.admin_biz.deleted_member') }}</small></span>
                        <span class="ls-adm-row-end"><span class="ls-num">{{ Money::format((float) $b->total_price - (float) $b->discount_total) }}</span><small>{{ __('app.admin_platform.status.'.$b->status) }}</small></span>
                    </a>
                @empty
                    <p class="ls-faint">{{ $t('nothing_yet') }}</p>
                @endforelse
            </div>
        </section>

        <section class="ls-card" aria-labelledby="recent-pay">
            <div class="ls-card-head"><h2 class="ls-card-title" id="recent-pay">{{ $t('recent_payments') }}</h2><a href="/admin/financial?type=subscription" class="ls-link">{{ $t('view_all') }}</a></div>
            <div class="ls-card-body">
                @forelse ($recentPayments as $s)
                    <a href="/admin/owners/{{ $s->owner_id }}/subscription" class="ls-adm-row">
                        <span class="ls-adm-row-main"><b class="ls-trunc">{{ $s->owner?->business_name ?? '—' }}</b><small>{{ $s->plan?->name }} · {{ trans_choice('app.admin_platform.months', $s->months, ['count' => $s->months]) }}</small></span>
                        <span class="ls-adm-row-end"><span class="ls-num">{{ Money::format((float) $s->amount_paid) }}</span><small>{{ $s->created_at?->diffForHumans() }}</small></span>
                    </a>
                @empty
                    <p class="ls-faint">{{ $t('nothing_yet') }}</p>
                @endforelse
            </div>
        </section>

        <section class="ls-card" aria-labelledby="expiring-t">
            <div class="ls-card-head"><h2 class="ls-card-title" id="expiring-t">{{ $t('expiring_title') }}</h2><a href="/admin/workspaces?status=expiring" class="ls-link">{{ $t('view_all') }}</a></div>
            <div class="ls-card-body">
                @forelse ($expiring as $o)
                    <a href="/admin/owners/{{ $o->id }}/subscription" class="ls-adm-row">
                        <span class="ls-adm-row-main"><b class="ls-trunc">{{ $o->business_name ?: $o->name }}</b><small>{{ $o->plan?->name ?? __('app.admin_biz.no_plan') }}</small></span>
                        <span class="ls-adm-row-end"><x-ui.badge :tone="$o->daysUntilExpiry() <= 3 ? 'danger' : 'warn'">{{ trans_choice('app.admin_platform.in_days', $o->daysUntilExpiry(), ['days' => $o->daysUntilExpiry()]) }}</x-ui.badge></span>
                    </a>
                @empty
                    <p class="ls-faint">{{ $t('none_expiring') }}</p>
                @endforelse
            </div>
        </section>

        <section class="ls-card" aria-labelledby="admin-act">
            <div class="ls-card-head"><h2 class="ls-card-title" id="admin-act">{{ $t('admin_actions') }}</h2></div>
            <div class="ls-card-body">
                @forelse ($adminActions as $a)
                    <div class="ls-adm-row">
                        <span class="ls-adm-row-main"><b class="ls-trunc">{{ $a->description ?: $a->action }}</b><small class="ls-trunc">{{ $a->admin_name ?? 'Admin' }}@if ($a->owner_id) · <a href="/admin/owners/{{ $a->owner_id }}" class="ls-link">{{ $a->owner_name ?? '#'.$a->owner_id }}</a>@endif</small></span>
                        <time datetime="{{ $a->created_at?->toIso8601String() }}">{{ $a->created_at?->diffForHumans() }}</time>
                    </div>
                @empty
                    <p class="ls-faint">{{ $t('nothing_yet') }}</p>
                @endforelse
            </div>
        </section>
    </div>
</div>
@endsection
