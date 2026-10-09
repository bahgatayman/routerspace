import { Link } from '@inertiajs/react';
import { Badge } from '../../../Components/ui';
import { money } from '../../../lib/format';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';
import { STATUS_TONE } from './BookingsTable';

const tp = (key, params) => t(`admin_platform.${key}`, params);

export default function AdminBookingShow({ booking: b, activity }) {
    usePageTitle(`#${b.id}`, t('nav.bookings'));

    return (
        <div className="ls-adm">
            <Link href="/admin/bookings" className="ls-biz-back">&larr; {t('nav.bookings')}</Link>
            <header className="ls-biz-head">
                <div className="ls-biz-id">
                    <h1 className="ls-biz-name">{tp('booking_n', { id: b.id })}{' '}
                        <Badge tone={STATUS_TONE[b.status] || 'neutral'}>{tp(`status.${b.status}`)}</Badge>{' '}
                        <Badge tone={b.payment_tone} dot={false}>{b.payment_label}</Badge>
                    </h1>
                    <p className="ls-biz-meta">{tp(`types.${b.type}`)} · {tp('created_at', { date: b.created })}</p>
                </div>
            </header>

            <div className="ls-adm-grid">
                <section className="ls-card">
                    <div className="ls-card-head"><h2 className="ls-card-title">{tp('reservation')}</h2></div>
                    <div className="ls-card-body">
                        <dl className="ls-kv">
                            <dt>{tp('col.workspace')}</dt><dd><Link href={`/admin/owners/${b.owner_id}`} className="ls-link">{b.business}</Link></dd>
                            <dt>{tp('col.location')}</dt><dd>{b.location_id ? <Link href={`/admin/locations/${b.location_id}`} className="ls-link">{b.location}</Link> : '—'}</dd>
                            <dt>{tp('col.room')}</dt><dd>{b.room_id ? <><Link href={`/admin/rooms/${b.room_id}`} className="ls-link">{b.room}</Link> · {b.room_type}</> : '—'}</dd>
                            <dt>{tp('col.date')}</dt><dd>{b.date}</dd>
                            <dt>{tp('time')}</dt><dd>{b.time}{b.hours && <> · {tp('hours_n', { n: b.hours })}</>}</dd>
                            <dt>{tp('people')}</dt><dd>{b.party_size}{b.checked_in_party_size ? <> · {tp('checked_in_n', { n: b.checked_in_party_size })}</> : null}</dd>
                            <dt>{tp('col.customer')}</dt>
                            <dd>{b.customer ?? t('admin_biz.deleted_member')}{b.phone && <> · <bdi dir="ltr">{b.phone}</bdi></>}</dd>
                            {b.session && (
                                <>
                                    <dt>{tp('types.session')}</dt>
                                    <dd>{b.session.opened} – {b.session.closed ?? '…'} · {tp('minutes_n', { n: b.session.minutes })}</dd>
                                </>
                            )}
                            {b.notes && <><dt>{tp('notes')}</dt><dd>{b.notes}</dd></>}
                        </dl>
                    </div>
                </section>

                <section className="ls-card">
                    <div className="ls-card-head"><h2 className="ls-card-title">{tp('payment_title')}</h2></div>
                    <div className="ls-card-body ls-stack">
                        <dl className="ls-kv">
                            <dt>{tp('pricing_used')}</dt>
                            <dd>{b.pricing_name ?? tp('standard_pricing')}{b.price_per_hour > 0 && <> · {money(b.price_per_hour)}{t('common.slash_hr')}</>}</dd>
                            {b.pricing_note && <><dt>{tp('pricing_note')}</dt><dd>{b.pricing_note}</dd></>}
                            {b.buffer_minutes ? <><dt>{tp('buffer')}</dt><dd>{tp('minutes_n', { n: b.buffer_minutes })}</dd></> : null}
                            <dt>{tp('room_charge')}</dt><dd className="ls-num">{money(b.total_price)}</dd>
                            {b.discount_total > 0 && (
                                <>
                                    <dt>{tp('coupon')}{b.coupon_code && <> <bdi dir="ltr">({b.coupon_code})</bdi></>}</dt>
                                    <dd className="ls-num">− {money(b.discount_total)}</dd>
                                </>
                            )}
                            <dt>{tp('col.net')}</dt><dd className="ls-num">{money(b.net)}</dd>
                            <dt>{tp('col.paid')}</dt><dd className="ls-num">{money(b.paid)}</dd>
                            <dt>{tp('payment_method')}</dt><dd>{b.payment_method ?? '—'}</dd>
                            {b.package && <><dt>{tp('hour_package')}</dt><dd>{b.package}</dd></>}
                        </dl>
                        <div className="ls-total"><span>{tp('balance_due')}</span><b>{money(b.balance_due)}</b></div>
                        <p className="ls-faint" style={{ margin: 0, fontSize: '12.5px' }}>{tp('payment_ledger_note')}</p>
                    </div>
                </section>
            </div>

            {b.sale && (
                <section className="ls-card">
                    <div className="ls-card-head"><h2 className="ls-card-title">{tp('products_sold')}</h2><span className="ls-faint">{tp(`status.${b.sale.status}`)}</span></div>
                    <div className="ls-card-body ls-card-body--flush">
                        <div className="ls-table-wrap">
                            <table className="ls-table">
                                <thead><tr>
                                    <th scope="col">{tp('col.product')}</th>
                                    <th scope="col" className="is-num">{tp('qty')}</th>
                                    <th scope="col" className="is-num">{tp('unit_price')}</th>
                                    <th scope="col" className="is-num">{tp('line_total')}</th>
                                </tr></thead>
                                <tbody>
                                    {b.sale.items.map((item) => (
                                        <tr key={item.id}><td>{item.name}</td><td className="is-num">{item.quantity}</td><td className="is-money">{money(item.unit_price)}</td><td className="is-money">{money(item.line_total)}</td></tr>
                                    ))}
                                </tbody>
                                <tfoot><tr><th scope="row" colSpan={3}>{tp('sale_total')}</th><td className="is-money">{money(b.sale.total)}</td></tr></tfoot>
                            </table>
                        </div>
                    </div>
                </section>
            )}

            <section className="ls-card">
                <div className="ls-card-head"><h2 className="ls-card-title">{tp('activity_title')}</h2></div>
                <div className="ls-card-body">
                    {activity.length === 0 ? (
                        <p className="ls-faint" style={{ margin: 0 }}>{tp('no_booking_activity')}</p>
                    ) : (
                        <ol className="ls-biz-feed">
                            {activity.map((a) => (
                                <li key={a.id}><time dateTime={a.iso}>{a.at}</time><span><b>{a.actor || t('admin_biz.staff_member')}</b> — {a.text}</span><span /></li>
                            ))}
                        </ol>
                    )}
                </div>
            </section>
        </div>
    );
}
