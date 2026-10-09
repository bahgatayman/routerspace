import { Link } from '@inertiajs/react';
import { FilterBar, PeriodFields, Stat, useFilters } from '../../../Components/analytics';
import { Badge, Button } from '../../../Components/ui';
import { money, num } from '../../../lib/format';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';
import BookingsTable from '../Bookings/BookingsTable';

const tp = (key, params) => t(`admin_platform.${key}`, params);

export default function AdminRoomShow({ room, stats, range, today, recent }) {
    usePageTitle(room.name, tp('nav.rooms'));
    const filters = useFilters({ preset: range.preset, from: range.from || '', to: range.to || '' });
    const bookingsHref = `/admin/bookings?owner=${room.owner_id}&room=${room.id}`;

    return (
        <div className="ls-adm">
            <Link href="/admin/rooms" className="ls-biz-back">&larr; {tp('nav.rooms')}</Link>
            <header className="ls-biz-head">
                <div className="ls-biz-id">
                    <h1 className="ls-biz-name">{room.name}{' '}
                        {room.is_available ? <Badge tone="ok">{tp('available')}</Badge> : <Badge tone="neutral">{tp('unavailable')}</Badge>}
                    </h1>
                    <p className="ls-biz-meta">
                        <Link href={`/admin/owners/${room.owner_id}`} className="ls-link">{room.business}</Link>
                        {room.location_id && <> · <Link href={`/admin/locations/${room.location_id}`} className="ls-link">{room.location}</Link></>}
                        {' '}· {room.type} · {tp('capacity_n', { n: room.capacity })}
                    </p>
                </div>
                <div className="ls-biz-actions">
                    <Button size="sm" href={bookingsHref}>{tp('all_bookings')}</Button>
                </div>
            </header>

            <FilterBar filters={filters} variant="secondary"><PeriodFields filters={filters} /></FilterBar>

            <div className="ls-akpis">
                <Stat label={tp('kpi.bookings')} value={num(stats.bookings)} href={`${bookingsHref}&preset=${range.preset}&from=${range.from || ''}&to=${range.to || ''}`} />
                <Stat label={tp('kpi.completed')} value={num(stats.completed)} />
                <Stat label={tp('kpi.cancellation_rate')} value={`${num(stats.cancel_rate, 1)}%`} />
                <Stat label={tp('kpi.earnings')} value={money(stats.earnings)} tone="revenue" help={tp('help.room_earnings')} />
                <Stat label={tp('kpi.gbv')} value={money(stats.gbv)} help={tp('help.gbv')} />
                <Stat label={tp('hours_billed')} value={num(stats.hours, 1)} />
                <Stat label={tp('kpi.outstanding')} value={money(stats.outstanding)} sub={tp('all_time')} tone={stats.outstanding > 0 ? 'warn' : null} />
            </div>

            <div className="ls-adm-grid">
                <section className="ls-card">
                    <div className="ls-card-head"><h2 className="ls-card-title">{tp('pricing_title')}</h2></div>
                    <div className="ls-card-body ls-stack">
                        <dl className="ls-kv">
                            <dt>{tp('pricing_model')}</dt><dd>{t(`pricing.model.${room.pricing_model}`)}</dd>
                            <dt>{tp('col.pricing')}</dt><dd>{room.pricing}</dd>
                            {room.is_shared && <><dt>{tp('billing_unit')}</dt><dd>{room.billing_unit}</dd></>}
                            <dt>{tp('buffer')}</dt><dd>{room.buffer_minutes ? tp('minutes_n', { n: room.buffer_minutes }) : '—'}</dd>
                        </dl>
                        {room.profiles.length > 0 && (
                            <div>
                                <h3 className="ls-section-title">{tp('pricing_profiles')}</h3>
                                <ul className="ls-adm-list">
                                    {room.profiles.map((p) => (
                                        <li key={p.id}>
                                            <span>{p.name} {!p.is_active && <Badge tone="neutral" dot={false}>{t('status.inactive')}</Badge>}</span>
                                            <span className="ls-num">{money(p.price_per_hour)}{t('common.slash_hr')}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        {room.plans.length > 0 && (
                            <div>
                                <h3 className="ls-section-title">{tp('custom_plans')}</h3>
                                <ul className="ls-adm-list">
                                    {room.plans.map((p) => <li key={p.id}><span>{p.name}</span><span className="ls-num">{money(p.price)}</span></li>)}
                                </ul>
                            </div>
                        )}
                        {room.description && <p className="ls-faint" style={{ margin: 0 }}>{room.description}</p>}
                    </div>
                </section>

                <section className="ls-card">
                    <div className="ls-card-head"><h2 className="ls-card-title">{tp('today_availability')}</h2></div>
                    <div className="ls-card-body">
                        {today.length === 0 ? (
                            <p className="ls-faint">{tp('closed_today')}</p>
                        ) : (
                            <ul className="ls-adm-slots">
                                {today.map((seg, i) => (
                                    <li key={i} className={`is-${seg.state}`}>
                                        <span className="ls-num" dir="ltr">{seg.start} – {seg.end}</span>
                                        <span>{tp(`slot.${seg.state}`)}{!seg.closed && room.is_shared && <> · {seg.available}/{seg.capacity}</>}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </section>
            </div>

            <section className="ls-card">
                <div className="ls-card-head"><h2 className="ls-card-title">{tp('recent_bookings')} <span className="ls-count">{num(stats.all_time)}</span></h2></div>
                <div className="ls-card-body ls-card-body--flush">
                    <BookingsTable bookings={recent} hide={['workspace', 'room']} />
                </div>
            </section>
        </div>
    );
}
