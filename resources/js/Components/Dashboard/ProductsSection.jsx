import { Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { ChartCanvas } from '../analytics';
import { money } from '../../lib/format';
import { t } from '../../lib/i18n';

/*
 * Product Analytics section of the owner dashboard (only rendered for the
 * 'sales' feature). Every figure comes from ProductAnalyticsService via
 * DashboardController; the metric / view / rank toggles are pure client state.
 */
const ICON = {
    star: 'M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 10l-5.714 2.143L13 19l-2.286-6.857L5 10l5.714-2.143L13 1z',
    box: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
    money: 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    cart: 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m-10 0a1 1 0 100 2 1 1 0 000-2zm10 0a1 1 0 100 2 1 1 0 000-2z',
    check: 'M9 14l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
    archive: 'M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375C2.754 3.75 2.25 4.254 2.25 4.875v1.5c0 .621.504 1.125 1.125 1.125z',
};

function Icon({ tone, d, color }) {
    return (
        <div className={`ls-kpi-icon w-10 h-10 lg:w-12 lg:h-12 bg-${tone}-50 rounded-xl flex items-center justify-center shrink-0`}>
            <svg className={`w-5 h-5 lg:w-6 lg:h-6 ${color || `text-${tone}-600`}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={d} />
            </svg>
        </div>
    );
}

function Toggle({ options, value, onChange }) {
    return (
        <div className="inline-flex items-center gap-1 bg-gray-50 border border-gray-100 rounded-lg p-1" role="group">
            {options.map(([key, label]) => (
                <button key={key} type="button" aria-pressed={value === key} onClick={() => onChange(key)}
                    className="px-3 py-1 rounded-md text-xs font-medium transition"
                    style={value === key ? { background: '#2e4f8f', color: '#fff' } : { color: 'var(--color-text-secondary, #6b7280)' }}>
                    {label}
                </button>
            ))}
        </div>
    );
}

const METRIC_LABEL = { units: 'dashboard.metric_units', revenue: 'dashboard.metric_revenue', orders: 'dashboard.metric_orders' };

export default function ProductsSection({ products }) {
    const { summary, hasSales, series, top, share, lowStock, insights } = products;
    const [metric, setMetric] = useState('units');
    const [view, setView] = useState('ranking');
    const [rankBy, setRankBy] = useState('revenue');
    const metrics = [['units', t('dashboard.metric_units')], ['revenue', t('dashboard.metric_revenue')], ['orders', t('dashboard.metric_orders')]];

    const trendSpec = useMemo(() => ({
        type: 'line',
        labels: series.map((r) => r.label),
        money: metric === 'revenue',
        datasets: [{ label: t(METRIC_LABEL[metric]), data: series.map((r) => r[metric]), color: 'c1' }],
    }), [series, metric]);

    const topSpec = useMemo(() => {
        const sorted = [...top].sort((a, b) => b[rankBy] - a[rankBy]).slice(0, 10);
        return {
            type: 'bar',
            horizontal: true,
            labels: sorted.map((p) => p.name),
            money: rankBy === 'revenue',
            datasets: [{ label: t(METRIC_LABEL[rankBy]), data: sorted.map((p) => p[rankBy]), color: 'c1' }],
        };
    }, [top, rankBy]);

    const shareSpec = useMemo(() => ({
        type: 'doughnut',
        labels: share.map((p) => p.name),
        money: true,
        datasets: [{ label: t('dashboard.metric_revenue'), data: share.map((p) => p.revenue), color: ['c1', 'c2', 'c3', 'c4', 'warning', 'info', 'neutral'].slice(0, share.length) }],
    }), [share]);

    const KpiButton = ({ metricKey, children }) => (
        <button type="button" onClick={() => setMetric(metricKey)} className="text-left bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">{children}</button>
    );

    return (
        <div className="mb-8">
            <h2 className="font-semibold text-gray-900 mb-4">{t('dashboard.product_analytics')}</h2>

            <div className="ls-kpis-wrap mb-6"><div className="ls-kpis ls-kpis--6">
                <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
                    <div className="flex items-center justify-between">
                        <div className="min-w-0">
                            <p className="text-xs lg:text-sm font-medium text-gray-500">{t('dashboard.top_selling_product')}</p>
                            <p className="text-xl lg:text-2xl font-bold text-gray-900 mt-1 truncate">{summary.topProduct?.name ?? '—'}</p>
                            {summary.topProduct && <p className="text-xs text-gray-400 mt-1">{summary.topProduct.units} {t('dashboard.metric_units')}</p>}
                        </div>
                        <Icon tone="amber" d={ICON.star} />
                    </div>
                </div>

                <KpiButton metricKey="units">
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="text-xs lg:text-sm font-medium text-gray-500">{t('dashboard.total_units_sold')}</p>
                            <p className="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{summary.totalUnits}</p>
                        </div>
                        <Icon tone="blue" d={ICON.box} />
                    </div>
                </KpiButton>

                <KpiButton metricKey="revenue">
                    <div className="flex items-center justify-between">
                        <div className="min-w-0">
                            <p className="text-xs lg:text-sm font-medium text-gray-500">{t('dashboard.product_revenue')}</p>
                            <p className="text-2xl lg:text-3xl font-bold text-green-600 mt-1 whitespace-nowrap">{money(summary.productRevenue)}</p>
                        </div>
                        <Icon tone="green" d={ICON.money} />
                    </div>
                </KpiButton>

                <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
                    <div className="flex items-center justify-between">
                        <div className="min-w-0">
                            <p className="text-xs lg:text-sm font-medium text-gray-500">{t('dashboard.avg_product_order_value')}</p>
                            <p className="text-2xl lg:text-3xl font-bold text-gray-900 mt-1 whitespace-nowrap">{summary.avgOrderValue !== null ? money(summary.avgOrderValue) : '—'}</p>
                        </div>
                        <Icon tone="purple" d={ICON.cart} />
                    </div>
                </div>

                <KpiButton metricKey="orders">
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="text-xs lg:text-sm font-medium text-gray-500">{t('dashboard.product_orders')}</p>
                            <p className="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{summary.orderCount}</p>
                        </div>
                        <Icon tone="orange" d={ICON.check} />
                    </div>
                </KpiButton>

                <Link href="/products?stock=low" className="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6 hover:shadow-md transition block">
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="text-xs lg:text-sm font-medium text-gray-500">{t('dashboard.low_stock_products')}</p>
                            <p className={`text-2xl lg:text-3xl font-bold ${summary.lowStockCount > 0 ? 'text-red-600' : 'text-gray-900'} mt-1`}>{summary.lowStockCount}</p>
                        </div>
                        <Icon tone={summary.lowStockCount > 0 ? 'red' : 'gray'} d={ICON.archive} color={summary.lowStockCount > 0 ? undefined : 'text-gray-400'} />
                    </div>
                </Link>
            </div></div>

            {!hasSales ? (
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-8 text-center text-gray-400 mb-6">{t('dashboard.no_product_sales_for_period')}</div>
            ) : (
                <>
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6 mb-6">
                        <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
                            <h3 className="font-semibold text-gray-900">{t('dashboard.product_sales_trend')}</h3>
                            <Toggle options={metrics} value={metric} onChange={setMetric} />
                        </div>
                        <ChartCanvas spec={trendSpec} height={260} label={t('dashboard.product_sales_trend')} />
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 lg:gap-6 mb-6">
                        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6">
                            <div className="flex flex-wrap items-center justify-between gap-3 mb-4">
                                <h3 className="font-semibold text-gray-900">{t('dashboard.top_selling_products')}</h3>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Toggle options={[['ranking', t('dashboard.view_ranking')], ['share', t('dashboard.view_share')]]} value={view} onChange={setView} />
                                    {view === 'ranking' && <Toggle options={[['revenue', t('dashboard.metric_revenue')], ['units', t('dashboard.metric_units')]]} value={rankBy} onChange={setRankBy} />}
                                </div>
                            </div>
                            {view === 'ranking'
                                ? <ChartCanvas spec={topSpec} height={280} label={t('dashboard.top_selling_products')} />
                                : <ChartCanvas spec={shareSpec} height={280} label={t('dashboard.product_revenue_share')} />}
                        </div>

                        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6">
                            <h3 className="font-semibold text-gray-900 mb-4">{t('dashboard.product_insights')}</h3>
                            {insights.length === 0 && <p className="text-sm text-gray-400">{t('dashboard.no_insights_yet')}</p>}
                            {insights.map((text, i) => <p key={i} className="text-sm text-gray-700 py-2 border-b border-gray-50 last:border-0">{text}</p>)}
                        </div>
                    </div>
                </>
            )}

            {lowStock.length > 0 && (
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6 mb-6">
                    <h3 className="font-semibold text-gray-900 mb-4">{t('dashboard.low_stock_products')}</h3>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-left text-gray-500 border-b">
                                    <th className="pb-3">{t('table.th.name')}</th>
                                    <th className="pb-3">{t('inventory.current_stock')}</th>
                                    <th className="pb-3">{t('inventory.low_alert')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {lowStock.map((p) => (
                                    <tr key={p.id} className="border-b last:border-0">
                                        <td className="py-3 pe-3 font-medium text-gray-900">{p.name}</td>
                                        <td className="py-3 text-red-600 font-medium">{p.stock_quantity}</td>
                                        <td className="py-3 text-gray-500">{p.low_stock_threshold}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </div>
    );
}
