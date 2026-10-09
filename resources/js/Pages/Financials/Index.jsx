import { Link } from '@inertiajs/react';
import PeriodFilter, { egp } from '../../Components/Financials/PeriodFilter';
import { num } from '../../lib/format';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

const CARD = 'bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6';
const BTN = 'px-4 py-2 rounded-lg text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 transition';

function Kpi({ label, value, tone = 'text-gray-900', children }) {
    return (
        <div className={CARD}>
            <p className="text-xs lg:text-sm font-medium text-gray-500">{label}</p>
            <p className={`text-2xl lg:text-3xl font-bold ${tone} mt-1`}>{egp(value)}</p>
            {children}
        </div>
    );
}

function Breakdown({ title, rows }) {
    return (
        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6">
            <h3 className="font-semibold text-gray-900 mb-4">{title}</h3>
            {rows.length === 0 ? (
                <p className="text-sm text-gray-400">{t('financials.no_data')}</p>
            ) : (
                <div className="space-y-2">
                    {rows.map((row, i) => (
                        <div key={i} className="flex items-center justify-between text-sm">
                            <span className="text-gray-600 truncate">{row.name}</span>
                            <span className="font-medium text-gray-900 shrink-0 ms-2">{egp(row.revenue)}</span>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

export default function FinancialsIndex(props) {
    const { periodKey, customStart, customEnd, exportUrl, canViewExpenses, comparison, trend, trendTotal } = props;
    usePageTitle(t('financials.title'));
    const maxTrend = Math.max(1, ...trend.map((d) => d.amount));
    const up = comparison.change >= 0;

    return (
        <>
            <div className="flex flex-wrap items-center justify-between gap-3 mb-6">
                <h1 className="text-2xl font-bold text-gray-900">{t('financials.title')}</h1>
                <div className="flex items-center gap-2">
                    {canViewExpenses && <Link href="/expenses" className={BTN}>{t('nav.expenses')}</Link>}
                    <Link href="/financials/transactions" className={BTN}>{t('financials.view_transactions')}</Link>
                    {/* File download: plain link. */}
                    <a href={exportUrl} className="px-4 py-2 rounded-lg text-sm font-medium bg-brand-600 text-white hover:bg-brand-700 transition">{t('financials.export')}</a>
                </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 lg:gap-6 mb-6">
                <Kpi label={t('financials.revenue_today')} value={props.revenueToday} tone="text-green-600" />
                <Kpi label={t('financials.revenue_this_week')} value={props.revenueThisWeek} tone="text-green-600" />
                <Kpi label={t('financials.revenue_this_month')} value={props.revenueThisMonth} tone="text-green-600" />
            </div>

            <div className="mb-6">
                <PeriodFilter periodKey={periodKey} customStart={customStart} customEnd={customEnd} />
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 lg:gap-6 mb-6">
                <Kpi label={t('financials.total_revenue')} value={comparison.current}>
                    {comparison.changePercent !== null && comparison.changePercent !== undefined && (
                        <p className={`text-xs mt-1 font-medium ${up ? 'text-green-600' : 'text-red-600'}`}>
                            {up ? '▲' : '▼'} {num(Math.abs(comparison.changePercent), 1)}% {t('financials.vs_previous_period')}
                        </p>
                    )}
                </Kpi>
                <Kpi label={t('financials.booking_revenue')} value={props.bookingRevenue} tone="text-blue-600" />
                <Kpi label={t('financials.product_revenue')} value={props.saleRevenue} tone="text-purple-600" />
                <Kpi label={t('financials.average_booking_value')} value={props.averageBookingValue ?? 0} />
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 lg:gap-6 mb-6">
                <Kpi label={t('financials.net.revenue')} value={comparison.current} tone="text-green-600" />
                <Kpi label={t('financials.net.expenses')} value={props.totalExpenses} tone="text-red-600" />
                <Kpi label={t('financials.net.net')} value={props.netTotal} tone={props.netTotal >= 0 ? 'text-green-600' : 'text-red-600'} />
            </div>

            {trendTotal > 0 ? (
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6 mb-6">
                    <h3 className="font-semibold text-gray-900 mb-4">{t('financials.revenue_trend')}</h3>
                    <div className="flex items-end gap-1 lg:gap-2 h-40 overflow-x-auto">
                        {trend.map((d) => (
                            <div key={d.date} className="flex-1 min-w-[1.5rem] flex flex-col items-center gap-1 h-full justify-end">
                                <span className="text-[10px] text-gray-500 whitespace-nowrap">{d.amount > 0 ? num(d.amount, 0) : ''}</span>
                                <div className="w-full rounded-t-md bg-brand-500" style={{ height: `${(d.amount / maxTrend) * 120}px`, minHeight: '2px' }} />
                                <span className="text-[10px] text-gray-400">{d.label}</span>
                            </div>
                        ))}
                    </div>
                </div>
            ) : (
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-6 mb-6 text-center text-sm text-gray-400">{t('financials.no_data')}</div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 lg:gap-6">
                <Breakdown title={t('financials.revenue_by_room')} rows={props.byRoom} />
                <Breakdown title={t('financials.revenue_by_room_type')} rows={props.byRoomType} />
                <Breakdown title={t('financials.revenue_by_product')} rows={props.byProduct} />
            </div>
        </>
    );
}
