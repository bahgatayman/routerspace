{{--
    Booking form → "Repeat this booking" (create only). Off by default: the
    form books once, exactly as before. On: the repeat rule, and "Review
    dates" posts the same form to the recurring review step
    (bookings.series.review) instead — the single-booking button and the
    payment section step aside, since each date is its own unpaid booking.
    The fields themselves are bookings._repeat-fields (shared with the
    quick-booking modal).
--}}
@include('bookings._repeat-fields', ['rp' => 'repeat', 'useOld' => true, 'withReview' => true])

<script>
(function () {
    // Repeat on/off: the review button replaces the single-booking button and
    // the payment section (no upfront payment for a series). Weekly starts on
    // the booking date's weekday and follows that date until the owner picks
    // days themselves; the summary says which date comes first.
    // Queries stay inside this section: the page also holds the quick-booking modal's copy.
    const section = document.getElementById('repeat-section');
    const toggle = document.getElementById('repeat-toggle');
    const fields = document.getElementById('repeat-fields');
    const weekdays = document.getElementById('repeat-weekdays');
    const dateInput = document.getElementById('booking_date');
    const summary = document.getElementById('repeat-summary');
    if (!section || !toggle || !fields) return;
    // Days already chosen (a form shown again after an error) are the owner's own.
    let daysChosen = !!weekdays.querySelector('input:checked');
    weekdays.addEventListener('change', () => { daysChosen = true; sync(); });
    section.addEventListener('input', (e) => { if (e.target.matches('[name="repeat_count"], [name="repeat_until"]')) sync(); });
    section.addEventListener('change', (e) => { if (e.target.matches('[name="repeat_end"], [name="repeat_until"]')) sync(); });
    if (dateInput) dateInput.addEventListener('change', () => sync());

    function sync() {
        // Both come later in the page than this script: look them up each time.
        const payment = document.getElementById('payment-section');
        const cta = document.getElementById('confirm-cta');
        const on = toggle.checked;
        fields.hidden = !on;
        if (payment) payment.hidden = on;
        if (cta) cta.hidden = on;
        const weekly = (section.querySelector('input[name="repeat_frequency"]:checked') || {}).value === 'weekly';
        weekdays.hidden = !weekly;
        if (on && weekly && !daysChosen && dateInput && dateInput.value) {
            const day = new Date(dateInput.value + 'T00:00:00').getDay();
            weekdays.querySelectorAll('input').forEach((i) => { i.checked = +i.value === day; });
        }
        if (summary && window.LSRepeat) summary.innerHTML = on ? LSRepeat.summary(section, dateInput ? dateInput.value : '').html : '';
    }
    toggle.addEventListener('change', sync);
    section.querySelectorAll('input[name="repeat_frequency"]').forEach((r) => r.addEventListener('change', sync));
    document.addEventListener('DOMContentLoaded', sync);
})();
</script>
