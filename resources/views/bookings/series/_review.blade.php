{{--
    Step 2 of a repeating booking — review and confirm. One partial for both
    places it appears: the review page (full booking form) and step 2 of the
    quick-booking modal (fetched as a fragment, RecurringBookingController::
    review() with X-LS-Fragment). Nothing is held here: confirming re-checks
    every date under the room lock (RecurringBookingService).

    Order: who/what/when → the numbers → the dates (a compact list; dates that
    can't be booked are always shown) → how it's paid → hour package → a fixed
    footer with the amount and the one confirm button. Behaviour:
    LSSeriesReview.init() (bookings.series._review-script).

    Expects: $v (validated input), $draft, $preview, $requestToken, $packages,
    $templates, $canSell.
--}}
@php
    $rule = $draft['rule'];
    $label = new \App\Models\BookingSeries([
        'frequency' => $rule->frequency,
        'weekdays' => $rule->weekdays,
        'occurrence_limit' => $rule->count,
        'ends_on' => $rule->endsOn?->toDateString(),
        'start_time' => $draft['start'],
        'end_time' => $draft['end'],
    ]);
    $occ = $preview['occurrences'];
    $lastDate = $occ === [] ? null : end($occ)['date'];
    $money = fn ($n) => app()->getLocale() === 'ar' ? number_format((float) $n, 2).' ج.م' : 'EGP '.number_format((float) $n, 2);
    $selected = collect($occ)->whereNull('reason')->count();
    $conflicts = count($occ) - $selected;
    $pkg = $preview['package'];
    $plan = $v['payment_plan'] ?? \App\Models\BookingSeries::PLAN_PER_SESSION;
    $hoursLabel = fn (int $minutes) => \App\Support\Duration::label($minutes);
    $showPackages = ! $draft['room']->isShared() && ($packages->isNotEmpty() || $canSell);
    // The first date the rule makes (the first chosen weekday on or after the start date).
    $firstDate = $occ === [] ? null : \Carbon\Carbon::parse($occ[0]['date']);
    // What one date costs — the price this series is agreed at when confirmed.
    $agreedPrice = collect($occ)->firstWhere('reason', null)['price'] ?? null;
    // Long series: the first few dates, then "show all" — a date that can't be booked is always listed.
    $shown = 6;
@endphp
<div class="ls-series-review" data-series-review-root>
    {{-- As a fragment there is no page flash: say "package sold" here. --}}
    @if (session('success') && request()->header('X-LS-Fragment'))
        <div class="ls-banner ls-banner--ok" role="status"><x-ui.icon name="check-circle" /><span>{{ session('success') }}</span></div>
    @endif
    <div class="ls-banner ls-banner--danger" role="alert" data-review-error hidden><x-ui.icon name="alert" /><span></span></div>

    <form method="POST" action="{{ route('bookings.series.store') }}" data-series-review
          data-review-url="{{ route('bookings.series.review') }}" data-sell-url="{{ route('bookings.series.review.package') }}">
        @csrf
        <input type="hidden" name="request_token" value="{{ $requestToken }}">
        @foreach (['room_id', 'hotspot_user_id', 'booking_date', 'start_time', 'end_time', 'party_size', 'guest_count', 'room_pricing_profile_id', 'notes', 'repeat_frequency', 'repeat_end', 'repeat_count', 'repeat_until'] as $field)
            @if (isset($v[$field]) && $v[$field] !== '')
                <input type="hidden" name="{{ $field }}" value="{{ $v[$field] }}">
            @endif
        @endforeach
        @foreach ($v['repeat_weekdays'] ?? [] as $d)
            <input type="hidden" name="repeat_weekdays[]" value="{{ $d }}">
        @endforeach

        {{-- 1 · What is being booked, in two lines. --}}
        <section class="ls-series-recap" aria-label="{{ __('app.series.review_title') }}">
            <p><b>{{ $draft['member']->name }}</b> · {{ $draft['room']->workspace?->name }} / {{ $draft['room']->name }}@if ($draft['profile']) · {{ $draft['profile']->name }}@endif</p>
            <p>{{ $label->frequencyLabel() }} · <bdi dir="ltr">{{ $label->timeRange() }}</bdi> · {{ $label->endLabel() }}</p>
            <p class="ls-faint">{{ __('app.series.first_date') }}: <b>{{ $firstDate?->translatedFormat('D j M Y') ?? '—' }}</b>@if ($lastDate && $lastDate !== $occ[0]['date']) → {{ \Carbon\Carbon::parse($lastDate)->translatedFormat('D j M Y') }}@endif</p>
            @if ($firstDate && ! $firstDate->isSameDay($rule->startsOn))
                <p class="ls-repeat-shift">{{ __('app.series.summary_shift', ['date' => $firstDate->translatedFormat('D j M Y')]) }}</p>
            @endif
        </section>

        {{-- 2 · The numbers (live as dates are skipped or moved). --}}
        <dl class="ls-series-metrics" aria-live="polite">
            <div><dt>{{ __('app.series.dates_label') }}</dt><dd data-sum-count>{{ $selected }}</dd></div>
            <div><dt>{{ __('app.series.total_time') }}</dt><dd data-sum-hours>{{ $hoursLabel($preview['minutes']) }}</dd></div>
            <div><dt>{{ __('app.series.per_date_price') }}</dt><dd>{{ $agreedPrice === null ? '—' : $money($agreedPrice) }}</dd></div>
            @if ($pkg)
                <div><dt>{{ __('app.series.from_package') }}</dt><dd data-sum-package>{{ $hoursLabel($pkg['covered_minutes']) }}</dd></div>
            @endif
            <div class="is-total"><dt data-sum-total-label>{{ __('app.series.to_pay.'.$plan) }}</dt><dd data-sum-total>{{ $money($preview['total']) }}</dd></div>
        </dl>
        <p class="ls-hint ls-series-fineprint">{{ __('app.series.not_a_hold') }} {{ __('app.series.price_note') }}</p>

        {{-- 3 · The dates: quiet when free, marked when not; the rest behind "show all". --}}
        <section class="ls-series-appts" aria-labelledby="appts-title">
            <div class="ls-series-appts-head">
                <h3 id="appts-title">{{ __('app.series.appointments') }}</h3>
                @if ($conflicts)
                    <x-ui.badge tone="warn" :dot="false">{{ trans_choice('app.series.conflicts_count', $conflicts, ['count' => $conflicts]) }}</x-ui.badge>
                @endif
            </div>
            <ol class="ls-series-dates" aria-label="{{ __('app.series.occurrences') }}">
                @foreach ($occ as $i => $o)
                    @php
                        $date = \Carbon\Carbon::parse($o['date']);
                        $ok = $o['reason'] === null;
                        $selectId = 'occ-'.$i;
                    @endphp
                    <li class="ls-series-date {{ $ok ? '' : 'is-conflict' }}" data-minutes="{{ $o['minutes'] }}" data-pkg-ok="{{ $o['pkg_ok'] ? 1 : 0 }}"
                        @if ($ok && $i >= $shown) data-extra hidden @endif>
                        <input type="hidden" name="occurrences[{{ $i }}][date]" value="{{ $o['date'] }}">
                        <div class="ls-series-date-when">
                            <b>{{ $date->translatedFormat('D j M') }}</b>
                            <span class="ls-faint"><bdi dir="ltr">{{ $o['start'] }} – {{ $o['end'] }}</bdi></span>
                        </div>
                        <div class="ls-series-date-status">
                            @if ($ok)
                                <span class="ls-faint">{{ __('app.series.available') }}</span>
                            @else
                                <x-ui.badge tone="warn" :dot="false">{{ __('app.series.reasons.'.$o['reason']) }}</x-ui.badge>
                            @endif
                        </div>
                        <div class="ls-series-date-choice">
                            <label class="ls-sr" for="{{ $selectId }}">{{ __('app.series.choice') }} — {{ $date->translatedFormat('D j M') }}</label>
                            <select name="occurrences[{{ $i }}][choice]" id="{{ $selectId }}" class="ls-select" data-occurrence>
                                @if ($ok)
                                    <option value="{{ $o['start'] }}-{{ $o['end'] }}" data-price="{{ $o['price'] }}" selected>{{ __('app.series.book_at', ['time' => $o['start'].'–'.$o['end'], 'price' => $money($o['price'])]) }}</option>
                                @endif
                                <option value="skip" data-price="0" @selected(! $ok)>{{ __('app.series.skip') }}</option>
                                @foreach ($o['suggestions'] as $s)
                                    <option value="{{ $s['start'] }}-{{ $s['end'] }}" data-price="{{ $s['price'] }}">{{ __('app.series.other_time', ['time' => $s['start'].'–'.$s['end'], 'price' => $money($s['price'])]) }}</option>
                                @endforeach
                            </select>
                            @if (! $ok && $o['suggestions'] === [] && in_array($o['reason'], ['booked', 'closed'], true))
                                <p class="ls-hint">{{ __('app.series.no_other_time') }}</p>
                            @endif
                        </div>
                        <div class="ls-series-date-price ls-num" data-price-cell>{{ ! $ok ? '—' : ($o['covered'] ? __('app.series.by_package') : $money($o['price'])) }}</div>
                    </li>
                @endforeach
            </ol>
            @if (collect($occ)->keys()->filter(fn ($i) => $i >= $shown && $occ[$i]['reason'] === null)->isNotEmpty())
                <button type="button" class="ls-link ls-series-more-btn" data-show-all aria-expanded="false"
                        data-more="{{ trans_choice('app.series.show_all', count($occ), ['count' => count($occ)]) }}" data-less="{{ __('app.series.show_fewer') }}">
                    {{ trans_choice('app.series.show_all', count($occ), ['count' => count($occ)]) }}
                </button>
            @endif
            @if ($preview['more'] && $lastDate)
                <p class="ls-hint ls-series-more">{{ __('app.series.more_note', ['date' => \Carbon\Carbon::parse($lastDate)->translatedFormat('j M Y')]) }}</p>
            @endif
        </section>

        {{-- 4 · How the customer pays. --}}
        <fieldset class="ls-series-pay-plan">
            <legend>{{ __('app.series.plan_label') }}</legend>
            <div class="ls-series-plans">
                @foreach (\App\Models\BookingSeries::PLANS as $p)
                    <label class="ls-pay-choice">
                        <input type="radio" name="payment_plan" value="{{ $p }}" @checked($plan === $p)>
                        <span class="ls-pay-choice-body">
                            <span class="ls-pay-choice-title">{{ __('app.series.plans.'.$p) }}</span>
                            <small>{{ __('app.series.plans_hint.'.$p) }}</small>
                        </span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        {{-- 5 · Optional hour package (or sell one here). --}}
        @if ($showPackages)
            <section class="ls-series-pkg" aria-labelledby="pkg-title">
                <label class="ls-label" id="pkg-title" for="series-package">{{ __('app.series.package_label') }}</label>
                <select name="series_package_id" id="series-package" class="ls-select" data-package-select>
                    <option value="">{{ __('app.series.package_none') }}</option>
                    @foreach ($packages as $p)
                        <option value="{{ $p->id }}" @selected((string) ($v['series_package_id'] ?? '') === (string) $p->id)>
                            {{ __('app.series.package_option', ['name' => $p->name, 'left' => $hoursLabel($p->remainingMinutes()), 'date' => $p->expires_on->translatedFormat('j M Y')]) }}
                        </option>
                    @endforeach
                </select>
                {{-- Without script: re-review with the chosen package (coverage is worked out per date by the server). --}}
                <noscript><button type="submit" formaction="{{ route('bookings.series.review') }}" formnovalidate class="ls-btn ls-btn--secondary ls-btn--sm">{{ __('app.series.package_apply') }}</button></noscript>
                @if ($pkg)
                    {{-- What this review shows as covered — if confirming finds less, the owner is told. --}}
                    <input type="hidden" name="package_dates" value="{{ collect($occ)->where('covered', true)->pluck('date')->implode(',') }}" data-package-dates>
                    <p class="ls-series-cover" data-package-line data-left="{{ $pkg['remaining_minutes'] }}">
                        <x-ui.icon name="clock" />
                        <span data-package-cover>{{ __('app.series.package_cover', ['hours' => $hoursLabel($pkg['covered_minutes']), 'left' => $hoursLabel($pkg['remaining_minutes'] - $pkg['covered_minutes'])]) }}</span>
                    </p>
                @else
                    <span class="ls-hint">{{ __('app.series.package_hint') }}</span>
                @endif

                @if ($canSell)
                    <details class="ls-series-sell" @if ($errors->get('sale.*')) open @endif>
                        <summary>{{ __('app.series.sell_title') }}</summary>
                        <p class="ls-hint">{{ __('app.series.sell_hint') }}</p>
                        @if ($templates->isNotEmpty())
                            <div class="ls-field">
                                <label class="ls-label" for="sale-template">{{ __('app.packages.choose_template') }}</label>
                                <select id="sale-template" name="sale[package_template_id]" class="ls-select" data-sale-template>
                                    <option value="">{{ __('app.packages.custom') }}</option>
                                    @foreach ($templates as $t)
                                        <option value="{{ $t->id }}" data-name="{{ $t->name }}" data-hours="{{ rtrim(rtrim(number_format($t->total_minutes / 60, 2, '.', ''), '0'), '.') }}"
                                                data-price="{{ $t->price }}" data-days="{{ $t->validity_days }}">{{ $t->name }} · {{ $t->hoursLabel() }} · {{ $money($t->price) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <div class="ls-pkg-form-row">
                            <div class="ls-field"><label class="ls-label" for="sale-name">{{ __('app.packages.name') }}</label>
                                <input id="sale-name" name="sale[name]" class="ls-input" maxlength="80" value="{{ old('sale.name', request('sale.name')) }}" placeholder="{{ __('app.packages.name_ph') }}"></div>
                            <div class="ls-field"><label class="ls-label" for="sale-hours">{{ __('app.packages.hours') }}</label>
                                <input id="sale-hours" name="sale[hours]" type="number" step="0.25" min="0.25" inputmode="decimal" class="ls-input" value="{{ old('sale.hours', request('sale.hours')) }}"></div>
                        </div>
                        <div class="ls-pkg-form-row">
                            <div class="ls-field"><label class="ls-label" for="sale-price">{{ __('app.packages.price_paid') }}</label>
                                <input id="sale-price" name="sale[price_paid]" type="number" step="0.01" min="0" inputmode="decimal" class="ls-input" value="{{ old('sale.price_paid', request('sale.price_paid')) }}"></div>
                            <div class="ls-field"><label class="ls-label" for="sale-starts">{{ __('app.packages.starts_on') }}</label>
                                <input id="sale-starts" name="sale[starts_on]" type="date" class="ls-input" value="{{ old('sale.starts_on', request('sale.starts_on', $rule->startsOn->toDateString())) }}"></div>
                            <div class="ls-field"><label class="ls-label" for="sale-expires">{{ __('app.packages.expires_on') }}</label>
                                <input id="sale-expires" name="sale[expires_on]" type="date" class="ls-input" value="{{ old('sale.expires_on', request('sale.expires_on', $rule->startsOn->addDays(29)->toDateString())) }}"></div>
                        </div>
                        @foreach (['sale.name', 'sale.hours', 'sale.price_paid', 'sale.starts_on', 'sale.expires_on', 'sale.package_template_id'] as $field)
                            @error($field) <span class="ls-error"><x-ui.icon name="alert" />{{ $message }}</span> @enderror
                        @endforeach
                        <button type="submit" formaction="{{ route('bookings.series.review.package') }}" formnovalidate class="ls-btn ls-btn--tonal ls-btn--sm" data-sell>{{ __('app.series.sell_button') }}</button>
                    </details>
                @endif
            </section>
        @elseif ($draft['room']->isShared())
            <p class="ls-hint">{{ __('app.packages.reasons.shared_booking') }}</p>
        @endif

        {{-- 6 · The amount and the one confirm button — always on screen. --}}
        <div class="ls-series-foot">
            <div class="ls-series-total">
                <span class="ls-hint"><span data-selected-count>{{ trans_choice('app.series.selected_count', $selected, ['count' => $selected]) }}</span> · <span data-hours>{{ $hoursLabel($preview['minutes']) }}</span></span>
                <span><span class="ls-faint" data-total-label>{{ __('app.series.to_pay.'.$plan) }}</span> <b class="ls-num" data-total>{{ $money($preview['total']) }}</b></span>
            </div>
            <div class="ls-series-actions">
                <a href="/bookings/create" class="ls-btn ls-btn--secondary" data-back>{{ __('app.series.back_to_details') }}</a>
                <button type="submit" class="ls-btn ls-btn--primary" data-confirm @disabled($selected === 0)>
                    <x-ui.icon name="check" /><span>{{ trans_choice('app.series.confirm', $selected, ['count' => $selected]) }}</span>
                </button>
            </div>
        </div>
    </form>
</div>
