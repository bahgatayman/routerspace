import rowLink from '../../../Components/Admin/rowLink';
import { Link } from '@inertiajs/react';
import { FilterBar, PeriodFields, useFilters } from '../../../Components/analytics';
import { Badge, EmptyState } from '../../../Components/ui';
import { money, num } from '../../../lib/format';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';
import BusinessHeader from './Header';

export default function BusinessRooms({ business, workspace, range, rooms }) {
    usePageTitle(business.name, t('admin_biz.tabs.rooms'));
    const filters = useFilters({ workspace: workspace?.id ?? '', preset: range.preset, from: range.from, to: range.to });
    const tp = (k, r) => t(`admin_platform.${k}`, r);

    return (
        <div className="ls-biz-page">
            <BusinessHeader business={business} active="rooms" workspaceId={workspace?.id ?? null} locationAware />

            <FilterBar filters={filters} variant="secondary">
                <PeriodFields filters={filters} />
            </FilterBar>

            <section className="ls-card">
                {rooms.length === 0 ? (
                    <EmptyState title={tp('no_rooms')} />
                ) : (
                    <div className="ls-table-wrap">
                        <table className="ls-table ls-adm-table">
                            <thead><tr>
                                <th scope="col">{tp('col.room')}</th>
                                <th scope="col">{tp('col.location')}</th>
                                <th scope="col">{tp('col.type')}</th>
                                <th scope="col" className="is-num">{tp('col.capacity')}</th>
                                <th scope="col">{tp('col.pricing')}</th>
                                <th scope="col" className="is-num">{tp('col.bookings_period')}</th>
                                <th scope="col" className="is-num">{tp('hours_billed')}</th>
                                <th scope="col" className="is-num">{tp('col.earnings_period')}</th>
                                <th scope="col">{t('common.status')}</th>
                            </tr></thead>
                            <tbody>
                                {rooms.map((room) => (
                                    <tr key={room.id} {...rowLink(`/admin/rooms/${room.id}`)}>
                                        <td><Link href={`/admin/rooms/${room.id}`} className="ls-adm-ident-name">{room.name}</Link></td>
                                        <td>{room.location || '—'}</td>
                                        <td>{room.type}</td>
                                        <td className="is-num">{room.capacity}</td>
                                        <td className="ls-faint">{room.pricing}</td>
                                        <td className="is-num">{num(room.period_bookings)}</td>
                                        <td className="is-num">{num(room.period_hours, 1)}</td>
                                        <td className="is-money">{money(room.period_earnings)}</td>
                                        <td>{room.is_available
                                            ? <span className="ls-status"><span className="ls-dot" />{tp('available')}</span>
                                            : <Badge tone="neutral">{tp('unavailable')}</Badge>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
        </div>
    );
}
