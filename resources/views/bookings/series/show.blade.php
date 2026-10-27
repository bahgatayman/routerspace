@extends('layouts.app')

{{--
    One recurring booking: its rule, every occurrence (ordinary bookings,
    each with its own status/payment/price — opened and edited/cancelled
    individually on the booking page), dates that couldn't be booked with
    other free times, and the "this and later" actions.
    Money (RecurringBookingService::financials()): always summed from the
    dates, so a cancellation shows at once and nothing counts twice; an
    upfront series takes one payment spread over its dates.
    Expects: $series, $bookings, $attention (booking id => reason),
    $unresolved, $upcoming, $money.
--}}
@php
    $staff = auth('staff')->user();
    $can = fn (string $p) => ! $staff || $staff->hasPermission($p);
    $nextBooking = $upcoming->first();
    $moreToCome = $series->isActive() && $series->rule()->hasDatesAfter($series->generated_through?->toDateString());
    $timeOptions = collect(range(0, 47))->map(fn ($i) => sprintf('%02d:%02d', intdiv($i, 2), ($i % 2) * 30));
@endphp

@section('page-title', __('app.series.show_title'))

@section('content')
    <div class="ls-series-page">
        <nav class="ls-wizard-crumbs" aria-label="{{ __('app.workspace.wizard.breadcrumb') }}">
            <a href="/bookings" class="ls-link">{{ __('app.booking.bookings') }}</a>
            <span aria-hidden="true">/</span>
            <a href="{{ route('bookings.series.index') }}" class="ls-link">{{ __('app.series.index_title') }}</a>
        </nav>

        <x-ui.page-header :title="__('app.series.show_title')" :subtitle="$series->hotspotUser?->name.' · '.$series->room?->name">
            <x-slot:actions>
                @if ($series->isActive())
                    <x-ui.badge tone="ok" :dot="true">{{ __('app.series.statuses.active') }}</x-ui.badge>
                @else
                    <x-ui.badge tone="neutral" :dot="false">{{ __('app.series.statuses.stopped') }}</x-ui.badge>
                @endif
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.flash />

        <div class="ls-card ls-series-summary">
            <dl>
                <div><dt>{{ __('app.series.room') }}</dt><dd>{{ $series->room?->workspace?->name }} / {{ $series->room?->name }}@if ($series->pricingProfile) · {{ $series->pricingProfile->name }}@endif</dd></div>
                <div><dt>{{ __('app.series.schedule') }}</dt><dd>{{ $series->frequencyLabel() }}</dd></div>
                <div><dt>{{ __('app.series.time') }}</dt><dd><bdi dir="ltr">{{ $series->timeRange() }}</bdi></dd></div>
                <div><dt>{{ __('app.series.starts') }}</dt><dd>{{ $series->starts_on->translatedFormat('D j M Y') }}</dd></div>
                <div><dt>{{ __('app.series.ends_label') }}</dt><dd>{{ $series->endLabel() }}</dd></div>
                <div><dt>{{ __('app.series.next') }}</dt><dd>{{ $nextBooking ? $nextBooking->booking_date->translatedFormat('D j M') : '—' }} · {{ trans_choice('app.series.upcoming', $upcoming->count(), ['count' => $upcoming->count()]) }}</dd></div>
            </dl>
            @if ($moreToCome)
                <p class="ls-hint">{{ __('app.series.more_to_come') }}</p>
            @endif
        </div>

        <section class="ls-card ls-series-block ls-series-money" aria-labelledby="money-title">
            <div class="ls-card-body">
                <div class="ls-series-money-head">
                    <h2 class="ls-card-title" id="money-title">{{ __('app.series.money_title') }}</h2>
                    <x-ui.badge tone="neutral" :dot="false">{{ __('app.series.plans.'.$series->payment_plan) }}</x-ui.badge>
                </div>
                @if ($series->isUpfront() && $money['remaining'] > 0)
                    <x-ui.banner tone="warn">{{ __('app.series.upfront_unpaid', ['amount' => \App\Support\Money::format($money['remaining'])]) }}</x-ui.banner>
                @endif
                <dl class="ls-series-money-grid">
                    <div><dt>{{ __('app.series.money_total') }}</dt><dd><x-ui.money :amount="$money['total']" /></dd></div>
                    <div><dt>{{ __('app.series.money_paid') }}</dt><dd><x-ui.money :amount="$money['paid']" /></dd></div>
                    <div><dt>{{ __('app.series.money_remaining') }}</dt><dd class="{{ $money['remaining'] > 0 ? 'is-due' : '' }}"><x-ui.money :amount="$money['remaining']" /></dd></div>
                    @if ($series->occurrence_price !== null)
                        <div><dt>{{ __('app.series.agreed_price') }}</dt><dd><x-ui.money :amount="$series->occurrence_price" /></dd></div>
                    @endif
                </dl>
                @if ($money['overpaid'] > 0)
                    <x-ui.banner tone="warn">{{ __('app.series.overpaid', ['amount' => \App\Support\Money::format($money['overpaid'])]) }}</x-ui.banner>
                @endif
                @if ($series->member_package_id || $money['package_dates'] > 0)
                    <p class="ls-series-cover">
                        <x-ui.icon name="clock" />
                        <span>{{ __('app.series.package_status', [
                            'name' => $series->memberPackage?->name ?? '—',
                            'reserved' => \App\Support\Duration::label($money['package_reserved_minutes']),
                            'used' => \App\Support\Duration::label($money['package_used_minutes']),
                        ]) }}</span>
                    </p>
                @endif

                @if ($series->isUpfront() && $money['remaining'] > 0 && $can('bookings.edit'))
                    <form method="POST" action="{{ route('bookings.series.payment', $series) }}" class="ls-series-pay-form">
                        @csrf
                        {{-- One key per page view: a double click records the payment once. --}}
                        <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <div class="ls-field">
                            <label class="ls-label" for="series-pay">{{ __('app.series.pay_label') }}</label>
                            <div class="ls-input-affix"><span aria-hidden="true">{{ app()->getLocale() === 'ar' ? 'ج.م' : 'EGP' }}</span>
                                <input type="number" step="0.01" min="0.01" max="{{ $money['remaining'] }}" id="series-pay" name="amount" class="ls-input" inputmode="decimal" value="{{ number_format($money['remaining'], 2, '.', '') }}" required>
                            </div>
                            <span class="ls-hint">{{ __('app.series.pay_hint') }}</span>
                        </div>
                        <x-ui.button type="submit" variant="primary" size="sm">{{ __('app.series.pay_button') }}</x-ui.button>
                    </form>
                @endif
            </div>
        </section>

        @if ($unresolved->isNotEmpty())
            <section class="ls-card ls-series-block" aria-labelledby="unresolved-title">
                <div class="ls-card-body">
                    <h2 class="ls-card-title" id="unresolved-title">{{ __('app.series.unresolved_title') }}</h2>
                    <p class="ls-hint">{{ __('app.series.unresolved_hint') }}</p>
                    <ul class="ls-series-unresolved">
                        @foreach ($unresolved as $u)
                            <li>
                                <div>
                                    <b>{{ \Carbon\Carbon::parse($u['date'])->translatedFormat('D j M') }}</b>
                                    <span class="ls-faint"><bdi dir="ltr">{{ $u['start'] }} – {{ $u['end'] }}</bdi></span>
                                    <x-ui.badge tone="warn" :dot="false">{{ __('app.series.reasons.'.$u['reason']) }}</x-ui.badge>
                                </div>
                                <div class="ls-series-unresolved-actions">
                                    @if ($can('bookings.create'))
                                        @forelse ($u['suggestions'] as $s)
                                            <form method="POST" action="{{ route('bookings.series.occurrences.store', $series) }}">
                                                @csrf
                                                <input type="hidden" name="date" value="{{ $u['date'] }}">
                                                <input type="hidden" name="start_time" value="{{ $s['start'] }}">
                                                <input type="hidden" name="end_time" value="{{ $s['end'] }}">
                                                <button type="submit" class="ls-btn ls-btn--tonal ls-btn--sm">{{ __('app.series.book_time', ['time' => $s['start'].'–'.$s['end']]) }} · <x-ui.money :amount="$s['price']" /></button>
                                            </form>
                                        @empty
                                            <span class="ls-hint">{{ __('app.series.no_other_time') }}</span>
                                        @endforelse
                                    @endif
                                    @if ($can('bookings.edit'))
                                        <form method="POST" action="{{ route('bookings.series.dismiss', $series) }}">
                                            @csrf
                                            <input type="hidden" name="date" value="{{ $u['date'] }}">
                                            <button type="submit" class="ls-btn ls-btn--ghost ls-btn--sm">{{ __('app.series.skip_date') }}</button>
                                        </form>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </section>
        @endif

        <section class="ls-card ls-series-block" aria-labelledby="occurrences-title">
            <div class="ls-card-body">
                <h2 class="ls-card-title" id="occurrences-title">{{ __('app.series.occurrences') }}</h2>
                @if ($bookings->isEmpty())
                    <p class="ls-hint">{{ __('app.series.no_occurrences') }}</p>
                @else
                    <ul class="ls-series-occurrences">
                        @foreach ($bookings as $b)
                            <li class="{{ $b->booking_date->lt(today()) ? 'is-past' : '' }}">
                                <div class="ls-series-occ-when">
                                    <b>{{ $b->booking_date->translatedFormat('D j M Y') }}</b>
                                    {{-- 24-hour, like the series' own time above (Booking::timeRange() is the shared 12-hour format). --}}
                                    <span class="ls-faint"><bdi dir="ltr">{{ substr((string) $b->start_time, 0, 5) }} – {{ substr((string) $b->end_time, 0, 5) }}</bdi></span>
                                </div>
                                <div class="ls-series-occ-tags">
                                    <span class="inline-block px-2 py-0.5 rounded text-[11px] font-medium {{ $b->statusBadgeClass() }}">{{ \Illuminate\Support\Facades\Lang::has('app.series.booking_status.'.$b->status) ? __('app.series.booking_status.'.$b->status) : $b->statusLabel() }}</span>
                                    <x-ui.badge :tone="$b->paymentStatusTone()" :dot="false">{{ $b->paymentStatusLabel() }}</x-ui.badge>
                                    @if (isset($attention[$b->id]))
                                        <x-ui.badge tone="danger" :dot="false">{{ __('app.series.needs_attention') }}: {{ __('app.series.reasons.'.$attention[$b->id]) }}</x-ui.badge>
                                    @endif
                                </div>
                                <x-ui.money :amount="$b->total_price" class="ls-series-occ-price" />
                                <a href="/bookings/{{ $b->id }}" class="ls-link">{{ __('app.series.open_booking') }}</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>

        @if ($upcoming->isNotEmpty() && ($can('bookings.edit') || $can('bookings.cancel')))
            <section class="ls-card ls-series-block" aria-labelledby="actions-title">
                <div class="ls-card-body ls-series-manage">
                    <h2 class="ls-card-title" id="actions-title">{{ __('app.series.actions_title') }}</h2>

                    @if ($can('bookings.edit'))
                        <form method="POST" action="{{ route('bookings.series.future.update', $series) }}" class="ls-series-form">
                            @csrf
                            <h3>{{ __('app.series.change_future') }}</h3>
                            <p class="ls-hint">{{ __('app.series.change_future_hint') }}</p>
                            <div class="ls-series-fields">
                                <div class="ls-field">
                                    <label class="ls-label" for="future-from">{{ __('app.series.from') }}</label>
                                    <select name="from_booking_id" id="future-from" class="ls-select">
                                        @foreach ($upcoming as $b)
                                            <option value="{{ $b->id }}">{{ $b->booking_date->translatedFormat('D j M') }} · {{ substr((string) $b->start_time, 0, 5) }}–{{ substr((string) $b->end_time, 0, 5) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="ls-field">
                                    <label class="ls-label" for="future-start">{{ __('app.series.start_time') }}</label>
                                    <select name="start_time" id="future-start" class="ls-select">
                                        @foreach ($timeOptions as $t)
                                            <option value="{{ $t }}" @selected($t === substr((string) $series->start_time, 0, 5))>{{ $t }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="ls-field">
                                    <label class="ls-label" for="future-end">{{ __('app.series.end_time') }}</label>
                                    <select name="end_time" id="future-end" class="ls-select">
                                        @foreach ($timeOptions->push('23:59') as $t)
                                            <option value="{{ $t }}" @selected($t === substr((string) $series->end_time, 0, 5))>{{ $t }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="ls-field">
                                    <label class="ls-label" for="future-party">{{ __('app.series.party') }}</label>
                                    @if ($series->room?->isShared())
                                        <input type="number" name="party_size" id="future-party" class="ls-input" min="1" max="999" value="{{ $series->party_size }}">
                                    @else
                                        <input type="number" name="guest_count" id="future-party" class="ls-input" min="1" max="999" value="{{ $series->guest_count ?? 1 }}">
                                    @endif
                                </div>
                            </div>
                            <x-ui.button type="submit" variant="primary" size="sm">{{ __('app.series.apply') }}</x-ui.button>
                        </form>

                        {{-- Price per date: shows the money impact first (bookings.series.price.preview). --}}
                        <form method="POST" action="{{ route('bookings.series.price.preview', $series) }}" class="ls-series-form">
                            @csrf
                            <h3>{{ __('app.series.change_price') }}</h3>
                            <p class="ls-hint">{{ __('app.series.change_price_hint') }}</p>
                            <div class="ls-series-fields">
                                <div class="ls-field">
                                    <label class="ls-label" for="series-price">{{ __('app.series.price_per_date') }}</label>
                                    <div class="ls-input-affix"><span aria-hidden="true">{{ app()->getLocale() === 'ar' ? 'ج.م' : 'EGP' }}</span>
                                        <input type="number" step="0.01" min="0" id="series-price" name="price" class="ls-input" inputmode="decimal" required
                                               value="{{ $series->occurrence_price !== null ? number_format((float) $series->occurrence_price, 2, '.', '') : '' }}">
                                    </div>
                                </div>
                            </div>
                            <x-ui.button type="submit" variant="secondary" size="sm">{{ __('app.series.review_price') }}</x-ui.button>
                        </form>
                    @endif

                    @if ($can('bookings.cancel'))
                        <form method="POST" action="{{ route('bookings.series.future.cancel', $series) }}" class="ls-series-form"
                              onsubmit="return confirm(@js(__('app.series.cancel_confirm')))">
                            @csrf
                            <h3>{{ __('app.series.cancel_future') }}</h3>
                            <p class="ls-hint">{{ __('app.series.cancel_future_hint') }}</p>
                            <div class="ls-series-fields">
                                <div class="ls-field">
                                    <label class="ls-label" for="cancel-from">{{ __('app.series.from') }}</label>
                                    <select name="from_booking_id" id="cancel-from" class="ls-select">
                                        @foreach ($upcoming as $b)
                                            <option value="{{ $b->id }}">{{ $b->booking_date->translatedFormat('D j M') }} · {{ substr((string) $b->start_time, 0, 5) }}–{{ substr((string) $b->end_time, 0, 5) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <x-ui.button type="submit" variant="danger-quiet" size="sm">{{ __('app.series.cancel_button') }}</x-ui.button>
                        </form>
                    @endif
                </div>
            </section>
        @endif

        @if ($series->isActive() && $can('bookings.edit'))
            <form method="POST" action="{{ route('bookings.series.stop', $series) }}" class="ls-series-stop" onsubmit="return confirm(@js(__('app.series.stop_confirm')))">
                @csrf
                <p class="ls-hint">{{ __('app.series.stop_hint') }}</p>
                <x-ui.button type="submit" variant="secondary" size="sm" icon="power">{{ __('app.series.stop') }}</x-ui.button>
            </form>
        @endif
    </div>
@endsection
