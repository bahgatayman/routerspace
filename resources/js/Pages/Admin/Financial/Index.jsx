import { Fragment } from 'react';
import { Link, usePage } from '@inertiajs/react';
import rowLink from '../../../Components/Admin/rowLink';
import { ChartCard, FilterBar, PeriodFields, Stat, useFilters } from '../../../Components/analytics';
import { Badge, EmptyState, Pagination, SortHeader } from '../../../Components/ui';
import { money, num } from '../../../lib/format';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';

const tp = (key, params) => t(`admin_platform.${key}`, params);
const TYPE_TONE = { subscription: 'info', booking: 'neutral', sale: 'neutral' };
const RULES = ['platform_revenue', 'earnings', 'gbv', 'outstanding', 'mrr', 'sessions'];
const UNTRACKED = ['refunds', 'failed_payments', 'commissions', 'providers', 'transaction_ids'];

export default function AdminFinancialIndex({ range, cards: c, charts, transactions, owners, plans, filters: f, sort, dir, options }) {
    usePageTitle(t('nav.financial'));
    const { url } = usePage();
    const qs = new URLSearchParams(url.split('?')[1] || '');
    const hasFilters = ['owner', 'plan', 'type', 'payment', 'preset'].some((k) => qs.has(k));
    const filters = useFilters({
        preset: range.preset, from: range.from || '', to: range.to || '',
        owner: f.owner || '', plan: f.plan || '', type: f.type || '', payment: f.payment || '',
    });
    const { values, set } = filters;
    const rows = transactions.data;

    return (
        <div className="ls-adm">
            <header className="ls-page-head ls-adm-head">
                <div>
                    <h1 className="ls-title">{t('nav.financial')}</h1>
                    <p className="ls-subtitle">{tp('financial_sub', { from: range.from_label, to: range.to_label })}</p>
                </div>
            </header>

            <FilterBar filters={filters} resetHref="/admin/financial" showReset={hasFilters}>
                <PeriodFields filters={filters} />
                <div className="ls-field ls-filter-field">
                    <label className="ls-label" htmlFor="f-owner">{tp('col.workspace')}</label>
                    <select id="f-owner" className="ls-select" value={values.owner} onChange={(e) => set('owner', e.target.value)}>
                        <option value="">{tp('all_workspaces')}</option>
                        {owners.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </select>
                </div>
                <div className="ls-field ls-filter-field">
                    <label className="ls-label" htmlFor="f-plan">{t('nav.plans')}</label>
                    <select id="f-plan" className="ls-select" value={values.plan} onChange={(e) => set('plan', e.target.value)}>
                        <option value="">{tp('all_plans')}</option>
                        {plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </select>
                </div>
                <div className="ls-field ls-filter-field">
                    <label className="ls-label" htmlFor="f-type">{tp('tx_type')}</label>
                    <select id="f-type" className="ls-select" value={values.type} onChange={(e) => set('type', e.target.value)}>
                        <option value="">{t('common.all')}</option>
                        {options.types.map((ty) => <option key={ty} value={ty}>{tp(`tx.${ty}`)}</option>)}
                    </select>
                </div>
                <div className="ls-field ls-filter-field">
                    <label className="ls-label" htmlFor="f-pay">{tp('col.payment')}</label>
                    <select id="f-pay" className="ls-select" value={values.payment} onChange={(e) => set('payment', e.target.value)}>
                        <option value="">{t('common.all')}</option>
                        {options.payments.map((p) => <option key={p} value={p}>{tp(`payment.${p}`)}</option>)}
                    </select>
                </div>
            </FilterBar>

            <section aria-labelledby="pl-title">
                <h2 className="ls-adm-section" id="pl-title">{tp('section.platform_money')}</h2>
                <div className="ls-akpis ls-akpis--money">
                    <Stat label={tp('kpi.platform_revenue')} value={money(c.platform_revenue.value)} change={c.platform_revenue.change} tone="brand" help={tp('help.platform_revenue')} />
                    <Stat label={tp('kpi.mrr')} value={money(c.mrr.value)} help={tp('help.mrr')} sub={tp('right_now')} />
                </div>
            </section>

            <section aria-labelledby="ws-title">
                <h2 className="ls-adm-section" id="ws-title">{tp('section.workspace_money')}</h2>
                <div className="ls-akpis">
                    <Stat label={tp('kpi.earnings')} value={money(c.earnings.value)} change={c.earnings.change} tone="revenue" help={tp('help.earnings')} />
                    <Stat label={tp('kpi.booking_earnings')} value={money(c.booking_earnings.value)} change={c.booking_earnings.change} />
                    <Stat label={tp('kpi.sales')} value={money(c.sales.value)} change={c.sales.change} help={tp('help.sales')} />
                    <Stat label={tp('kpi.gbv')} value={money(c.gbv.value)} change={c.gbv.change} help={tp('help.gbv')} />
                    <Stat label={tp('kpi.outstanding')} value={money(c.outstanding.value)} tone={c.outstanding.value > 0 ? 'warn' : null}
                        help={tp('help.outstanding')} sub={tp('all_time')} href={`/admin/bookings?payment=due${f.owner ? `&owner=${f.owner}` : ''}`} />
                    <Stat label={tp('kpi.discounts')} value={money(c.discounts.value)} change={c.discounts.change} help={tp('help.discounts')} />
                    <Stat label={tp('kpi.package_value')} value={money(c.package_value.value)} change={c.package_value.change} help={tp('help.package_value')} />
                </div>
            </section>

            <div className="ls-adm-grid">
                <ChartCard id="f-earn" title={tp('chart.earnings_title')} note={tp('chart.earnings_split_note')} spec={charts.earnings} />
                <ChartCard id="f-plat" title={tp('chart.platform_title')} note={tp('chart.platform_note')} spec={charts.platform} />
                <ChartCard id="f-pay" title={tp('chart.payments_title')} note={tp('chart.click_slice')} spec={charts.payments} />
                <ChartCard id="f-plans" title={tp('chart.plan_revenue_title')} spec={charts.plans} />
                <ChartCard id="f-top" title={tp('chart.top_title')} note={tp('chart.top_note')} spec={charts.top} height={Math.max(180, 34 * charts.top.labels.length + 40)} />

                <section className="ls-card" aria-labelledby="rules-title">
                    <div className="ls-card-head"><h2 className="ls-card-title" id="rules-title">{tp('rules_title')}</h2></div>
                    <div className="ls-card-body">
                        <dl className="ls-adm-rules">
                            {RULES.map((r) => (
                                <Fragment key={r}>
                                    <dt>{tp(`rules.${r}.name`)}</dt><dd>{tp(`rules.${r}.rule`)}</dd>
                                </Fragment>
                            ))}
                        </dl>
                        <h3 className="ls-section-title" style={{ marginTop: 'var(--space-5)' }}>{tp('not_tracked_title')}</h3>
                        <ul className="ls-adm-untracked">
                            {UNTRACKED.map((u) => (
                                <li key={u}><b>{tp(`not_tracked.${u}.name`)}</b> <Badge tone="neutral" dot={false}>{tp('not_tracked_badge')}</Badge><span>{tp(`not_tracked.${u}.needs`)}</span></li>
                            ))}
                        </ul>
                    </div>
                </section>
            </div>

            <section className="ls-card" id="transactions" aria-labelledby="tx-title">
                <div className="ls-card-head">
                    <div>
                        <h2 className="ls-card-title" id="tx-title">{tp('transactions')} <span className="ls-count">{num(transactions.total)}</span></h2>
                        <p className="ls-chart-note">{tp('transactions_note')}</p>
                    </div>
                </div>
                <div className="ls-card-body ls-card-body--flush">
                    {rows.length === 0 ? (
                        <EmptyState title={tp('no_transactions')} text={hasFilters ? tp('no_match_text') : null} />
                    ) : (
                        <>
                            <div className="ls-table-wrap">
                                <table className="ls-table ls-adm-table">
                                    <thead><tr>
                                        <SortHeader field="date" label={tp('col.date')} sort={sort} dir={dir} />
                                        <th scope="col">{tp('tx_type')}</th>
                                        <th scope="col">{tp('col.workspace')}</th>
                                        <th scope="col">{tp('related')}</th>
                                        <th scope="col">{tp('payer')}</th>
                                        <th scope="col">{t('common.status')}</th>
                                        <SortHeader field="amount" label={tp('amount')} sort={sort} dir={dir} className="is-num" />
                                    </tr></thead>
                                    <tbody>
                                        {rows.map((tx) => (
                                            <tr key={tx.key} {...rowLink(tx.url)} data-tx={tx.type}>
                                                <td className="ls-nowrap ls-num">{tx.date}</td>
                                                <td><Badge tone={TYPE_TONE[tx.type]} dot={false}>{tp(`tx.${tx.type}`)}</Badge></td>
                                                <td><Link href={`/admin/owners/${tx.owner_id}`} className="ls-link">{tx.workspace ?? '—'}</Link></td>
                                                <td>
                                                    {tx.type === 'subscription' && (tx.ref ?? '—')}
                                                    {tx.type === 'booking' && <><Link href={tx.url} className="ls-link">#{tx.id}</Link> · {tx.ref ?? '—'}</>}
                                                    {tx.type === 'sale' && <>{tp('sale_n', { id: tx.id })}{tx.ref && <> · <Link href={tx.url} className="ls-link">#{tx.ref}</Link></>}</>}
                                                </td>
                                                <td>{tx.payer ?? (tx.type === 'subscription' ? '—' : tp('walk_in'))}</td>
                                                <td>
                                                    {tx.type === 'subscription' ? tp('recorded') : tp(`status.${tx.status}`)}
                                                    {!tx.counted && <small className="ls-adm-sub">{tp('not_in_earnings')}</small>}
                                                </td>
                                                <td className="is-money">{money(tx.amount)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <div className="ls-adm-pager">
                                <span className="ls-faint">{tp('showing', { from: transactions.from, to: transactions.to, total: transactions.total })}</span>
                                <Pagination paginator={transactions} />
                            </div>
                        </>
                    )}
                </div>
            </section>
        </div>
    );
}
