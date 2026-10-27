{{--
    "Repeat this booking" — the toggle and the repeat rule, shared by the full
    booking form (bookings._repeat) and the quick-booking modal
    (bookings._quick-modal). Only markup: each caller wires its own behaviour,
    and both post to the one recurring review step (bookings.series.review).

    $rp          id prefix (both may be on one page: /bookings/create also has the modal)
    $useOld      refill from old() input (the full form after a validation error)
    $withReview  the full form's own "Review dates" submit button
    $class       the section's class (each caller's section style)
--}}
@php
    $rp ??= 'repeat';
    $useOld ??= true;
    $withReview ??= true;
    $old = fn (string $key, $default = null) => $useOld ? old($key, $default) : $default;
    $repeatOn = (bool) $old('repeat');
    $frequency = $old('repeat_frequency', 'weekly');
    $weekdays = array_map('intval', (array) $old('repeat_weekdays', []));
    $end = $old('repeat_end', 'count');
    // Saturday first, as the week runs here.
    $weekOrder = [6, 0, 1, 2, 3, 4, 5];
@endphp
<section class="{{ $class ?? 'ls-plain-section' }} ls-repeat" id="{{ $rp }}-section" aria-labelledby="{{ $rp }}-title">
    {{-- A switch (role="switch": announced on/off), not a plain checkbox. --}}
    <label class="ls-repeat-toggle">
        <input type="checkbox" role="switch" class="ls-switch" name="repeat" value="1" id="{{ $rp }}-toggle" @checked($repeatOn) aria-controls="{{ $rp }}-fields">
        <span>
            <b id="{{ $rp }}-title">{{ __('app.series.repeat') }}</b>
            <small>{{ __('app.series.repeat_hint') }}</small>
        </span>
    </label>

    {{-- Why a confirmed review came back here (dates taken meanwhile, none chosen, changed). --}}
    @if ($useOld) @error('occurrences') <x-ui.banner tone="danger">{{ $message }}</x-ui.banner> @enderror @endif

    <div class="ls-repeat-fields" id="{{ $rp }}-fields" @unless ($repeatOn) hidden @endunless>
        <fieldset class="ls-field">
            <legend class="ls-label">{{ __('app.series.frequency') }}</legend>
            <div class="ls-chips" role="radiogroup">
                @foreach (['daily', 'weekly'] as $f)
                    <label class="ls-chip ls-repeat-chip">
                        <input type="radio" name="repeat_frequency" value="{{ $f }}" @checked($frequency === $f)>
                        <span>{{ __('app.series.'.$f) }}</span>
                    </label>
                @endforeach
            </div>
            @if ($useOld) @error('repeat_frequency') <span class="ls-error"><x-ui.icon name="alert" />{{ $message }}</span> @enderror @endif
        </fieldset>

        <fieldset class="ls-field ls-repeat-weekdays" id="{{ $rp }}-weekdays" @if ($frequency !== 'weekly') hidden @endif>
            <legend class="ls-label">{{ __('app.series.on_days') }}</legend>
            <div class="ls-chips">
                @foreach ($weekOrder as $d)
                    <label class="ls-chip ls-repeat-chip">
                        <input type="checkbox" name="repeat_weekdays[]" value="{{ $d }}" @checked(in_array($d, $weekdays, true))>
                        <span>{{ __('app.series.weekdays_short.'.$d) }}</span>
                    </label>
                @endforeach
            </div>
            @if ($useOld) @error('repeat_weekdays') <span class="ls-error"><x-ui.icon name="alert" />{{ $message }}</span> @enderror @endif
        </fieldset>

        <fieldset class="ls-field">
            <legend class="ls-label">{{ __('app.series.ends') }}</legend>
            <div class="ls-repeat-end">
                <label class="ls-repeat-end-opt">
                    <input type="radio" name="repeat_end" value="count" @checked($end === 'count')>
                    <span>{{ __('app.series.end_count') }}</span>
                    <input type="number" name="repeat_count" class="ls-input" min="2" max="{{ \App\Support\Recurrence\RecurrenceRule::MAX_COUNT }}" step="1" inputmode="numeric"
                           value="{{ $old('repeat_count', 4) }}" aria-label="{{ __('app.series.end_count') }} ({{ __('app.series.end_count_unit') }})">
                    <span>{{ __('app.series.end_count_unit') }}</span>
                </label>
                <label class="ls-repeat-end-opt">
                    <input type="radio" name="repeat_end" value="date" @checked($end === 'date')>
                    <span>{{ __('app.series.end_date') }}</span>
                    <input type="date" name="repeat_until" class="ls-input" value="{{ $old('repeat_until') }}" aria-label="{{ __('app.series.end_date') }}">
                </label>
            </div>
            @if ($useOld)
                @error('repeat_count') <span class="ls-error"><x-ui.icon name="alert" />{{ $message }}</span> @enderror
                @error('repeat_until') <span class="ls-error"><x-ui.icon name="alert" />{{ $message }}</span> @enderror
            @endif
        </fieldset>

        {{-- In words: the pattern, how many dates, the first and last one (LSRepeat.summary()).
             "Done" folds the settings away and leaves just this line; "Edit" opens them again. --}}
        <div class="ls-repeat-summary-row">
            <p class="ls-repeat-summary" id="{{ $rp }}-summary" aria-live="polite"></p>
            <button type="button" class="ls-btn ls-btn--ghost ls-btn--sm" data-repeat-collapse aria-expanded="true" aria-controls="{{ $rp }}-fields"
                    data-done="{{ __('app.series.repeat_done') }}" data-edit="{{ __('app.series.repeat_edit') }}">{{ __('app.series.repeat_done') }}</button>
        </div>

        <p class="ls-hint">{{ __('app.series.payment_note') }}</p>
        @if ($withReview)
            <button type="submit" formaction="{{ route('bookings.series.review') }}" formnovalidate class="ls-btn ls-btn--primary ls-btn--block" id="{{ $rp }}-review">
                <x-ui.icon name="calendar" /><span>{{ __('app.series.review_button') }}</span>
            </button>
        @endif
    </div>
