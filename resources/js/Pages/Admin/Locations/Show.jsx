import { Link } from '@inertiajs/react';
import rowLink from '../../../Components/Admin/rowLink';
import { Badge, Button, EmptyState } from '../../../Components/ui';
import { money, num } from '../../../lib/format';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';

const tp = (key, params) => t(`admin_platform.${key}`, params);

export default function AdminLocationShow({ location: l, rooms }) {
    usePageTitle(l.name, tp('nav.locations'));
    const wsHref = `/admin/owners/${l.owner_id}?workspace=${l.id}`;

    return (
        <div className="ls-adm">
            <Link href="/admin/locations" className="ls-biz-back">&larr; {tp('nav.locations')}</Link>
            <header className="ls-biz-head">
                <div className="ls-biz-id">
                    <h1 className="ls-biz-name">{l.name} {!l.is_active && <Badge tone="neutral">{t('status.inactive')}</Badge>}</h1>
                    <p className="ls-biz-meta">
                        <Link href={wsHref} className="ls-link">{l.business}</Link>
                        {l.meta.map((bit, i) => <span key={i}> · <span>{bit}</span></span>)}
                    </p>
                    {l.description && <p className="ls-faint" style={{ margin: 0 }}>{l.description}</p>}
                </div>
                <div className="ls-biz-actions">
                    <Button size="sm" href={wsHref}>{tp('open_workspace')}</Button>
                    <Button size="sm" href={`/admin/bookings?owner=${l.owner_id}&location=${l.id}`}>{t('nav.bookings')}</Button>
                </div>
            </header>

            <section className="ls-card">
                <div className="ls-card-head"><h2 className="ls-card-title">{tp('nav.rooms')} <span className="ls-count">{rooms.length}</span></h2></div>
                <div className="ls-card-body ls-card-body--flush">
                    {rooms.length === 0 ? (
                        <EmptyState title={tp('no_rooms')} />
                    ) : (
                        <div className="ls-table-wrap">
                            <table className="ls-table ls-adm-table">
                                <thead><tr>
                                    <th scope="col">{tp('col.room')}</th>
                                    <th scope="col">{tp('col.type')}</th>
                                    <th scope="col" className="is-num">{tp('col.capacity')}</th>
                                    <th scope="col">{tp('col.pricing')}</th>
                                    <th scope="col" className="is-num">{tp('col.bookings_all')}</th>
                                    <th scope="col" className="is-num">{tp('col.earned_all')}</th>
                                    <th scope="col">{t('common.status')}</th>
                                </tr></thead>
                                <tbody>
                                    {rooms.map((room) => (
                                        <tr key={room.id} {...rowLink(`/admin/rooms/${room.id}`)}>
                                            <td><Link href={`/admin/rooms/${room.id}`} className="ls-adm-ident-name">{room.name}</Link></td>
                                            <td>{room.type}</td>
                                            <td className="is-num">{room.capacity}</td>
                                            <td className="ls-faint">{room.pricing}</td>
                                            <td className="is-num">{num(room.bookings)}</td>
                                            <td className="is-money">{money(room.earned)}</td>
                                            <td>{room.is_available
                                                ? <span className="ls-status"><span className="ls-dot" />{tp('available')}</span>
                                                : <Badge tone="neutral">{tp('unavailable')}</Badge>}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </section>
        </div>
    );
}
