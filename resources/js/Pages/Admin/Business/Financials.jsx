import { ChartCard, FilterBar, PeriodFields, Stat, useFilters } from '../../../Components/analytics';
import { money, num } from '../../../lib/format';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';
import BusinessHeader from './Header';

export default function BusinessFinancials({ business, range, cards: c, chart, byRoom, byProduct, byCategory }) {
    usePageTitle(business.name, t('admin_biz.tabs.financials'));
    const filters = useFilters({ preset: range.preset, from: range.from, to: range.to });
    const tp = (k, r) => t(`admin_platform.${k}`, r);
    const breakdowns = [
        ['by_room', byRoom, 'room_name', 'revenue', 'bookings'],
        ['by_product', byProduct, 'name', 'revenue', 'quantity'],
        ['by_expense', byCategory, 'name', 'amount', null],
    ];

    return (
        <div className="ls-biz-page">
            <BusinessHeader business={business} active="financials" />

            <FilterBar filters={filters} variant="secondary">
                <PeriodFields filters={filters} />
            </FilterBar>

            <div className="ls-akpis">
                <Stat label={tp('kpi.earnings')} value={money(c.earnings.value)} change={c.earnings.change} tone="revenue" help={tp('help.earnings')} />
                <Stat label={tp('kpi.booking_earnings')} value={money(c.booking_earnings.value)} />
                <Stat label={tp('kpi.sales')} value={money(c.sales.value)} />
                <Stat label={tp('expenses')} value={money(c.expenses.value)} change={c.expenses.change} invert />
                <Stat label={tp('net_after_expenses')} value={money(c.net.value)} tone={c.net.value < 0 ? 'warn' : null} help={tp('help.net')} />
                <Stat label={tp('kpi.gbv')} value={money(c.gbv.value)} help={tp('help.gbv')} />
                <Stat label={tp('kpi.outstanding')} value={money(c.outstanding.value)} sub={tp('all_time')} tone={c.outstanding.value > 0 ? 'warn' : null} href={`/admin/bookings?payment=due&owner=${business.id}`} />
                <Stat label={tp('kpi.discounts')} value={money(c.discounts.value)} />
                <Stat label={tp('kpi.package_value')} value={money(c.package_value.value)} help={tp('help.package_value')} />
                <Stat label={tp('paid_to_platform')} value={money(c.platform_revenue.value)} tone="brand" href={`/admin/owners/${business.id}/subscription`} />
            </div>

            <ChartCard id="b-earn" title={tp('chart.earnings_title')} note={tp('chart.click_bucket')} spec={chart} />

            <div className="ls-adm-grid ls-adm-grid--3">
                {breakdowns.map(([key, rows, nameCol, valCol, countCol]) => (
                    <section key={key} className="ls-card">
                        <div className="ls-card-head"><h2 className="ls-card-title">{tp(`breakdown.${key}`)}</h2></div>
                        <div className="ls-card-body">
                            {rows.length === 0 ? (
                                <p className="ls-faint" style={{ margin: 0 }}>{tp('nothing_in_period')}</p>
                            ) : (
                                <ul className="ls-adm-list">
                                    {rows.map((r, i) => (
                                        <li key={i}>
                                            <span className="ls-trunc">{r[nameCol]}{countCol && <small className="ls-faint"> · {num(r[countCol])}</small>}</span>
                                            <span className="ls-num">{money(r[valCol])}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </section>
                ))}
            </div>
        </div>
    );
}
