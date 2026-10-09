import { Link, usePage } from '@inertiajs/react';
import { ChartCard, FilterBar, PeriodFields, Stat, useFilters } from '../../../Components/analytics';
import { Avatar, Badge, Banner, EmptyState, Icon } from '../../../Components/ui';
import { money, num } from '../../../lib/format';
import { t, tc } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';

const LEVEL_ICON = { danger: 'alert', warning: 'alert', info: 'bell', positive: 'check-circle' };

function RecentCard({ id, title, viewAll, empty, children }) {
    const items = [].concat(children).filter(Boolean);
    return (
        <section className="ls-card" aria-labelledby={id}>
            <div className="ls-card-head">
                <h2 className="ls-card-title" id={id}>{title}</h2>
                {viewAll && <Link href={viewAll} className="ls-link">{t('admin_platform.view_all')}</Link>}
            </div>
            <div className="ls-card-body">
                {items.length ? items : <p className="ls-faint">{empty || t('admin_platform.nothing_yet')}</p>}
            </div>
        </section>
    );
}

export default function AdminDashboard({ range, planId, plans, kpis: k, charts, topChartHeight, byPlan, insights, recentOwners, recentBookings, recentPayments, adminActions, expiring, pendingRenewals }) {
    usePageTitle(t('nav.dashboard'));
    const { url } = usePage();
    const query = new URLSearchParams(url.split('?')[1] || '');
    const tp = (key, r) => t(`admin_platform.${key}`, r);
    const q = `preset=${range.preset}&from=${range.from}&to=${range.to}`;
    const filters = useFilters({ preset: range.preset, from: range.from, to: range.to, plan: planId || '' });

    const moneyCards = [
        { label: tp('kpi.platform_revenue'), value: money(k.platform_revenue.value), change: k.platform_revenue.change, help: tp('help.platform_revenue'), tone: 'brand', href: `/admin/financial?type=subscription&${q}` },
        { label: tp('kpi.earnings'), value: money(k.earnings.value), change: k.earnings.change, help: tp('help.earnings'), tone: 'revenue', href: `/admin/financial?${q}` },
        { label: tp('kpi.gbv'), value: money(k.gbv.value), change: k.gbv.change, help: tp('help.gbv') },
        { label: tp('kpi.outstanding'), value: money(k.outstanding.value), help: tp('help.outstanding'), tone: k.outstanding.value > 0 ? 'warn' : null, sub: tp('all_time'), href: '/admin/bookings?payment=due' },
        { label: tp('kpi.mrr'), value: money(k.mrr.value), help: tp('help.mrr'), sub: tp('right_now') },
    ];
    const opsCards = [
        { label: tp('kpi.workspaces'), value: `${num(k.active_workspaces.value)} / ${num(k.workspaces.value)}`, help: tp('help.workspaces'), sub: tp('active_of_total'), href: '/admin/workspaces' },
        { label: tp('kpi.new_workspaces'), value: num(k.new_workspaces.value), change: k.new_workspaces.change, sub: k.growth_rate.value !== null ? tp('growth', { pct: k.growth_rate.value }) : null, href: '/admin/workspaces?sort=created&dir=desc' },
        { label: tp('kpi.bookings'), value: num(k.bookings.value), change: k.bookings.change, href: `/admin/bookings?${q}` },
        { label: tp('kpi.completed'), value: num(k.completed.value), change: k.completed.change, href: `/admin/bookings?status=completed&${q}` },
        { label: tp('kpi.cancellation_rate'), value: `${num(k.cancellation_rate.value, 1)}%`, change: k.cancellation_rate.change, invert: true, help: tp('help.cancellation_rate'), href: `/admin/bookings?status=cancelled&${q}` },
        { label: tp('kpi.avg_booking_value'), value: money(k.avg_booking_value.value), change: k.avg_booking_value.change, help: tp('help.avg_booking_value') },
        { label: tp('kpi.active_customers'), value: num(k.active_customers.value), change: k.active_customers.change, help: tp('help.active_customers') },
        { label: tp('kpi.discounts'), value: money(k.discounts.value), change: k.discounts.change, help: tp('help.discounts') },
        { label: tp('kpi.package_value'), value: money(k.package_value.value), change: k.package_value.change, help: tp('help.package_value') },
        { label: tp('kpi.rooms'), value: num(k.rooms.value), href: '/admin/rooms' },
        { label: tp('kpi.products'), value: num(k.products.value) },
    ];

    return (
        <div className="ls-adm">
            <header className="ls-page-head ls-adm-head">
                <div>
                    <h1 className="ls-title">{tp('dashboard_title')}</h1>
                    <p className="ls-subtitle">{tp('dashboard_sub', { from: range.from_label, to: range.to_label })}</p>
                </div>
            </header>

            <FilterBar filters={filters} resetHref="/admin/dashboard" showReset={['preset', 'plan', 'from', 'to'].some((key) => query.has(key))}>
                <PeriodFields filters={filters} />
                <div className="ls-field ls-filter-field">
                    <label className="ls-label" htmlFor="f-plan">{t('nav.plans')}</label>
                    <select id="f-plan" className="ls-select" value={filters.values.plan}
                        onChange={(e) => { filters.set('plan', e.target.value); filters.apply({ plan: e.target.value }); }}>
                        <option value="">{tp('all_plans')}</option>
                        {plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                    </select>
                </div>
            </FilterBar>

            {pendingRenewals > 0 && (
                <Banner tone="warn">
                    {tc('admin_platform.pending_renewals', pendingRenewals, { count: pendingRenewals })}{' '}
                    <Link href="/admin/subscription-requests" className="ls-link">{tp('review')}</Link>
                </Banner>
            )}

            <section aria-labelledby="money-title">
                <h2 className="ls-adm-section" id="money-title">{tp('section.money')}</h2>
                <div className="ls-akpis ls-akpis--money">
                    {moneyCards.map((c) => <Stat key={c.label} {...c} />)}
                </div>
            </section>

            <section aria-labelledby="ops-title">
                <h2 className="ls-adm-section" id="ops-title">{tp('section.activity')}</h2>
                <div className="ls-akpis">
                    {opsCards.map((c) => <Stat key={c.label} {...c} />)}
                </div>
            </section>

            <div className="ls-adm-grid">
                <ChartCard id="c-earn" title={tp('chart.earnings_title')} note={tp('chart.earnings_note')} spec={charts.earnings} />
                <ChartCard id="c-plat" title={tp('chart.platform_title')} note={tp('chart.platform_note')} spec={charts.platform} />
                <ChartCard id="c-book" title={tp('chart.bookings_title')} note={tp('chart.click_bucket')} spec={charts.bookings} />
                <ChartCard id="c-status" title={tp('chart.status_title')} note={tp('chart.click_slice')} spec={charts.status} />
                <ChartCard id="c-top" title={tp('chart.top_title')} note={tp('chart.top_note')} spec={charts.top} height={topChartHeight} />
                <ChartCard id="c-growth" title={tp('chart.growth_title')} note={tp('chart.growth_note')} spec={charts.growth} />
            </div>

            <section className="ls-card" aria-labelledby="insights-title">
                <div className="ls-card-head">
                    <div>
                        <h2 className="ls-card-title" id="insights-title">{tp('insights_title')}</h2>
                        <p className="ls-chart-note">{tp('insights_sub')}</p>
                    </div>
                </div>
                <div className="ls-card-body">
                    {insights.items.length === 0 ? (
                        <EmptyState title={tp('no_insights')} text={tp('no_insights_text')} />
                    ) : (
                        <ul className="ls-insights">
                            {insights.items.map((i, n) => (
                                <li key={`${i.key}-${n}`} className={`ls-insight is-${i.level}`} data-insight={i.key}>
                                    <span className="ls-insight-icon"><Icon name={LEVEL_ICON[i.level]} /></span>
                                    <div className="ls-insight-body">
                                        <b>{i.title}</b>
                                        <span className="ls-insight-metric">{i.metric}</span>
                                        <p>{i.why}</p>
                                        <p className="ls-insight-action"><span>{tp('suggested')}:</span> {i.action}</p>
                                    </div>
                                    {i.url && <Link href={i.url} className="ls-btn ls-btn--secondary ls-btn--sm">{tp('open')}</Link>}
                                </li>
                            ))}
                        </ul>
                    )}
                    <details className="ls-untracked">
                        <summary>{tp('needs_tracking')}</summary>
                        <ul>
                            {insights.untracked.map((u) => <li key={u}><b>{tp(`untracked.${u}.title`)}</b> — {tp(`untracked.${u}.needs`)}</li>)}
                        </ul>
                    </details>
                </div>
            </section>

            <div className="ls-adm-grid">
                <ChartCard id="c-plans" title={tp('chart.plans_title')} note={tp('chart.plans_note')} spec={charts.plans} />

                <section className="ls-card" aria-labelledby="planrev-title">
                    <div className="ls-card-head"><h2 className="ls-card-title" id="planrev-title">{tp('revenue_by_plan')}</h2></div>
                    <div className="ls-card-body ls-card-body--flush">
                        {byPlan.length === 0 ? (
                            <EmptyState title={tp('no_plan_data')} />
                        ) : (
                            <div className="ls-table-wrap">
                                <table className="ls-table">
                                    <thead><tr>
                                        <th scope="col">{t('nav.plans')}</th>
                                        <th scope="col" className="is-num">{tp('chart.active_workspaces')}</th>
                                        <th scope="col" className="is-num">{tp('kpi.mrr')}</th>
                                        <th scope="col" className="is-num">{tp('chart.renewals')}</th>
                                        <th scope="col" className="is-num">{tp('kpi.platform_revenue')}</th>
                                    </tr></thead>
                                    <tbody>
                                        {byPlan.map((row) => (
                                            <tr key={row.plan_id}>
                                                <td><Link href={`/admin/plans/${row.plan_id}`} className="ls-link">{row.name}</Link></td>
                                                <td className="is-num">{num(row.active)}</td>
                                                <td className="is-money">{money(row.mrr)}</td>
                                                <td className="is-num">{num(row.renewals)}</td>
                                                <td className="is-money">{money(row.revenue)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </section>
            </div>

            <div className="ls-adm-grid ls-adm-grid--3">
                <RecentCard id="recent-ws" title={tp('recent_workspaces')} viewAll="/admin/workspaces?sort=created&dir=desc">
                    {recentOwners.map((o) => (
                        <Link key={o.id} href={`/admin/owners/${o.id}`} className="ls-adm-row">
                            <Avatar name={o.name} size="sm" />
                            <span className="ls-adm-row-main"><b className="ls-trunc">{o.name}</b><small>{o.plan}</small></span>
                            <time dateTime={o.at_iso || undefined}>{o.ago}</time>
                        </Link>
                    ))}
                </RecentCard>

                <RecentCard id="recent-bk" title={tp('recent_bookings')} viewAll="/admin/bookings">
                    {recentBookings.map((b) => (
                        <Link key={b.id} href={`/admin/bookings/${b.id}`} className="ls-adm-row">
                            <span className="ls-adm-row-main"><b className="ls-trunc">#{b.id} · {b.room}</b><small className="ls-trunc">{b.business} · {b.customer}</small></span>
                            <span className="ls-adm-row-end"><span className="ls-num">{money(b.net)}</span><small>{tp(`status.${b.status}`)}</small></span>
                        </Link>
                    ))}
                </RecentCard>

                <RecentCard id="recent-pay" title={tp('recent_payments')} viewAll="/admin/financial?type=subscription">
                    {recentPayments.map((s) => (
                        <Link key={s.id} href={`/admin/owners/${s.owner_id}/subscription`} className="ls-adm-row">
                            <span className="ls-adm-row-main"><b className="ls-trunc">{s.business}</b><small>{s.plan} · {tc('admin_platform.months', s.months, { count: s.months })}</small></span>
                            <span className="ls-adm-row-end"><span className="ls-num">{money(s.amount)}</span><small>{s.ago}</small></span>
                        </Link>
                    ))}
                </RecentCard>

                <RecentCard id="expiring-t" title={tp('expiring_title')} viewAll="/admin/workspaces?status=expiring" empty={tp('none_expiring')}>
                    {expiring.map((o) => (
                        <Link key={o.id} href={`/admin/owners/${o.id}/subscription`} className="ls-adm-row">
                            <span className="ls-adm-row-main"><b className="ls-trunc">{o.name}</b><small>{o.plan}</small></span>
                            <span className="ls-adm-row-end"><Badge tone={o.days <= 3 ? 'danger' : 'warn'}>{tc('admin_platform.in_days', o.days, { days: o.days })}</Badge></span>
                        </Link>
                    ))}
                </RecentCard>

                <RecentCard id="admin-act" title={tp('admin_actions')}>
                    {adminActions.map((a) => (
                        <div key={a.id} className="ls-adm-row">
                            <span className="ls-adm-row-main">
                                <b className="ls-trunc">{a.text}</b>
                                <small className="ls-trunc">{a.admin}{a.owner_id && <> · <Link href={`/admin/owners/${a.owner_id}`} className="ls-link">{a.owner}</Link></>}</small>
                            </span>
                            <time dateTime={a.at_iso || undefined}>{a.ago}</time>
                        </div>
                    ))}
                </RecentCard>
            </div>
        </div>
    );
}
