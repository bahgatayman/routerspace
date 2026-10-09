import { Link } from '@inertiajs/react';
import { ChartCard, PeriodFields, Stat, useFilters } from '../../../Components/analytics';
import { Pagination, cx } from '../../../Components/ui';
import { money, num } from '../../../lib/format';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';
import BookingsTable from '../Bookings/BookingsTable';
import BusinessHeader from './Header';

export default function BusinessBookings({ business, workspace, range, status, stats, statusChart, chips, bookings }) {
    usePageTitle(business.name, t('admin_biz.tabs.bookings'));
    const filters = useFilters({ workspace: workspace?.id ?? '', status: status || '', preset: range.preset, from: range.from, to: range.to });
    const tp = (k, r) => t(`admin_platform.${k}`, r);

    return (
        <div className="ls-biz-page">
            <BusinessHeader business={business} active="bookings" workspaceId={workspace?.id ?? null} locationAware />

            <form className="ls-adm-filters" aria-label={tp('filters')} onSubmit={(e) => { e.preventDefault(); filters.apply(); }}>
                <PeriodFields filters={filters} />
                <div className="ls-filter-actions">
                    <button type="submit" className="ls-btn ls-btn--secondary">{tp('apply')}</button>
                    <Link href={`/admin/bookings?owner=${business.id}`} className="ls-btn ls-btn--ghost">{tp('advanced_filters')}</Link>
                </div>
            </form>

            <div className="ls-akpis">
                <Stat label={tp('kpi.bookings')} value={num(stats.total)} />
                <Stat label={tp('kpi.gbv')} value={money(stats.gbv)} help={tp('help.gbv')} />
                <Stat label={tp('collected')} value={money(stats.collected)} tone="revenue" help={tp('help.collected')} />
                <Stat label={tp('kpi.outstanding')} value={money(stats.outstanding)} tone={stats.outstanding > 0 ? 'warn' : null} />
            </div>

            <ChartCard id="biz-status" title={tp('chart.status_title')} note={tp('chart.click_slice')} spec={statusChart} height={220} />

            <nav className="ls-chips" aria-label={t('common.status')}>
                <Link href={chips.all} preserveScroll className={cx('ls-chip', !status && 'is-active')}>{t('common.all')} <span className="ls-chip-count">{stats.total}</span></Link>
                {chips.statuses.map((c) => (
                    <Link key={c.key} href={c.href} preserveScroll className={cx('ls-chip', status === c.key && 'is-active')}>
                        {tp(`status.${c.key}`)} <span className="ls-chip-count">{c.count}</span>
                    </Link>
                ))}
            </nav>

            <section className="ls-card">
                <BookingsTable bookings={bookings.data} hide={['workspace']} />
                {bookings.data.length > 0 && (
                    <div className="ls-adm-pager">
                        <span className="ls-faint">{tp('showing', { from: bookings.from, to: bookings.to, total: bookings.total })}</span>
                        <Pagination paginator={bookings} />
                    </div>
                )}
            </section>
        </div>
    );
}
