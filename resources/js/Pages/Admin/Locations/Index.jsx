import { Link } from '@inertiajs/react';
import rowLink from '../../../Components/Admin/rowLink';
import { FilterBar, useFilters } from '../../../Components/analytics';
import { Badge, EmptyState, Icon, Pagination } from '../../../Components/ui';
import { num } from '../../../lib/format';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';

const tp = (key, params) => t(`admin_platform.${key}`, params);

export default function AdminLocationsIndex({ locations, owners, filters: f }) {
    usePageTitle(tp('nav.locations'));
    const filters = useFilters({ q: f.q || '', owner: f.owner || '' });
    const { values, set } = filters;

    return (
        <div className="ls-adm">
            <header className="ls-page-head ls-adm-head">
                <div>
                    <h1 className="ls-title">{tp('nav.locations')} <span className="ls-count">{num(locations.total)}</span></h1>
                    <p className="ls-subtitle">{tp('locations_sub')}</p>
                </div>
            </header>

            <FilterBar filters={filters} resetHref="/admin/locations" showReset={!!(f.q || f.owner)}>
                <div className="ls-field ls-filter-field ls-filter-field--grow">
                    <label className="ls-label" htmlFor="f-q">{t('common.search')}</label>
                    <div className="ls-search"><Icon name="search" /><input id="f-q" type="search" className="ls-input" placeholder={tp('search_locations')} value={values.q} onChange={(e) => set('q', e.target.value)} /></div>
                </div>
                <div className="ls-field ls-filter-field">
                    <label className="ls-label" htmlFor="f-owner">{tp('col.workspace')}</label>
                    <select id="f-owner" className="ls-select" value={values.owner} onChange={(e) => set('owner', e.target.value)}>
                        <option value="">{tp('all_workspaces')}</option>
                        {owners.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </select>
                </div>
            </FilterBar>

            <section className="ls-card">
                {locations.data.length === 0 ? (
                    <EmptyState title={tp('no_locations')} />
                ) : (
                    <>
                        <div className="ls-table-wrap">
                            <table className="ls-table ls-adm-table">
                                <thead><tr>
                                    <th scope="col">{tp('col.location')}</th>
                                    <th scope="col">{tp('col.workspace')}</th>
                                    <th scope="col">{tp('col.city')}</th>
                                    <th scope="col" className="is-num">{tp('nav.rooms')}</th>
                                    <th scope="col">{t('common.status')}</th>
                                </tr></thead>
                                <tbody>
                                    {locations.data.map((l) => (
                                        <tr key={l.id} {...rowLink(`/admin/locations/${l.id}`)}>
                                            <td><Link href={`/admin/locations/${l.id}`} className="ls-adm-ident-name">{l.name}</Link>{l.address && <small className="ls-adm-sub">{l.address}</small>}</td>
                                            <td><Link href={`/admin/owners/${l.owner_id}?workspace=${l.id}`} className="ls-link">{l.business ?? '—'}</Link></td>
                                            <td>{l.city || '—'}</td>
                                            <td className="is-num">{l.available_rooms_count} / {l.rooms_count}</td>
                                            <td>{l.is_active
                                                ? <span className="ls-status"><span className="ls-dot" />{t('status.active')}</span>
                                                : <Badge tone="neutral">{t('status.inactive')}</Badge>}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="ls-adm-pager">
                            <span className="ls-faint">{tp('showing', { from: locations.from, to: locations.to, total: locations.total })}</span>
                            <Pagination paginator={locations} />
                        </div>
                    </>
                )}
            </section>
        </div>
    );
}
