import { Link, usePage } from '@inertiajs/react';
import { FilterBar, PeriodFields, Stat, useFilters } from '../../../Components/analytics';
import { Icon, Pagination, cx, withQuery } from '../../../Components/ui';
import { money, num } from '../../../lib/format';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';
import BookingsTable from './BookingsTable';

const tp = (key, params) => t(`admin_platform.${key}`, params);

export default function AdminBookingsIndex({ bookings, stats, owners, locations, rooms, filters: f, sort, dir, range, options }) {
    usePageTitle(t('nav.bookings'));
    const { url } = usePage();
    const filters = useFilters({
        status: f.status || '', q: f.search || '', owner: f.owner || '', location: f.location || '', room: f.room || '',
        payment: f.payment || '', type: f.type || '',
        preset: f.has_range ? range.preset : '', from: f.has_range ? range.from : '', to: f.has_range ? range.to : '',
    });
    const { values, set, apply } = filters;
    const hasFilters = !!(f.search || f.owner || f.location || f.room || f.status || f.payment || f.type || f.has_range);
    const chip = (s) => withQuery(url, { status: s, page: null });

    return (
        <div className="ls-adm">
            <header className="ls-page-head ls-adm-head">
                <div>
                    <h1 className="ls-title">{t('nav.bookings')} <span className="ls-count">{num(bookings.total)}</span></h1>
                    <p className="ls-subtitle">{f.has_range ? tp('bookings_sub_range', { from: range.from_label, to: range.to_label }) : tp('bookings_sub_all')}</p>
                </div>
            </header>

            <FilterBar filters={filters} resetHref="/admin/bookings" showReset={hasFilters}>
                <div className="ls-field ls-filter-field ls-filter-field--grow">
                    <label className="ls-label" htmlFor="f-q">{t('common.search')}</label>
                    <div className="ls-search"><Icon name="search" /><input id="f-q" type="search" className="ls-input" placeholder={tp('search_bookings')} value={values.q} onChange={(e) => set('q', e.target.value)} /></div>
                </div>
                <div className="ls-field ls-filter-field">
                    <label className="ls-label" htmlFor="f-owner">{tp('col.workspace')}</label>
                    <select id="f-owner" className="ls-select" value={values.owner}
                        onChange={(e) => { set('owner', e.target.value); apply({ owner: e.target.value, location: '', room: '' }); }}>
                        <option value="">{tp('all_workspaces')}</option>
                        {owners.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </select>
                </div>
                {locations.length > 1 && (
                    <div className="ls-field ls-filter-field">
                        <label className="ls-label" htmlFor="f-loc">{tp('col.location')}</label>
                        <select id="f-loc" className="ls-select" value={values.location} onChange={(e) => set('location', e.target.value)}>
                            <option value="">{tp('all_locations')}</option>
                            {locations.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
                        </select>
                    </div>
                )}
                {rooms.length > 0 && (
                    <div className="ls-field ls-filter-field">
                        <label className="ls-label" htmlFor="f-room">{tp('col.room')}</label>
                        <select id="f-room" className="ls-select" value={values.room} onChange={(e) => set('room', e.target.value)}>
                            <option value="">{tp('all_rooms')}</option>
                            {rooms.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
                        </select>
                    </div>
                )}
                <div className="ls-field ls-filter-field">
                    <label className="ls-label" htmlFor="f-pay">{tp('col.payment')}</label>
                    <select id="f-pay" className="ls-select" value={values.payment} onChange={(e) => set('payment', e.target.value)}>
                        <option value="">{t('common.all')}</option>
                        {options.payments.map((p) => <option key={p} value={p}>{tp(`payment.${p}`)}</option>)}
                    </select>
                </div>
                <div className="ls-field ls-filter-field">
                    <label className="ls-label" htmlFor="f-type">{tp('col.type')}</label>
                    <select id="f-type" className="ls-select" value={values.type} onChange={(e) => set('type', e.target.value)}>
                        <option value="">{t('common.all')}</option>
                        {options.types.map((ty) => <option key={ty} value={ty}>{tp(`types.${ty}`)}</option>)}
                    </select>
                </div>
                {f.has_range ? (
                    <PeriodFields filters={filters} />
                ) : (
                    <div className="ls-field ls-filter-field">
                        <label className="ls-label" htmlFor="f-preset">{tp('period')}</label>
                        <select id="f-preset" className="ls-select" value={values.preset}
                            onChange={(e) => { set('preset', e.target.value); apply({ preset: e.target.value }); }}>
                            <option value="">{tp('presets.all_time')}</option>
                            {options.presets.filter((p) => p !== 'custom').map((p) => <option key={p} value={p}>{tp(`presets.${p}`)}</option>)}
                        </select>
                    </div>
                )}
            </FilterBar>

            <div className="ls-akpis">
                <Stat label={tp('kpi.bookings')} value={num(stats.total)} />
                <Stat label={tp('kpi.gbv')} value={money(stats.gbv)} help={tp('help.gbv')} />
                <Stat label={tp('collected')} value={money(stats.collected)} tone="revenue" help={tp('help.collected')} />
                <Stat label={tp('kpi.outstanding')} value={money(stats.outstanding)} tone={stats.outstanding > 0 ? 'warn' : null}
                    help={tp('help.outstanding')} href={withQuery(url, { payment: 'due', page: null })} />
            </div>

            <nav className="ls-chips" aria-label={t('common.status')}>
                <Link href={chip(null)} preserveScroll className={cx('ls-chip', !f.status && 'is-active')}>{t('common.all')} <span className="ls-chip-count">{stats.total}</span></Link>
                {options.statuses.filter((s) => (stats.by_status[s] || 0) > 0 || f.status === s).map((s) => (
                    <Link key={s} href={chip(s)} preserveScroll className={cx('ls-chip', f.status === s && 'is-active')}>
                        {tp(`status.${s}`)} <span className="ls-chip-count">{stats.by_status[s] || 0}</span>
                    </Link>
                ))}
            </nav>

            <section className="ls-card">
                <BookingsTable bookings={bookings.data} sort={sort} dir={dir} />
                {bookings.data.length > 0 && (
                    <div className="ls-adm-pager">
                        <span className="ls-faint">{tp('showing', { from: bookings.from, to: bookings.to, total: bookings.total })}</span>
                        <Pagination paginator={bookings} />
                    </div>
                )}
            </section>
            <p className="ls-faint ls-adm-foot">{tp('bookings_live_note')}</p>
        </div>
    );
}
