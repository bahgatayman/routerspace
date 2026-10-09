import { Link, usePage } from '@inertiajs/react';
import rowLink from '../../../Components/Admin/rowLink';
import { FilterBar, PeriodFields, useFilters } from '../../../Components/analytics';
import { Badge, EmptyState, Icon, Pagination, SortHeader } from '../../../Components/ui';
import { money, num } from '../../../lib/format';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';

const tp = (key, params) => t(`admin_platform.${key}`, params);

export default function AdminRoomsIndex({ rooms, owners, locations, filters: f, sort, dir, range, types }) {
    usePageTitle(tp('nav.rooms'));
    const { url } = usePage();
    const qs = new URLSearchParams(url.split('?')[1] || '');
    const hasFilters = ['q', 'owner', 'location', 'type', 'available', 'idle', 'preset'].some((k) => qs.has(k));
    const filters = useFilters({
        q: f.q || '', owner: f.owner || '', location: f.location || '', type: f.type || '', available: f.available ?? '',
        preset: range.preset, from: range.from || '', to: range.to || '', idle: f.idle ? '1' : '',
    });
    const { values, set, apply } = filters;
    const sh = (field, label, className, defaultDir) => <SortHeader field={field} label={label} sort={sort} dir={dir} className={className} defaultDir={defaultDir} />;

    return (
        <div className="ls-adm">
            <header className="ls-page-head ls-adm-head">
                <div>
                    <h1 className="ls-title">{tp('nav.rooms')} <span className="ls-count">{num(rooms.total)}</span></h1>
                    <p className="ls-subtitle">{tp('rooms_sub')}</p>
                </div>
            </header>

            <FilterBar filters={filters} resetHref="/admin/rooms" showReset={hasFilters}>
                <div className="ls-field ls-filter-field ls-filter-field--grow">
                    <label className="ls-label" htmlFor="f-q">{t('common.search')}</label>
                    <div className="ls-search"><Icon name="search" /><input id="f-q" type="search" className="ls-input" placeholder={tp('search_rooms')} value={values.q} onChange={(e) => set('q', e.target.value)} /></div>
                </div>
                <div className="ls-field ls-filter-field">
                    <label className="ls-label" htmlFor="f-owner">{tp('col.workspace')}</label>
                    <select id="f-owner" className="ls-select" value={values.owner}
                        onChange={(e) => { set('owner', e.target.value); apply({ owner: e.target.value, location: '' }); }}>
                        <option value="">{tp('all_workspaces')}</option>
                        {owners.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </select>
                </div>
                {locations.length > 0 && (
                    <div className="ls-field ls-filter-field">
                        <label className="ls-label" htmlFor="f-loc">{tp('col.location')}</label>
                        <select id="f-loc" className="ls-select" value={values.location} onChange={(e) => set('location', e.target.value)}>
                            <option value="">{tp('all_locations')}</option>
                            {locations.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
                        </select>
                    </div>
                )}
                <div className="ls-field ls-filter-field">
                    <label className="ls-label" htmlFor="f-type">{tp('col.type')}</label>
                    <select id="f-type" className="ls-select" value={values.type} onChange={(e) => set('type', e.target.value)}>
                        <option value="">{t('common.all')}</option>
                        {types.map((ty) => <option key={ty} value={ty}>{t(`room_type.${ty}`)}</option>)}
                    </select>
                </div>
                <div className="ls-field ls-filter-field">
                    <label className="ls-label" htmlFor="f-av">{tp('availability')}</label>
                    <select id="f-av" className="ls-select" value={values.available} onChange={(e) => set('available', e.target.value)}>
                        <option value="">{t('common.all')}</option>
                        <option value="1">{tp('available')}</option>
                        <option value="0">{tp('unavailable')}</option>
                    </select>
                </div>
                <PeriodFields filters={filters} />
                <label className="ls-adm-check"><input type="checkbox" checked={values.idle === '1'} onChange={(e) => set('idle', e.target.checked ? '1' : '')} /> {tp('idle_only')}</label>
            </FilterBar>

            <section className="ls-card">
                {rooms.data.length === 0 ? (
                    <EmptyState title={hasFilters ? tp('no_match') : tp('no_rooms')} text={hasFilters ? tp('no_match_text') : null} />
                ) : (
                    <>
                        <div className="ls-table-wrap">
                            <table className="ls-table ls-adm-table">
                                <thead><tr>
                                    {sh('name', tp('col.room'), undefined, 'asc')}
                                    <th scope="col">{tp('col.workspace')}</th>
                                    <th scope="col">{tp('col.location')}</th>
                                    <th scope="col">{tp('col.type')}</th>
                                    {sh('capacity', tp('col.capacity'), 'is-num')}
                                    {sh('price', tp('col.pricing'))}
                                    {sh('bookings', tp('col.bookings_period'), 'is-num')}
                                    {sh('earnings', tp('col.earnings_period'), 'is-num')}
                                    <th scope="col">{t('common.status')}</th>
                                </tr></thead>
                                <tbody>
                                    {rooms.data.map((room) => (
                                        <tr key={room.id} {...rowLink(`/admin/rooms/${room.id}`)}>
                                            <td><Link href={`/admin/rooms/${room.id}`} className="ls-adm-ident-name">{room.name}</Link></td>
                                            <td><Link href={`/admin/owners/${room.owner_id}`} className="ls-link">{room.business ?? '—'}</Link></td>
                                            <td>{room.location_id ? <Link href={`/admin/locations/${room.location_id}`} className="ls-link">{room.location}</Link> : '—'}</td>
                                            <td>{room.type}</td>
                                            <td className="is-num">{room.capacity}</td>
                                            <td className="ls-faint">{room.pricing}</td>
                                            <td className="is-num">{num(room.period_bookings)}</td>
                                            <td className="is-money">{money(room.period_earnings)}</td>
                                            <td>{room.is_available
                                                ? <span className="ls-status"><span className="ls-dot" />{tp('available')}</span>
                                                : <Badge tone="neutral">{tp('unavailable')}</Badge>}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="ls-adm-pager">
                            <span className="ls-faint">{tp('showing', { from: rooms.from, to: rooms.to, total: rooms.total })} · {tp('money_note_period')}</span>
                            <Pagination paginator={rooms} />
                        </div>
                    </>
                )}
            </section>
        </div>
    );
}
