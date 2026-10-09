import { Link } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { Badge, Button, EmptyState, PageHeader } from '../../Components/ui';
import { locale, t, tc } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

/*
 * Check availability for one day or a date range. One request for the whole
 * range — GET /bookings/availability-range (AvailabilityService::rangeReport()) —
 * and the answer is rendered here; no availability logic lives in the page.
 */
export default function BookingAvailability({ roomGroups, hasRooms, timeSlots, prefRoom, prefDate }) {
    usePageTitle(t('availability.title'));
    const [mode, setMode] = useState('single');
    const [from, setFrom] = useState(prefDate);
    const [to, setTo] = useState(prefDate);
    const [start, setStart] = useState('10:00');
    const [end, setEnd] = useState('18:00');
    const [room, setRoom] = useState(prefRoom || '');
    const [people, setPeople] = useState('1');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState(null);
    const resultsRef = useRef(null);

    const changeMode = (m) => { setMode(m); if (m === 'range' && to < from) setTo(from); };
    const changeFrom = (v) => { setFrom(v); if (to < v) setTo(v); };

    const submit = (e) => {
        e.preventDefault();
        setError('');
        const toDate = mode === 'range' ? to : from;
        if (!from || !start || !end) { setError(t('availability.pick_times')); return; }
        const params = new URLSearchParams({ from, to: toDate, start_time: start, end_time: end, party_size: people || 1 });
        if (room) params.set('room_id', room);
        setBusy(true);
        fetch(`/bookings/availability-range?${params}`, { headers: { Accept: 'application/json' } })
            .then((r) => r.json().then((b) => ({ ok: r.ok, b })))
            .then(({ ok, b }) => {
                if (!ok || !b.success) { setError((b && b.message) || t('quick_booking.error')); return; }
                setResult(b);
                requestAnimationFrame(() => resultsRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
            })
            .catch(() => setError(t('quick_booking.error')))
            .finally(() => setBusy(false));
    };

    return (
        <>
            <PageHeader title={t('availability.title')} subtitle={t('availability.subtitle')} />

            <form className="ls-card ls-av-form" id="av-form" noValidate onSubmit={submit}>
                <div className="ls-card-body">
                    <div className="ls-inv-seg ls-av-mode" role="radiogroup" aria-label={t('availability.mode_range')}>
                        <label><input type="radio" name="av-mode" value="single" checked={mode === 'single'} onChange={() => changeMode('single')} /><span>{t('availability.mode_single')}</span></label>
                        <label><input type="radio" name="av-mode" value="range" checked={mode === 'range'} onChange={() => changeMode('range')} /><span>{t('availability.mode_range')}</span></label>
                    </div>

                    <div className="ls-av-grid">
                        <div className="ls-field">
                            <label className="ls-label" htmlFor="av-from">{mode === 'range' ? t('availability.from') : t('availability.date')}</label>
                            <input type="date" id="av-from" className="ls-input" value={from} onChange={(e) => changeFrom(e.target.value)} required />
                        </div>
                        {mode === 'range' && (
                            <div className="ls-field" id="av-to-field">
                                <label className="ls-label" htmlFor="av-to">{t('availability.to')}</label>
                                <input type="date" id="av-to" className="ls-input" min={from} value={to} onChange={(e) => setTo(e.target.value)} />
                            </div>
                        )}
                        <div className="ls-field">
                            <label className="ls-label" htmlFor="av-start">{t('availability.start')}</label>
                            <select id="av-start" className="ls-select" dir="ltr" value={start} onChange={(e) => setStart(e.target.value)}>
                                {Object.entries(timeSlots).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                            </select>
                        </div>
                        <div className="ls-field">
                            <label className="ls-label" htmlFor="av-end">{t('availability.end')}</label>
                            <select id="av-end" className="ls-select" dir="ltr" value={end} onChange={(e) => setEnd(e.target.value)}>
                                {Object.entries(timeSlots).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                            </select>
                        </div>
                        <div className="ls-field">
                            <label className="ls-label" htmlFor="av-room">{t('availability.room')}</label>
                            <select id="av-room" className="ls-select" value={room} onChange={(e) => setRoom(e.target.value)}>
                                <option value="">{t('availability.all_rooms')}</option>
                                {roomGroups.map((g) => (
                                    <optgroup key={g.workspace} label={g.workspace}>
                                        {g.rooms.map((r) => <option key={r.id} value={String(r.id)}>{r.label}</option>)}
                                    </optgroup>
                                ))}
                            </select>
                        </div>
                        <div className="ls-field">
                            <label className="ls-label" htmlFor="av-people">{t('availability.people')}</label>
                            <input type="number" id="av-people" className="ls-input" min="1" max="999" value={people} inputMode="numeric" onChange={(e) => setPeople(e.target.value)} />
                        </div>
                    </div>

                    <div className="ls-av-actions">
                        <Button type="submit" variant="primary" icon="search" id="av-submit" processing={busy}>{t('availability.check')}</Button>
                        {error && <p className="ls-error" id="av-error" role="alert">{error}</p>}
                    </div>
                </div>
            </form>

            {result && (
                <section id="av-results" className="ls-av-results" aria-live="polite" ref={resultsRef}>
                    <Results data={result} timeSlots={timeSlots} />
                </section>
            )}

            {!hasRooms && <div className="ls-card"><EmptyState title={t('availability.no_rooms')} /></div>}
        </>
    );
}

const fmtDate = (ymd, opts = { weekday: 'short', day: 'numeric', month: 'short' }) =>
    new Date(`${ymd}T00:00:00`).toLocaleDateString(locale() === 'ar' ? 'ar-EG' : 'en-GB', opts);
const fmtMins = (mins) => {
    const h = Math.floor(mins / 60), m = mins % 60;
    return (h ? h + t('ui.unit_h') : '') + (h && m ? ' ' : '') + (m || !h ? m + t('ui.unit_m') : '');
};
const daysLabel = (n) => tc('availability.days', n, { count: n });

function Results({ data, timeSlots }) {
    const fmtTime = (hm) => timeSlots[hm] || hm;
    const whole = data.rooms.filter((r) => r.all_available).length;
    // Rooms fully available first, then by fewest conflicts.
    const rooms = [...data.rooms].sort((a, b) => (b.days_available - a.days_available) || a.room_name.localeCompare(b.room_name));
    return (
        <>
            <div className="ls-av-summary">
                <h2 className="ls-av-summary-title">
                    {data.days === 1
                        ? `${fmtDate(data.from, { weekday: 'long', day: 'numeric', month: 'long' })} · ${daysLabel(1)}`
                        : t('availability.summary', { from: fmtDate(data.from), to: fmtDate(data.to), days: daysLabel(data.days) })}
                </h2>
                <p className="ls-av-summary-sub">{t('availability.each_day', { start: fmtTime(data.start_time), end: fmtTime(data.end_time) })}</p>
                <p className={`ls-av-verdict ${whole ? 'is-ok' : 'is-none'}`}>
                    {whole ? tc('availability.rooms_all_period', whole, { count: whole }) : t('availability.none_all_period')}
                </p>
            </div>
            <div className="ls-av-rooms">
                {rooms.map((room) => <RoomCard key={room.room_id} room={room} fmtTime={fmtTime} />)}
            </div>
        </>
    );
}

function RoomCard({ room, fmtTime }) {
    const tone = room.all_available ? 'ok' : room.days_available ? 'warn' : 'danger';
    const badge = room.all_available
        ? t('availability.all_available', { days: daysLabel(room.days_total) })
        : t('availability.available_count', { n: room.days_available, total: room.days_total })
            + (room.conflict_days ? ` · ${tc('availability.conflicts', room.conflict_days, { count: room.conflict_days })}` : '');
    return (
        <article className={`ls-card ls-av-room${room.all_available ? ' is-all' : ''}`}>
            <div className="ls-av-room-head">
                <div className="ls-av-room-who">
                    <h3 className="ls-av-room-name">{room.room_name}</h3>
                    <span className="ls-av-room-meta">{[room.workspace, room.type_label, room.is_shared ? t('availability.seats', { count: room.capacity }) : null].filter(Boolean).join(' · ')}</span>
                </div>
                <Badge tone={tone}>{badge}</Badge>
            </div>
            <ul className="ls-av-days">
                {room.days.map((day) => <DayRow key={day.date} room={room} day={day} fmtTime={fmtTime} />)}
            </ul>
        </article>
    );
}

function DayRow({ room, day, fmtTime }) {
    let text = t(`availability.${day.status}`);
    if (room.is_shared && ['available', 'limited', 'unavailable'].includes(day.status)) {
        text = t('availability.seats_free', { free: day.remaining, capacity: day.capacity })
            + (day.used ? ` · ${tc('availability.seats_booked', day.used, { count: day.used })}` : '');
    }
    const showDetail = !['past', 'closed'].includes(day.status) && day.conflicts.length > 0;

    // A private room is simply booked or free: join neighbouring pieces in the same state.
    const merged = [];
    if (showDetail && !room.is_shared) {
        day.segments.forEach((s) => {
            const busy = s.available <= 0, last = merged[merged.length - 1];
            if (last && last.busy === busy && last.end === s.start) last.end = s.end;
            else merged.push({ start: s.start, end: s.end, busy });
        });
    }

    return (
        <li className={`ls-av-day is-${day.status}`}>
            <div className="ls-av-day-top">
                <span className="ls-av-day-date">{fmtDate(day.date)}</span>
                <span className="ls-av-day-status"><i className="ls-av-dot" />{text}</span>
            </div>
            {showDetail && (
                <>
                    {!room.is_shared && (
                        <div className="ls-av-segs">
                            {merged.map((s) => (
                                <span key={s.start} className={`ls-av-seg ${s.busy ? 'is-booked' : 'is-free'}`}>
                                    {fmtTime(s.start)} – {fmtTime(s.end)} · {s.busy ? t('availability.booked') : t('availability.free')}
                                </span>
                            ))}
                        </div>
                    )}
                    <ul className="ls-av-conflicts">
                        {day.conflicts.map((c, i) => {
                            const when = c.end ? `${fmtTime(c.start)} – ${fmtTime(c.end)}` : `${fmtTime(c.start)} → ${t('availability.open_ended')}`;
                            const statusKey = `availability.status.${c.status}`;
                            const status = t(statusKey) === `app.${statusKey}` ? c.status : t(statusKey);
                            const bits = [c.customer, status];
                            if (room.is_shared) bits.push(tc('availability.people_count', c.party_size, { count: c.party_size }));
                            else if (c.overlap_minutes > 0) bits.push(t('availability.overlap', { time: fmtMins(c.overlap_minutes) }));
                            return (
                                <li key={i} className="ls-av-conflict">
                                    <b>{when}</b>
                                    <span>{bits.filter(Boolean).join(' · ')}</span>
                                    {c.booking_id && <Link className="ls-link" href={`/bookings/${c.booking_id}`}>#{c.booking_id}</Link>}
                                </li>
                            );
                        })}
                    </ul>
                </>
            )}
        </li>
    );
}
