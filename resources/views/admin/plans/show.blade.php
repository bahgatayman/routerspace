@extends('layouts.admin')

@section('page-title', $plan->name)
@section('crumb-parent', __('app.nav.plans'))

@php
    use App\Support\Money;
    $t = fn (string $key, array $r = []) => __('app.admin_platform.'.$key, $r);
    $limit = fn ($v) => (int) $v > 0 ? number_format($v) : $t('unlimited');
    $inUse = array_sum($used) > 0;
    $statusTone = ['active' => 'ok', 'expiring_soon' => 'warn', 'expired' => 'danger', 'disabled' => 'danger', 'never' => 'neutral'];
@endphp

@section('content')
<div class="ls-adm">
    <a href="/admin/plans" class="ls-biz-back">&larr; {{ __('app.nav.plans') }}</a>
    <header class="ls-biz-head">
        <div class="ls-biz-id">
            <h1 class="ls-biz-name">{{ $plan->name }}
                @if ($plan->is_active)<x-ui.badge tone="ok">{{ __('app.status.active') }}</x-ui.badge>@else<x-ui.badge tone="neutral">{{ __('app.status.inactive') }}</x-ui.badge>@endif
            </h1>
            <p class="ls-biz-meta"><span dir="ltr">{{ $plan->slug }}</span> · {{ Money::format((float) $plan->price_per_month) }} / {{ $t('month') }}</p>
        </div>
        <div class="ls-biz-actions">
            <x-ui.button size="sm" :href="'/admin/plans/'.$plan->id.'/edit'">{{ __('app.common.edit') }}</x-ui.button>
            <form method="POST" action="/admin/plans/{{ $plan->id }}/toggle" data-confirm="{{ $plan->is_active ? $t('confirm_disable_plan', ['plan' => $plan->name]) : $t('confirm_enable_plan', ['plan' => $plan->name]) }}" data-confirm-tone="{{ $plan->is_active ? 'danger' : 'primary' }}" data-confirm-label="{{ $plan->is_active ? __('app.btn.disable') : __('app.btn.enable') }}">
                @csrf <input type="hidden" name="reason" value="">
                <x-ui.button type="submit" size="sm" variant="ghost">{{ $plan->is_active ? __('app.btn.disable') : __('app.btn.enable') }}</x-ui.button>
            </form>
            @if ($plan->owners_count > 0 && $targets->isNotEmpty())
                <x-ui.button size="sm" variant="secondary" data-ls-open="migrate-plan">{{ $t('move_businesses') }}</x-ui.button>
            @endif
            <x-ui.button size="sm" variant="danger-quiet" data-ls-open="delete-plan">{{ __('app.common.delete') }}</x-ui.button>
        </div>
    </header>

    <div class="ls-akpis">
        @include('admin.partials.stat', ['label' => $t('chart.active_workspaces'), 'value' => number_format($active), 'sub' => $t('of_total', ['total' => $plan->owners_count])])
        @include('admin.partials.stat', ['label' => $t('kpi.mrr'), 'value' => Money::format($mrr), 'help' => $t('help.mrr')])
        @include('admin.partials.stat', ['label' => $t('lifetime_revenue'), 'value' => Money::format((float) $plan->revenue), 'tone' => 'brand'])
        @include('admin.partials.stat', ['label' => $t('chart.renewals'), 'value' => number_format($plan->subscriptions_count)])
        @include('admin.partials.stat', ['label' => $t('renewal_requests'), 'value' => number_format($requests)])
    </div>

    <div class="ls-adm-grid">
        <section class="ls-card">
            <div class="ls-card-head"><h2 class="ls-card-title">{{ $t('limits_features') }}</h2></div>
            <div class="ls-card-body ls-stack">
                <dl class="ls-kv">
                    <dt>{{ __('app.plan.max_members') }}</dt><dd>{{ $limit($plan->max_members) }}</dd>
                    <dt>{{ __('app.plan.max_workspaces') }}</dt><dd>{{ $limit($plan->max_workspaces) }}</dd>
                    <dt>{{ __('app.plan.max_rooms') }}</dt><dd>{{ $limit($plan->max_rooms) }}</dd>
                    <dt>{{ __('app.plan.max_products') }}</dt><dd>{{ $limit($plan->max_products) }}</dd>
                </dl>
                <ul class="ls-adm-list">
                    @foreach ($features as $f)
                        @php $on = in_array($f->key, $plan->features ?? [], true); @endphp
                        <li><span>{{ $f->name }}</span>@if ($on)<x-ui.badge tone="ok" :dot="false">{{ $t('included') }}</x-ui.badge>@else<span class="ls-faint">—</span>@endif</li>
                    @endforeach
                </ul>
            </div>
        </section>

        <section class="ls-card">
            <div class="ls-card-head"><h2 class="ls-card-title">{{ $t('recent_payments') }}</h2></div>
            <div class="ls-card-body">
                @forelse ($payments as $s)
                    <a href="/admin/owners/{{ $s->owner_id }}/subscription" class="ls-adm-row">
                        <span class="ls-adm-row-main"><b class="ls-trunc">{{ $s->owner?->business_name ?? '—' }}</b><small>{{ trans_choice('app.admin_platform.months', $s->months, ['count' => $s->months]) }} · {{ $s->admin?->name }}</small></span>
                        <span class="ls-adm-row-end"><span class="ls-num">{{ Money::format((float) $s->amount_paid) }}</span><small>{{ $s->created_at?->translatedFormat('M j, Y') }}</small></span>
                    </a>
                @empty
                    <p class="ls-faint">{{ $t('nothing_yet') }}</p>
                @endforelse
            </div>
        </section>
    </div>

    <section class="ls-card">
        <div class="ls-card-head"><h2 class="ls-card-title">{{ $t('businesses_on_plan') }} <span class="ls-count">{{ $plan->owners_count }}</span></h2></div>
        <div class="ls-card-body ls-card-body--flush">
            @if ($owners->isEmpty())
                <x-ui.empty-state :title="$t('no_businesses_on_plan')" />
            @else
                <div class="ls-table-wrap">
                    <table class="ls-table ls-adm-table">
                        <thead><tr><th scope="col">{{ $t('col.workspace') }}</th><th scope="col">{{ __('app.common.status') }}</th><th scope="col" class="is-num">{{ $t('nav.rooms') }}</th><th scope="col" class="is-num">{{ $t('col.products') }}</th><th scope="col">{{ $t('col.expires') }}</th></tr></thead>
                        <tbody>
                            @foreach ($owners as $o)
                                @php $st = $o->subscriptionStatus(); @endphp
                                <tr class="row-link" data-href="/admin/owners/{{ $o->id }}">
                                    <td><a href="/admin/owners/{{ $o->id }}" class="ls-adm-ident-name">{{ $o->business_name ?: $o->name }}</a></td>
                                    <td><x-ui.badge :tone="$statusTone[$st] ?? 'neutral'">{{ __('app.admin_biz.status.'.$st) }}</x-ui.badge></td>
                                    <td class="is-num">{{ $o->rooms_count }}</td>
                                    <td class="is-num">{{ $o->products_count }}</td>
                                    <td>{{ $o->subscription_expires_at?->translatedFormat('M j, Y') ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="ls-adm-pager"><span></span>{{ $owners->links('admin.partials.pager') }}</div>
            @endif
        </div>
    </section>
</div>

<x-ui.modal id="delete-plan" :title="$t('delete_plan_title', ['plan' => $plan->name])" size="narrow">
    @if ($inUse)
        <x-ui.banner tone="warn">{{ $t('plan_in_use', ['owners' => $used['owners'], 'subscriptions' => $used['subscriptions'], 'requests' => $used['requests']]) }}</x-ui.banner>
        <p class="ls-muted" style="margin:0">{{ $t('plan_in_use_options') }}</p>
    @else
        <p class="ls-muted" style="margin:0">{{ $t('plan_delete_safe') }}</p>
    @endif
    <x-slot:footer>
        <button type="button" class="ls-btn ls-btn--secondary" data-ls-close>{{ __('app.common.cancel') }}</button>
        @if ($inUse)
            @if ($plan->is_active)
                <form method="POST" action="/admin/plans/{{ $plan->id }}/toggle">@csrf<button type="submit" class="ls-btn ls-btn--secondary">{{ $t('deactivate_instead') }}</button></form>
            @endif
            @if ($plan->owners_count > 0 && $targets->isNotEmpty())
                <button type="button" class="ls-btn ls-btn--primary" onclick="LS.close('delete-plan'); LS.open('migrate-plan')">{{ $t('move_businesses') }}</button>
            @endif
        @else
            <form method="POST" action="/admin/plans/{{ $plan->id }}" onsubmit="LS.busy(this.querySelector('button'))">@csrf @method('DELETE')<button type="submit" class="ls-btn ls-btn--danger">{{ __('app.common.delete') }}</button></form>
        @endif
    </x-slot:footer>
</x-ui.modal>

@if ($targets->isNotEmpty())
    <x-ui.modal id="migrate-plan" :title="$t('move_businesses')" :subtitle="$t('move_sub', ['count' => $plan->owners_count, 'plan' => $plan->name])">
        <form method="POST" action="/admin/plans/{{ $plan->id }}/migrate" id="migrate-form" class="ls-stack" onsubmit="LS.busy(document.getElementById('migrate-submit'))">
            @csrf
            <div class="ls-field">
                <label class="ls-label" for="m-target">{{ $t('target_plan') }}</label>
                <select id="m-target" name="target_plan_id" class="ls-select" required>
                    <option value="">—</option>
                    @foreach ($targets as $tp)<option value="{{ $tp->id }}">{{ $tp->name }} · {{ Money::format((float) $tp->price_per_month) }}@unless ($tp->is_active) ({{ __('app.status.inactive') }})@endunless</option>@endforeach
                </select>
            </div>
            <label class="ls-adm-check"><input type="checkbox" name="deactivate" value="1" checked> {{ $t('deactivate_after_move', ['plan' => $plan->name]) }}</label>
            <div class="ls-field">
                <label class="ls-label" for="m-reason">{{ $t('reason') }} <span class="ls-opt">({{ $t('optional') }})</span></label>
                <textarea id="m-reason" name="reason" class="ls-textarea" rows="2" maxlength="500"></textarea>
            </div>
            <p class="ls-hint" style="margin:0">{{ $t('move_note') }}</p>
        </form>
        <x-slot:footer>
            <button type="button" class="ls-btn ls-btn--secondary" data-ls-close>{{ __('app.common.cancel') }}</button>
            <button type="submit" form="migrate-form" id="migrate-submit" class="ls-btn ls-btn--primary">{{ $t('move_confirm') }}</button>
        </x-slot:footer>
    </x-ui.modal>
@endif
@endsection
