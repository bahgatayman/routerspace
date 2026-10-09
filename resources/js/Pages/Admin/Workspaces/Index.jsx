import rowLink from '../../../Components/Admin/rowLink';
import { Link, usePage } from '@inertiajs/react';
import { FilterBar, PeriodFields, useFilters } from '../../../Components/analytics';
import { Avatar, Badge, Button, EmptyState, Icon, Pagination, SortHeader, cx } from '../../../Components/ui';
import { money, num } from '../../../lib/format';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';

const STATUS_TONE = { active: 'ok', expiring_soon: 'warn', expired: 'danger', disabled: 'danger', never: 'neutral' };

export default function WorkspacesIndex({ owners, counts, chips, total, plans, filters: initial, sort, dir, range }) {
    usePageTitle(t('admin_platform.nav.workspaces'));
    const { url } = usePage();
    const query = new URLSearchParams(url.split('?')[1] || '');
    const hasFilters = ['q', 'status', 'plan', 'joined_from', 'joined_to', 'preset'].some((k) => query.has(k));
    const tp = (k, r) => t(`admin_platform.${k}`, r);
    const filters = useFilters({
        status: initial.status || '', q: initial.q || '', plan: initial.plan || '',
        joined_from: initial.joined_from || '', joined_to: initial.joined_to || '',
        preset: range.preset, from: range.from, to: range.to,
        sort: query.get('sort') || '', dir: query.get('dir') || '',
    });
    const { values, set } = filters;
    const sortProps = { sort, dir };
    const rows = owners.data;

    return (
        <div className="ls-adm">
            <header className="ls-page-head ls-adm-head">
                <div>
                    <h1 className="ls-title">{tp('nav.workspaces')} <span className="ls-count">{num(total)}</span></h1>
                    <p className="ls-subtitle">{tp('directory_sub')}</p>
                </div>
                <div className="ls-actions">
                    <Button variant="primary" icon="plus" href="/admin/owners/create">{t('admin.add_owner')}</Button>
                </div>
            </header>

            <nav className="ls-chips" aria-label={t('common.status')}>
                <Link href={chips.all} preserveScroll className={cx('ls-chip', !initial.status && 'is-active')}>{t('common.all')} <span className="ls-chip-count">{total}</span></Link>
                {Object.entries(counts).map(([s, n]) => (
                    <Link key={s} href={chips[s]} preserveScroll className={cx('ls-chip', initial.status === s && 'is-active')} data-status-chip={s}>
                        {tp(`ws_status.${s}`)} <span className="ls-chip-count">{n}</span>
                    </Link>
                ))}
            </nav>

            <FilterBar filters={filters} resetHref="/admin/workspaces" showReset={hasFilters}>
                <div className="ls-field ls-filter-field ls-filter-field--grow">
                    <label className="ls-label" htmlFor="f-q">{t('common.search')}</label>
                    <div className="ls-search">
                        <Icon name="search" />
                        <input id="f-q" type="search" className="ls-input" placeholder={tp('search_ws')} autoComplete="off" value={values.q} onChange={(e) => set('q', e.target.value)} />
                    </div>
                </div>
                <div className="ls-field ls-filter-field">
                    <label className="ls-label" htmlFor="f-plan">{t('nav.plans')}</label>
                    <select id="f-plan" className="ls-select" value={values.plan} onChange={(e) => set('plan', e.target.value)}>
                        <option value="">{tp('all_plans')}</option>
                        {plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </select>
                </div>
                <div className="ls-field ls-filter-field">
                    <label className="ls-label" htmlFor="f-jf">{tp('joined_from')}</label>
                    <input id="f-jf" type="date" className="ls-input" dir="ltr" value={values.joined_from} onChange={(e) => set('joined_from', e.target.value)} />
                </div>
                <div className="ls-field ls-filter-field">
                    <label className="ls-label" htmlFor="f-jt">{tp('joined_to')}</label>
                    <input id="f-jt" type="date" className="ls-input" dir="ltr" value={values.joined_to} onChange={(e) => set('joined_to', e.target.value)} />
                </div>
                <PeriodFields filters={filters} />
            </FilterBar>

            <section className="ls-card" aria-label={tp('nav.workspaces')}>
                {rows.length === 0 ? (
                    <EmptyState title={hasFilters ? tp('no_match') : tp('no_workspaces')} text={hasFilters ? tp('no_match_text') : null}>
                        {hasFilters && <Link href="/admin/workspaces" className="ls-btn ls-btn--secondary">{tp('reset')}</Link>}
                    </EmptyState>
                ) : (
                    <>
                        <div className="ls-table-wrap">
                            <table className="ls-table ls-adm-table">
                                <thead><tr>
                                    <SortHeader field="name" label={tp('col.workspace')} defaultDir="asc" {...sortProps} />
                                    <th scope="col">{t('common.status')}</th>
                                    <th scope="col">{t('nav.plans')}</th>
                                    <SortHeader field="locations" label={tp('nav.locations')} className="is-num" {...sortProps} />
                                    <SortHeader field="rooms" label={tp('nav.rooms')} className="is-num" {...sortProps} />
                                    <SortHeader field="products" label={tp('col.products')} className="is-num" {...sortProps} />
                                    <SortHeader field="bookings" label={tp('col.bookings_period')} className="is-num" {...sortProps} />
                                    <SortHeader field="earnings" label={tp('col.earnings_period')} className="is-num" {...sortProps} />
                                    <SortHeader field="expires" label={tp('col.expires')} defaultDir="asc" {...sortProps} />
                                    <SortHeader field="activity" label={tp('col.last_activity')} {...sortProps} />
                                    <SortHeader field="created" label={tp('col.joined')} {...sortProps} />
                                </tr></thead>
                                <tbody>
                                    {rows.map((o) => (
                                        <tr key={o.id} {...rowLink(`/admin/owners/${o.id}`)}>
                                            <td>
                                                <div className="ls-adm-ident">
                                                    <Avatar name={o.name} size="sm" />
                                                    <span>
                                                        <Link href={`/admin/owners/${o.id}`} className="ls-adm-ident-name">{o.name}</Link>
                                                        <small>#{o.id} · {o.owner_name} · <bdi dir="ltr">{o.email}</bdi></small>
                                                    </span>
                                                </div>
                                            </td>
                                            <td><Badge tone={STATUS_TONE[o.status] || 'neutral'}>{t(`admin_biz.status.${o.status}`)}</Badge></td>
                                            <td>{o.plan || '—'}</td>
                                            <td className="is-num">{o.locations_count}</td>
                                            <td className="is-num">{o.rooms_count}</td>
                                            <td className="is-num">{o.products_count}</td>
                                            <td className="is-num">{num(o.period_bookings)}</td>
                                            <td className="is-money">{money(o.earnings)}</td>
                                            <td className="ls-nowrap">{o.expires || '—'}</td>
                                            <td className="ls-nowrap ls-faint">{o.last_activity || '—'}</td>
                                            <td className="ls-nowrap ls-faint">{o.joined}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="ls-adm-pager">
                            <span className="ls-faint">{tp('showing', { from: owners.from, to: owners.to, total: owners.total })} · {tp('money_note_period')}</span>
                            <Pagination paginator={owners} />
                        </div>
                    </>
                )}
            </section>
        </div>
    );
}
