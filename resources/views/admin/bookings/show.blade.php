@extends('layouts.admin')

@section('page-title', '#'.$booking->id)
@section('crumb-parent', __('app.nav.bookings'))

@php
    use App\Support\Money;
    $t = fn (string $key, array $r = []) => __('app.admin_platform.'.$key, $r);
    $b = $booking;
    $statusTone = ['completed' => 'ok', 'confirmed' => 'info', 'checked_in' => 'info', 'open' => 'info', 'pending' => 'warn', 'cancelled' => 'danger', 'no_show' => 'neutral'];
    $type = $b->status === 'open' ? 'open' : ($b->sharedSession ? 'session' : ($b->isPackageCovered() ? 'package' : 'booking'));
@endphp

@section('content')
<div class="ls-adm">
    <a href="/admin/bookings" class="ls-biz-back">&larr; {{ __('app.nav.bookings') }}</a>
    <header class="ls-biz-head">
        <div class="ls-biz-id">
            <h1 class="ls-biz-name">{{ $t('booking_n', ['id' => $b->id]) }}
                <x-ui.badge :tone="$statusTone[$b->status] ?? 'neutral'">{{ $t('status.'.$b->status) }}</x-ui.badge>
                <x-ui.badge :tone="$b->paymentStatusTone()" :dot="false">{{ $b->paymentStatusLabel() }}</x-ui.badge>
            </h1>
            <p class="ls-biz-meta">{{ $t('types.'.$type) }} · {{ $t('created_at', ['date' => $b->created_at?->translatedFormat('M j, Y g:i A')]) }}</p>
        </div>
    </header>

    <div class="ls-adm-grid">
        <section class="ls-card">
            <div class="ls-card-head"><h2 class="ls-card-title">{{ $t('reservation') }}</h2></div>
            <div class="ls-card-body">
                <dl class="ls-kv">
                    <dt>{{ $t('col.workspace') }}</dt><dd><a href="/admin/owners/{{ $b->owner_id }}" class="ls-link">{{ $b->owner?->business_name }}</a></dd>
                    <dt>{{ $t('col.location') }}</dt><dd>@if ($b->room?->workspace)<a href="/admin/locations/{{ $b->room->workspace_id }}" class="ls-link">{{ $b->room->workspace->name }}</a>@else — @endif</dd>
                    <dt>{{ $t('col.room') }}</dt><dd>@if ($b->room)<a href="/admin/rooms/{{ $b->room_id }}" class="ls-link">{{ $b->room->name }}</a> · {{ $b->room->typeLabel() }}@else — @endif</dd>
                    <dt>{{ $t('col.date') }}</dt><dd>{{ $b->booking_date?->translatedFormat('l, M j, Y') }}</dd>
                    <dt>{{ $t('time') }}</dt><dd>{{ $b->timeRange() }}@if ($b->total_hours) · {{ $t('hours_n', ['n' => rtrim(rtrim(number_format((float) $b->total_hours, 2), '0'), '.')]) }}@endif</dd>
                    <dt>{{ $t('people') }}</dt><dd>{{ $b->party_size ?? 1 }}@if ($b->checked_in_party_size) · {{ $t('checked_in_n', ['n' => $b->checked_in_party_size]) }}@endif</dd>
                    <dt>{{ $t('col.customer') }}</dt>
                    <dd>{{ $b->hotspotUser?->name ?? __('app.admin_biz.deleted_member') }}@if ($b->hotspotUser?->phone) · <bdi dir="ltr">{{ $b->hotspotUser->phone }}</bdi>@endif</dd>
                    @if ($b->sharedSession)
                        <dt>{{ $t('types.session') }}</dt>
                        <dd>{{ $b->sharedSession->opened_at?->format('g:i A') }} – {{ $b->sharedSession->closed_at?->format('g:i A') ?? '…' }} · {{ $t('minutes_n', ['n' => (int) $b->sharedSession->total_minutes]) }}</dd>
                    @endif
                    @if ($b->notes)<dt>{{ $t('notes') }}</dt><dd>{{ $b->notes }}</dd>@endif
                </dl>
            </div>
        </section>

        <section class="ls-card">
            <div class="ls-card-head"><h2 class="ls-card-title">{{ $t('payment_title') }}</h2></div>
            <div class="ls-card-body ls-stack">
                <dl class="ls-kv">
                    <dt>{{ $t('pricing_used') }}</dt>
                    <dd>{{ $b->plan?->name ?? $b->pricing_profile_name ?? $t('standard_pricing') }}@if ((float) $b->price_per_hour > 0) · {{ Money::format((float) $b->price_per_hour) }}{{ __('app.common.slash_hr') }}@endif</dd>
                    @if ($b->pricing_note)<dt>{{ $t('pricing_note') }}</dt><dd>{{ $b->pricing_note }}</dd>@endif
                    @if ($b->billing_buffer_minutes)<dt>{{ $t('buffer') }}</dt><dd>{{ $t('minutes_n', ['n' => $b->billing_buffer_minutes]) }}</dd>@endif
                    <dt>{{ $t('room_charge') }}</dt><dd class="ls-num">{{ Money::format((float) $b->total_price) }}</dd>
                    @if ((float) $b->discount_total > 0)
                        <dt>{{ $t('coupon') }}@if ($b->coupon) <bdi dir="ltr">({{ $b->coupon->code }})</bdi>@endif</dt><dd class="ls-num">− {{ Money::format((float) $b->discount_total) }}</dd>
                    @endif
                    <dt>{{ $t('col.net') }}</dt><dd class="ls-num">{{ Money::format($b->netRoomCharge()) }}</dd>
                    <dt>{{ $t('col.paid') }}</dt><dd class="ls-num">{{ Money::format((float) $b->amount_paid) }}</dd>
                    <dt>{{ $t('payment_method') }}</dt><dd>{{ $b->payment_method ? (Lang::has('app.admin_platform.methods.'.$b->payment_method) ? $t('methods.'.$b->payment_method) : ucfirst($b->payment_method)) : '—' }}</dd>
                    @if ($b->memberPackage)<dt>{{ $t('hour_package') }}</dt><dd>{{ $b->memberPackage->name }}</dd>@endif
                </dl>
                <div class="ls-total"><span>{{ $t('balance_due') }}</span><b>{{ Money::format($b->isPackageCovered() || in_array($b->status, ['cancelled', 'no_show']) ? 0 : $b->balanceDue()) }}</b></div>
                <p class="ls-faint" style="margin:0;font-size:12.5px">{{ $t('payment_ledger_note') }}</p>
            </div>
        </section>
    </div>

    @if ($b->sale && $b->sale->items->isNotEmpty())
        <section class="ls-card">
            <div class="ls-card-head"><h2 class="ls-card-title">{{ $t('products_sold') }}</h2><span class="ls-faint">{{ $t('status.'.$b->sale->status) }}</span></div>
            <div class="ls-card-body ls-card-body--flush">
                <div class="ls-table-wrap">
                    <table class="ls-table">
                        <thead><tr><th scope="col">{{ $t('col.product') }}</th><th scope="col" class="is-num">{{ $t('qty') }}</th><th scope="col" class="is-num">{{ $t('unit_price') }}</th><th scope="col" class="is-num">{{ $t('line_total') }}</th></tr></thead>
                        <tbody>
                            @foreach ($b->sale->items as $item)
                                <tr><td>{{ $item->name }}</td><td class="is-num">{{ $item->quantity }}</td><td class="is-money">{{ Money::format((float) $item->unit_price) }}</td><td class="is-money">{{ Money::format((float) $item->line_total) }}</td></tr>
                            @endforeach
                        </tbody>
                        <tfoot><tr><th scope="row" colspan="3">{{ $t('sale_total') }}</th><td class="is-money">{{ Money::format((float) $b->sale->total) }}</td></tr></tfoot>
                    </table>
                </div>
            </div>
        </section>
    @endif

    <section class="ls-card">
        <div class="ls-card-head"><h2 class="ls-card-title">{{ $t('activity_title') }}</h2></div>
        <div class="ls-card-body">
            @if ($activity->isEmpty())
                <p class="ls-faint" style="margin:0">{{ $t('no_booking_activity') }}</p>
            @else
                <ol class="ls-biz-feed">
                    @foreach ($activity as $a)
                        <li><time datetime="{{ $a->created_at?->toIso8601String() }}">{{ $a->created_at?->translatedFormat('M j · g:i A') }}</time><span><b>{{ $a->actor_name ?: __('app.admin_biz.staff_member') }}</b> — {{ $a->description ?: $a->action }}</span><span></span></li>
                    @endforeach
                </ol>
            @endif
        </div>
    </section>
</div>
@endsection
