@extends('layouts.admin')

@section('page-title', __('app.nav.plans'))

@php
    use App\Support\Money;
    $t = fn (string $key, array $r = []) => __('app.admin_platform.'.$key, $r);
    $limit = fn ($v) => (int) $v > 0 ? number_format($v) : $t('unlimited');
@endphp

@section('content')
<div class="ls-adm">
    <header class="ls-page-head ls-adm-head">
        <div>
            <h1 class="ls-title">{{ __('app.nav.plans') }} <span class="ls-count">{{ $plans->count() }}</span></h1>
            <p class="ls-subtitle">{{ $t('plans_sub') }}</p>
        </div>
        <div class="ls-actions"><x-ui.button variant="primary" icon="plus" href="/admin/plans/create">{{ __('app.btn.add_plan') }}</x-ui.button></div>
    </header>

    @if ($plans->isEmpty())
        <section class="ls-card"><x-ui.empty-state :title="$t('no_plans')"><x-ui.button variant="primary" href="/admin/plans/create">{{ __('app.btn.add_plan') }}</x-ui.button></x-ui.empty-state></section>
    @else
        <div class="ls-plan-grid">
            @foreach ($plans as $plan)
                @php $used = $plan->owners_count + $plan->subscriptions_count + ($requests[$plan->id] ?? 0) > 0; @endphp
                <article class="ls-card ls-plan-card {{ $plan->is_active ? '' : 'is-off' }}" data-plan="{{ $plan->id }}">
                    <header class="ls-plan-card-head">
                        <div>
                            <h2 class="ls-plan-name"><a href="/admin/plans/{{ $plan->id }}">{{ $plan->name }}</a></h2>
                            <span class="ls-faint ls-num" dir="ltr">{{ $plan->slug }}</span>
                        </div>
                        @if ($plan->is_active)<x-ui.badge tone="ok">{{ __('app.status.active') }}</x-ui.badge>@else<x-ui.badge tone="neutral">{{ __('app.status.inactive') }}</x-ui.badge>@endif
                    </header>
                    <p class="ls-plan-price"><b>{{ Money::format((float) $plan->price_per_month) }}</b><span>/ {{ $t('month') }}</span></p>
                    <dl class="ls-plan-usage">
                        <div><dt>{{ $t('chart.active_workspaces') }}</dt><dd>{{ number_format($plan->active_owners_count) }}<small> / {{ number_format($plan->owners_count) }}</small></dd></div>
                        <div><dt>{{ $t('kpi.mrr') }}</dt><dd>{{ Money::format($plan->active_owners_count * (float) $plan->price_per_month) }}</dd></div>
                        <div><dt>{{ $t('lifetime_revenue') }}</dt><dd>{{ Money::format((float) $plan->revenue) }}</dd></div>
                    </dl>
                    <ul class="ls-plan-limits">
                        <li><span>{{ __('app.plan.max_members') }}</span><b>{{ $limit($plan->max_members) }}</b></li>
                        <li><span>{{ __('app.plan.max_workspaces') }}</span><b>{{ $limit($plan->max_workspaces) }}</b></li>
                        <li><span>{{ __('app.plan.max_rooms') }}</span><b>{{ $limit($plan->max_rooms) }}</b></li>
                        <li><span>{{ __('app.plan.max_products') }}</span><b>{{ $limit($plan->max_products) }}</b></li>
                    </ul>
                    @if (! empty($plan->features))
                        <p class="ls-plan-features">@foreach ($plan->features as $f)<span>{{ $featureNames[$f] ?? $f }}</span>@endforeach</p>
                    @endif
                    <footer class="ls-plan-actions">
                        <x-ui.button size="sm" variant="tonal" :href="'/admin/plans/'.$plan->id">{{ $t('details') }}</x-ui.button>
                        <x-ui.button size="sm" variant="ghost" :href="'/admin/plans/'.$plan->id.'/edit'">{{ __('app.common.edit') }}</x-ui.button>
                        <form method="POST" action="/admin/plans/{{ $plan->id }}/toggle" data-confirm="{{ $plan->is_active ? $t('confirm_disable_plan', ['plan' => $plan->name]) : $t('confirm_enable_plan', ['plan' => $plan->name]) }}" data-confirm-tone="{{ $plan->is_active ? 'danger' : 'primary' }}" data-confirm-label="{{ $plan->is_active ? __('app.btn.disable') : __('app.btn.enable') }}">
                            @csrf
                            <button type="submit" class="ls-btn ls-btn--ghost ls-btn--sm">{{ $plan->is_active ? __('app.btn.disable') : __('app.btn.enable') }}</button>
                        </form>
                        @unless ($used)
                            <form method="POST" action="/admin/plans/{{ $plan->id }}" data-confirm="{{ $t('confirm_delete_plan', ['plan' => $plan->name]) }}" data-confirm-label="{{ __('app.common.delete') }}">
                                @csrf @method('DELETE')
                                <input type="hidden" name="reason" value="">
                                <button type="submit" class="ls-btn ls-btn--danger-quiet ls-btn--sm">{{ __('app.common.delete') }}</button>
                            </form>
                        @endunless
                    </footer>
                </article>
            @endforeach
        </div>
    @endif
</div>
@endsection
