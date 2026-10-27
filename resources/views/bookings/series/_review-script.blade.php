{{--
    LSSeriesReview.init(root, opts) — the behaviour of step 2 (bookings.series._review),
    wherever it is shown: the review page or step 2 of the quick-booking modal.

      opts.container  where a fresh review is put after the package changes
                      (the element that holds root)
      opts.onBack     what "Back" does (the modal: show step 1 again — every
                      choice there is still as it was); default: back in history

    Live: skipping a date, another time, the payment plan → totals and package
    coverage recalculated here (the same rule as RecurringBookingService::
    coverage()). A different package or a package sale → the server reviews
    again (same endpoint, as a fragment) and the dates/plan chosen so far are
    put back. Only the confirm button books, once; the server re-checks every
    date and amount then.
--}}
@once
    @php
        $plural = fn (string $key) => [
            0 => trans_choice($key, 0), 1 => trans_choice($key, 1, ['count' => 1]), 2 => trans_choice($key, 2, ['count' => 2]),
            'few' => trans_choice($key, 3, ['count' => ':count']), 'many' => trans_choice($key, 11, ['count' => ':count']),
        ];
        $seriesReviewI18n = [
            'count' => $plural('app.series.selected_count'),
            'confirm' => $plural('app.series.confirm'),
            'busy' => __('app.series.confirming'),
            'byPackage' => __('app.series.by_package'),
            'toPay' => collect(\App\Models\BookingSeries::PLANS)->mapWithKeys(fn ($p) => [$p => __('app.series.to_pay.'.$p)])->all(),
            'cover' => __('app.series.package_cover', ['hours' => ':hours', 'left' => ':left']),
            'unit' => [__('app.ui.unit_h'), __('app.ui.unit_m')],
            'error' => __('app.quick_booking.error'),
        ];
    @endphp
    <script>
    window.LSSeriesReview = window.LSSeriesReview || (function () {
        const T = @json($seriesReviewI18n);
        const pick = (map, n) => (n <= 2 ? map[n] : (n <= 10 ? map.few : map.many)).replace(':count', n);
        // Same output as App\Support\Duration::label().
        const hours = (m) => { const h = Math.floor(m / 60), r = m % 60; return [h ? h + T.unit[0] : '', (r || !h) ? r + T.unit[1] : ''].filter(Boolean).join(' '); };
        const money = (n) => (window.LS && LS.money ? LS.money(n) : (Number(n) || 0).toFixed(2));

        function init(root, opts = {}) {
            const form = root.querySelector('[data-series-review]');
            if (!form || form.dataset.ready) return;
            form.dataset.ready = '1';
            const confirm = form.querySelector('[data-confirm]');
            const coverLine = form.querySelector('[data-package-line]');
            const $ = (sel) => form.querySelector(sel);

            function refresh() {
                let n = 0, total = 0, minutes = 0, covered = 0;
                const coveredDates = [];
                let left = coverLine ? parseInt(coverLine.dataset.left, 10) : 0;
                form.querySelectorAll('[data-occurrence]').forEach((sel) => {
                    const li = sel.closest('li');
                    const price = parseFloat(sel.options[sel.selectedIndex].dataset.price || '0');
                    const booked = sel.value !== 'skip';
                    const m = parseInt(li.dataset.minutes, 10);
                    const byPackage = booked && coverLine && li.dataset.pkgOk === '1' && m <= left;
                    if (byPackage) { left -= m; covered += m; coveredDates.push(li.querySelector('input[name$="[date]"]').value); }
                    li.querySelector('[data-price-cell]').textContent = !booked ? '—' : (byPackage ? T.byPackage : money(price));
                    li.classList.toggle('is-skipped', !booked);
                    if (booked) { n++; minutes += m; total += byPackage ? 0 : price; }
                });
                const plan = (form.querySelector('[name="payment_plan"]:checked') || {}).value;
                if (plan) form.querySelectorAll('[data-total-label], [data-sum-total-label]').forEach((el) => { el.textContent = T.toPay[plan]; });
                $('[data-selected-count]').textContent = pick(T.count, n);
                $('[data-hours]').textContent = hours(minutes);
                $('[data-total]').textContent = money(total);
                $('[data-sum-count]').textContent = n;
                $('[data-sum-hours]').textContent = hours(minutes);
                $('[data-sum-total]').textContent = money(total);
                if ($('[data-sum-package]')) $('[data-sum-package]').textContent = hours(covered);
                if (coverLine) coverLine.querySelector('[data-package-cover]').textContent = T.cover.replace(':hours', hours(covered)).replace(':left', hours(left));
                if ($('[data-package-dates]')) $('[data-package-dates]').value = coveredDates.join(',');
                confirm.querySelector('span').textContent = pick(T.confirm, n);
                confirm.disabled = n === 0;
            }
            form.addEventListener('change', (e) => { if (e.target.matches('[data-occurrence], [name="payment_plan"]')) refresh(); });

            // Long series: everything, or just the first dates (dates that can't be booked always show).
            const more = $('[data-show-all]');
            if (more) more.addEventListener('click', () => {
                const open = more.getAttribute('aria-expanded') !== 'true';
                form.querySelectorAll('[data-extra]').forEach((li) => { li.hidden = !open; });
                more.setAttribute('aria-expanded', open ? 'true' : 'false');
                more.textContent = open ? more.dataset.less : more.dataset.more;
            });

            // Another package, or a package sold here: the server reviews again.
            const pkgSelect = $('[data-package-select]');
            if (pkgSelect) pkgSelect.addEventListener('change', () => reload(root, opts, form.dataset.reviewUrl));

            const tpl = $('[data-sale-template]');
            if (tpl) tpl.addEventListener('change', () => {
                const o = tpl.selectedOptions[0];
                if (!o || !o.value) return;
                $('#sale-name').value = o.dataset.name;
                $('#sale-hours').value = o.dataset.hours;
                $('#sale-price').value = o.dataset.price;
                const start = $('#sale-starts').value;
                if (start) {
                    const d = new Date(start + 'T00:00:00Z');
                    d.setUTCDate(d.getUTCDate() + parseInt(o.dataset.days, 10) - 1); // valid N days, inclusive
                    $('#sale-expires').value = d.toISOString().slice(0, 10);
                }
            });

            // Only the confirm button books, once (the server also refuses a second one for this review).
            let sent = false;
            form.addEventListener('submit', (e) => {
                if (e.submitter && e.submitter.matches('[data-sell]')) { e.preventDefault(); reload(root, opts, form.dataset.sellUrl); return; }
                if (e.submitter && e.submitter !== confirm) return;
                if (sent) { e.preventDefault(); return; }
                sent = true;
                confirm.setAttribute('aria-disabled', 'true');
                confirm.classList.add('is-busy');
                confirm.querySelector('span').textContent = T.busy;
            });
            window.addEventListener('pageshow', (e) => { if (e.persisted) { sent = false; confirm.classList.remove('is-busy'); confirm.removeAttribute('aria-disabled'); refresh(); } });

            $('[data-back]').addEventListener('click', (e) => {
                if (opts.onBack) { e.preventDefault(); opts.onBack(); return; }
                if (history.length > 1) { e.preventDefault(); history.back(); }
            });

            refresh();
        }

        /** Ask the server for a fresh review (package changed / sold), keeping the dates and plan chosen so far. */
        async function reload(root, opts, url) {
            const form = root.querySelector('[data-series-review]');
            const keep = {
                plan: (form.querySelector('[name="payment_plan"]:checked') || {}).value,
                choices: Object.fromEntries([...form.querySelectorAll('[data-occurrence]')].map((s) => [s.closest('li').querySelector('input[name$="[date]"]').value, s.value])),
                all: form.querySelector('[data-show-all]')?.getAttribute('aria-expanded') === 'true',
            };
            const container = opts.container || root.parentElement;
            root.setAttribute('aria-busy', 'true');
            try {
                const r = await fetch(url, { method: 'POST', body: new FormData(form), headers: { 'X-LS-Fragment': 'series-review', Accept: 'text/html' } });
                if (!r.ok) {
                    let msg = T.error;
                    try { msg = (await r.json()).message || msg; } catch (x) {}
                    throw new Error(msg);
                }
                container.innerHTML = await r.text();
                const next = container.querySelector('[data-series-review-root]');
                const f = next.querySelector('[data-series-review]');
                if (keep.plan) { const radio = f.querySelector(`[name="payment_plan"][value="${keep.plan}"]`); if (radio) radio.checked = true; }
                f.querySelectorAll('[data-occurrence]').forEach((s) => {
                    const v = keep.choices[s.closest('li').querySelector('input[name$="[date]"]').value];
                    if (v && [...s.options].some((o) => o.value === v)) s.value = v;
                });
                init(next, opts);
                if (keep.all) f.querySelector('[data-show-all]')?.click();
                next.querySelector('[data-package-select], [data-sell]')?.focus({ preventScroll: true });
            } catch (err) {
                const box = root.querySelector('[data-review-error]');
                box.querySelector('span').textContent = err.message || T.error;
                box.hidden = false;
                box.scrollIntoView({ block: 'nearest' });
            } finally {
                root.removeAttribute('aria-busy');
            }
        }

        return { init };
    })();
    </script>
@endonce