</section>

@once
    @php
        $repeatPlural = fn (string $key) => [
            1 => trans_choice($key, 1, ['count' => 1]), 2 => trans_choice($key, 2, ['count' => 2]),
            'few' => trans_choice($key, 3, ['count' => ':count']), 'many' => trans_choice($key, 11, ['count' => ':count']),
        ];
        $repeatI18n = [
            'locale' => app()->getLocale() === 'ar' ? 'ar-EG-u-nu-latn' : 'en-GB',
            'days' => collect(range(0, 6))->mapWithKeys(fn ($d) => [$d => __('app.series.weekdays_short.'.$d)])->all(),
            'everyDay' => __('app.series.every_day'),
            'everyDays' => __('app.series.every_weekdays', ['days' => ':days']),
            'sep' => __('app.series.list_separator'),
            'count' => $repeatPlural('app.series.ends_after'),
            'until' => __('app.series.ends_on', ['date' => ':date']),
            'first' => __('app.series.summary_first', ['date' => ':date']),
            'last' => __('app.series.summary_last', ['date' => ':date']),
            'shift' => __('app.series.summary_shift', ['date' => ':date']),
            'none' => __('app.series.errors.no_dates'),
        ];
    @endphp
    <script>
    /*
     * The repeat fields in words, for the full booking form and the quick
     * modal alike. dates() follows App\Support\Recurrence\RecurrenceRule::
     * dates(): from the start date on, every day or each chosen weekday,
     * for N dates or until the end date. The first date is the first of
     * those on or after the start date — so the summary says which one it is
     * (and when it isn't the start date itself). The server decides again
     * on the review step; this only explains the choice.
     */
    window.LSRepeat = window.LSRepeat || (function () {
        const T = @json($repeatI18n);
        const plural = (map, n) => (n <= 2 ? map[n] : (n <= 10 ? map.few : map.many)).replace(':count', n);
        const iso = (d) => d.toISOString().slice(0, 10);
        const fmt = (s) => new Intl.DateTimeFormat(T.locale, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(s + 'T00:00:00Z'));

        function read(section) {
            const q = (sel) => section.querySelector(sel);
            return {
                daily: (q('input[name="repeat_frequency"]:checked') || {}).value === 'daily',
                weekdays: [...section.querySelectorAll('input[name="repeat_weekdays[]"]:checked')].map((i) => +i.value),
                byCount: (q('input[name="repeat_end"]:checked') || {}).value !== 'date',
                count: parseInt((q('input[name="repeat_count"]') || {}).value, 10) || 0,
                until: (q('input[name="repeat_until"]') || {}).value || '',
            };
        }

        function dates(start, r, max = 1000) {
            const out = [];
            if (!start || (!r.daily && !r.weekdays.length) || (r.byCount ? r.count < 1 : !r.until)) return out;
            const d = new Date(start + 'T00:00:00Z');
            for (let i = 0; i < 4000 && out.length < Math.min(max, r.byCount ? r.count : max); i++, d.setUTCDate(d.getUTCDate() + 1)) {
                if (!r.byCount && iso(d) > r.until) break;
                if (r.daily || r.weekdays.includes(d.getUTCDay())) out.push(iso(d));
            }
            return out;
        }

        /** {html, count, first} for the summary line; html is built from plain text only. */
        function summary(section, start) {
            const r = read(section);
            const list = dates(start, r);
            const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
            if (!list.length) return { html: esc(T.none), count: 0, first: null };
            const order = [6, 0, 1, 2, 3, 4, 5]; // the week as it runs here
            const pattern = r.daily ? T.everyDay : T.everyDays.replace(':days', order.filter((d) => r.weekdays.includes(d)).map((d) => T.days[d]).join(T.sep));
            const end = r.byCount ? plural(T.count, list.length) : T.until.replace(':date', fmt(r.until)) + ' · ' + plural(T.count, list.length);
            let html = '<b>' + esc(pattern) + '</b> · ' + esc(end) + '<br>' + esc(T.first.replace(':date', fmt(list[0])));
            if (list.length > 1) html += ' · ' + esc(T.last.replace(':date', fmt(list[list.length - 1])));
            if (list[0] !== start) html += '<span class="ls-repeat-shift">' + esc(T.shift.replace(':date', fmt(list[0]))) + '</span>';
            return { html, count: list.length, first: list[0] };
        }

        // Fold the settings away once set (the summary line stays), open them again with "Edit".
        document.addEventListener('click', (e) => {
            const b = e.target.closest('[data-repeat-collapse]');
            if (!b) return;
            const section = b.closest('.ls-repeat');
            const folded = section.classList.toggle('is-collapsed');
            b.setAttribute('aria-expanded', folded ? 'false' : 'true');
            b.textContent = folded ? b.dataset.edit : b.dataset.done;
        });

        return { read, dates, summary };
    })();
    </script>
@endonce
