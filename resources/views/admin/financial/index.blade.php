@extends('layouts.admin')

@section('page-title', __('app.nav.financial'))

@php
    use App\Support\Money;
    $t = fn (string $key, array $r = []) => __('app.admin_platform.'.$key, $r);
    $c = $cards;
    $hasFilters = request()->hasAny(['owner', 'plan', 'type', 'payment', 'preset']);
    $typeTone = ['subscription' => 'info', 'booking' => 'neutral', 'sale' => 'neutral'];
@endphp

@section('content')
<div class="ls-adm">
    <header class="ls-page-head ls-adm-head">
        <div>
            <h1 class="ls-title">{{ __('app.nav.financial') }}</h1>
            <p class="ls-subtitle">{{ $t('financial_sub', ['from' => \Carbon\Carbon::parse($range['from'])->translatedFormat('M j, Y'), 'to' => \Carbon\Carbon::parse($range['to'])->translatedFormat('M j, Y')]) }}</p>
        </div>
    </header>

    <form method="GET" class="ls-adm-filters" aria-label="{{ $t('filters') }}">
        @include('admin.partials.period', $range)
        <div class="ls-field ls-filter-field">
            <label class="ls-label" for="f-owner">{{ $t('col.workspace') }}</label>
            <select id="f-owner" name="owner" class="ls-select">
                <option value="">{{ $t('all_workspaces') }}</option>
                @foreach ($owners as $o)<option value="{{ $o->id }}" @selected($filters['owner'] === $o->id)>{{ $o->business_name ?: $o->name }}</option>@endforeach
            </select>
        </div>
        <div class="ls-field ls-filter-field">
            <label class="ls-label" for="f-plan">{{ __('app.nav.plans') }}</label>
            <select id="f-plan" name="plan" class="ls-select">
                <option value="">{{ $t('all_plans') }}</option>
                @foreach ($plans as $p)<option value="{{ $p->id }}" @selected($filters['plan'] === $p->id)>{{ $p->name }}</option>@endforeach
            </select>
        </div>
        <div class="ls-field ls-filter-field">
            <label class="ls-label" for="f-type">{{ $t('tx_type') }}</label>
            <select id="f-type" name="type" class="ls-select">
                <option value="">{{ __('app.common.all') }}</option>
                @foreach (\App\Http\Controllers\Admin\FinancialController::TYPES as $ty)<option value="{{ $ty }}" @selected($filters['type'] === $ty)>{{ $t('tx.'.$ty) }}</option>@endforeach
            </select>
        </div>
        <div class="ls-field ls-filter-field">
            <label class="ls-label" for="f-pay">{{ $t('col.payment') }}</label>
            <select id="f-pay" name="payment" class="ls-select">
                <option value="">{{ __('app.common.all') }}</option>
                @foreach (\App\Http\Controllers\Admin\FinancialController::PAYMENTS as $p)<option value="{{ $p }}" @selected($filters['payment'] === $p)>{{ $t('payment.'.$p) }}</option>@endforeach
            </select>
        </div>
        <div class="ls-filter-actions">
            <button type="submit" class="ls-btn ls-btn--primary">{{ $t('apply') }}</button>
            @if ($hasFilters)<a href="/admin/financial" class="ls-btn ls-btn--ghost">{{ $t('reset') }}</a>@endif
        </div>
    </form>

    <section aria-labelledby="pl-title">
        <h2 class="ls-adm-section" id="pl-title">{{ $t('section.platform_money') }}</h2>
        <div class="ls-akpis ls-akpis--money">
            @include('admin.partials.stat', ['label' => $t('kpi.platform_revenue'), 'value' => Money::format($c['platform_revenue']['value']), 'change' => $c['platform_revenue']['change'], 'tone' => 'brand', 'help' => $t('help.platform_revenue')])
            @include('admin.partials.stat', ['label' => $t('kpi.mrr'), 'value' => Money::format($c['mrr']['value']), 'help' => $t('help.mrr'), 'sub' => $t('right_now')])
        </div>
    </section>

    <section aria-labelledby="ws-title">
        <h2 class="ls-adm-section" id="ws-title">{{ $t('section.workspace_money') }}</h2>
        <div class="ls-akpis">
            @include('admin.partials.stat', ['label' => $t('kpi.earnings'), 'value' => Money::format($c['earnings']['value']), 'change' => $c['earnings']['change'], 'tone' => 'revenue', 'help' => $t('help.earnings')])
            @include('admin.partials.stat', ['label' => $t('kpi.booking_earnings'), 'value' => Money::format($c['booking_earnings']['value']), 'change' => $c['booking_earnings']['change']])
            @include('admin.partials.stat', ['label' => $t('kpi.sales'), 'value' => Money::format($c['sales']['value']), 'change' => $c['sales']['change'], 'help' => $t('help.sales')])
            @include('admin.partials.stat', ['label' => $t('kpi.gbv'), 'value' => Money::format($c['gbv']['value']), 'change' => $c['gbv']['change'], 'help' => $t('help.gbv')])
            @include('admin.partials.stat', ['label' => $t('kpi.outstanding'), 'value' => Money::format($c['outstanding']['value']), 'tone' => $c['outstanding']['value'] > 0 ? 'warn' : null, 'help' => $t('help.outstanding'), 'sub' => $t('all_time'), 'href' => '/admin/bookings?payment=due'.($filters['owner'] ? '&owner='.$filters['owner'] : '')])
            @include('admin.partials.stat', ['label' => $t('kpi.discounts'), 'value' => Money::format($c['discounts']['value']), 'change' => $c['discounts']['change'], 'help' => $t('help.discounts')])
            @include('admin.partials.stat', ['label' => $t('kpi.package_value'), 'value' => Money::format($c['package_value']['value']), 'change' => $c['package_value']['change'], 'help' => $t('help.package_value')])
        </div>
    </section>

    <div class="ls-adm-grid">
        @include('admin.partials.chart', ['id' => 'f-earn', 'title' => $t('chart.earnings_title'), 'note' => $t('chart.earnings_split_note'), 'spec' => $charts['earnings']])
        @include('admin.partials.chart', ['id' => 'f-plat', 'title' => $t('chart.platform_title'), 'note' => $t('chart.platform_note'), 'spec' => $charts['platform']])
        @include('admin.partials.chart', ['id' => 'f-pay', 'title' => $t('chart.payments_title'), 'note' => $t('chart.click_slice'), 'spec' => $charts['payments']])
        @include('admin.partials.chart', ['id' => 'f-plans', 'title' => $t('chart.plan_revenue_title'), 'spec' => $charts['plans']])
        @include('admin.partials.chart', ['id' => 'f-top', 'title' => $t('chart.top_title'), 'note' => $t('chart.top_note'), 'spec' => $charts['top'], 'height' => max(180, 34 * count($charts['top']['labels']) + 40)])

        <section class="ls-card" aria-labelledby="rules-title">
            <div class="ls-card-head"><h2 class="ls-card-title" id="rules-title">{{ $t('rules_title') }}</h2></div>
            <div class="ls-card-body">
                <dl class="ls-adm-rules">
                    @foreach (['platform_revenue', 'earnings', 'gbv', 'outstanding', 'mrr', 'sessions'] as $r)
                        <dt>{{ $t('rules.'.$r.'.name') }}</dt><dd>{{ $t('rules.'.$r.'.rule') }}</dd>
                    @endforeach
                </dl>
                <h3 class="ls-section-title" style="margin-top:var(--space-5)">{{ $t('not_tracked_title') }}</h3>
                <ul class="ls-adm-untracked">
                    @foreach (['refunds', 'failed_payments', 'commissions', 'providers', 'transaction_ids'] as $u)
                        <li><b>{{ $t('not_tracked.'.$u.'.name') }}</b> <x-ui.badge tone="neutral" :dot="false">{{ $t('not_tracked_badge') }}</x-ui.badge><span>{{ $t('not_tracked.'.$u.'.needs') }}</span></li>
                    @endforeach
                </ul>
            </div>
        </section>
    </div>

    <section class="ls-card" id="transactions" aria-labelledby="tx-title">
        <div class="ls-card-head">
            <div>
                <h2 class="ls-card-title" id="tx-title">{{ $t('transactions') }} <span class="ls-count">{{ number_format($transactions->total()) }}</span></h2>
                <p class="ls-chart-note">{{ $t('transactions_note') }}</p>
            </div>
        </div>
        <div class="ls-card-body ls-card-body--flush">
            @if ($transactions->isEmpty())
                <x-ui.empty-state :title="$t('no_transactions')" :text="$hasFilters ? $t('no_match_text') : null" />
            @else
                <div class="ls-table-wrap">
                    <table class="ls-table ls-adm-table">
                        <thead><tr>
                            @include('admin.partials.sort', ['key' => 'date', 'label' => $t('col.date')])
                            <th scope="col">{{ $t('tx_type') }}</th>
                            <th scope="col">{{ $t('col.workspace') }}</th>
                            <th scope="col">{{ $t('related') }}</th>
                            <th scope="col">{{ $t('payer') }}</th>
                            <th scope="col">{{ __('app.common.status') }}</th>
                            @include('admin.partials.sort', ['key' => 'amount', 'label' => $t('amount'), 'class' => 'is-num'])
                        </tr></thead>
                        <tbody>
                            @foreach ($transactions as $tx)
                                @php
                                    $url = match ($tx->type) { 'subscription' => '/admin/owners/'.$tx->owner_id.'/subscription', 'booking' => '/admin/bookings/'.$tx->id, default => $tx->ref ? '/admin/bookings/'.$tx->ref : null };
                                @endphp
                                <tr @if ($url) class="row-link" data-href="{{ $url }}" @endif data-tx="{{ $tx->type }}">
                                    <td class="ls-nowrap ls-num">{{ \Carbon\Carbon::parse($tx->at)->translatedFormat('M j, Y') }}</td>
                                    <td><x-ui.badge :tone="$typeTone[$tx->type]" :dot="false">{{ $t('tx.'.$tx->type) }}</x-ui.badge></td>
                                    <td><a href="/admin/owners/{{ $tx->owner_id }}" class="ls-link">{{ $tx->workspace ?? '—' }}</a></td>
                                    <td>
                                        @if ($tx->type === 'subscription'){{ $tx->ref ?? '—' }}
                                        @elseif ($tx->type === 'booking')<a href="{{ $url }}" class="ls-link">#{{ $tx->id }}</a> · {{ $tx->ref ?? '—' }}
                                        @else {{ $t('sale_n', ['id' => $tx->id]) }}@if ($tx->ref) · <a href="{{ $url }}" class="ls-link">#{{ $tx->ref }}</a>@endif
                                        @endif
                                    </td>
                                    <td>{{ $tx->payer ?? ($tx->type === 'subscription' ? '—' : $t('walk_in')) }}</td>
                                    <td>
                                        {{ $tx->type === 'subscription' ? $t('recorded') : $t('status.'.$tx->status) }}
                                        @unless ($tx->counted)<small class="ls-adm-sub">{{ $t('not_in_earnings') }}</small>@endunless
                                    </td>
                                    <td class="is-money">{{ Money::format((float) $tx->amount) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="ls-adm-pager">
                    <span class="ls-faint">{{ $t('showing', ['from' => $transactions->firstItem(), 'to' => $transactions->lastItem(), 'total' => $transactions->total()]) }}</span>
                    {{ $transactions->links('admin.partials.pager') }}
                </div>
            @endif
        </div>
    </section>
</div>
@endsection
