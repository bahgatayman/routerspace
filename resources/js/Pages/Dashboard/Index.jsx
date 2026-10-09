import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { withQuery } from '../../Components/ui';
import { money, num } from '../../lib/format';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';
import ProductsSection from '../../Components/Dashboard/ProductsSection';

const PERIODS = [['today', 'dashboard.period_today'], ['7d', 'dashboard.period_7d'], ['30d', 'dashboard.period_30d'], ['3mo', 'dashboard.period_3mo'], ['12mo', 'dashboard.period_12mo']];
const BAR_COLORS = { blue: 'bg-blue-500', purple: 'bg-purple-500', green: 'bg-green-500', orange: 'bg-orange-500', gray: 'bg-gray-400' };
const pad2 = (n) => String(n).padStart(2, '0');

export function KpiIcon({ tone, d, iconColor }) {
    return (
        <div className={`ls-kpi-icon w-10 h-10 lg:w-12 lg:h-12 bg-${tone}-50 rounded-xl flex items-center justify-center shrink-0`}>
            <svg className={`w-5 h-5 lg:w-6 lg:h-6 ${iconColor || `text-${tone}-600`}`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={d} />
            </svg>
        </div>
    );
}

function Kpi({ label, value, valueClass = 'text-gray-900', sub, icon, tone, className = '' }) {
    return (
        <div className={`bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6 ${className}`}>
            <div className="flex items-center justify-between">
                <div>
                    <p className="text-xs lg:text-sm font-medium text-gray-500">{label}</p>
                    <p className={`text-2xl lg:text-3xl font-bold mt-1 ${valueClass}`}>{value}</p>
                    {sub && <p className="text-xs text-gray-400 mt-1">{sub}</p>}
                </div>
                <KpiIcon tone={tone} d={icon} />
            </div>
        </div>
    );
}

const ICONS = {
    money: 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    calendar: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
    people: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z',
    building: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
    bell: 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9',
    users: 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
    check: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
    wifi: 'M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0',
    bolt: 'M13 10V3L4 14h7v7l9-11h-7z',
};

const FEATURE_ICONS = {
    wifi: ['text-blue-500', ICONS.wifi],
    building: ['text-purple-500', ICONS.building],
    calendar: ['text-green-500', ICONS.calendar],
};

function FeatureIcon({ icon }) {
    const [color, d] = FEATURE_ICONS[icon] || ['text-gray-400', ICONS.bolt];
    return (
        <svg className={`w-5 h-5 ${color} shrink-0`} fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={d} />
        </svg>
    );
}

function PeriodSelector({ periodKey, customStart, customEnd }) {
    const [start, setStart] = useState(customStart || '');
    const [end, setEnd] = useState(customEnd || '');
    const apply = (e) => {
        e.preventDefault();
        const params = Object.fromEntries(new URLSearchParams(window.location.search));
        delete params.period; delete params.start; delete params.end;
        router.get(window.location.pathname, { ...params, period: 'custom', start, end }, { preserveScroll: true });
    };
    return (
        <div className="flex flex-wrap items-center gap-3 mb-6">
            <div className="inline-flex items-center gap-1 bg-white border border-gray-100 rounded-lg p-1 shadow-sm">
                {PERIODS.map(([key, labelKey]) => (
                    <Link key={key} href={withQuery(window.location.href, { period: key })} preserveScroll
                        className={`px-3 py-1.5 rounded-md text-sm font-medium transition ${periodKey === key ? 'bg-brand-600 text-white' : 'text-gray-500 hover:bg-gray-50'}`}>
                        {t(labelKey)}
                    </Link>
                ))}
            </div>
            <form onSubmit={apply} className="flex items-center gap-2">
                <input type="date" name="start" value={start} onChange={(e) => setStart(e.target.value)} className="border border-gray-200 rounded-md text-sm px-2 py-1.5 text-gray-700" />
                <span className="text-gray-400 text-sm">&ndash;</span>
                <input type="date" name="end" value={end} onChange={(e) => setEnd(e.target.value)} className="border border-gray-200 rounded-md text-sm px-2 py-1.5 text-gray-700" />
                <button type="submit" className={`px-3 py-1.5 rounded-md text-sm font-medium transition ${periodKey === 'custom' ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'}`}>
                    {t('dashboard.period_apply')}
                </button>
            </form>
        </div>
    );
}

function RevenueTrend({ trend }) {
    const max = Math.max(1, ...trend.map((r) => r.amount));
    return (
        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6 mb-6">
            <h3 className="font-semibold text-gray-900 mb-4">{t('dashboard.revenue_trend')}</h3>
            <div className="flex items-end gap-1 lg:gap-2 h-40 overflow-x-auto">
                {trend.map((r) => (
                    <div key={r.date} className="flex-1 min-w-[1.5rem] flex flex-col items-center gap-1 h-full justify-end">
                        <span className="text-[10px] text-gray-500 whitespace-nowrap">{r.amount > 0 ? num(r.amount) : ''}</span>
                        <div className="w-full rounded-t-md bg-brand-500" style={{ height: `${(r.amount / max) * 120}px`, minHeight: 2 }} />
                        <span className="text-[10px] text-gray-400">{r.label}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function RoomPerformance({ rooms, canViewRevenue }) {
    const value = (r) => r.utilization_percent ?? r.hours_booked;
    const max = Math.max(1, ...rooms.map(value));
    return (
        <div className="mb-6">
            <h3 className="font-semibold text-gray-900 mb-4">{t('dashboard.room_performance')}</h3>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                {rooms.length === 0 && <p className="text-sm text-gray-400">{t('dashboard.no_room_data')}</p>}
                {rooms.map((r) => (
                    <div key={r.room_id} className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6">
                        <div className="flex items-center justify-between gap-2 mb-2">
                            <span className="font-medium text-gray-900 truncate">{r.room_name}</span>
                            <span className="text-[11px] text-gray-400 shrink-0">{r.type_label}</span>
                        </div>
                        <div className="flex items-center justify-between text-xs mb-1 gap-2">
                            <span className="text-gray-500">{t('dashboard.room_utilization')}</span>
                            <span className="text-gray-400 shrink-0">
                                {r.utilization_percent !== null ? `${r.utilization_percent}%` : `${r.hours_booked}${t('ui.unit_h')}`}
                            </span>
                        </div>
                        <div className="w-full bg-gray-100 rounded-full h-2 mb-3">
                            <div className={`h-2 rounded-full ${BAR_COLORS[r.type_color] || 'bg-gray-400'}`} style={{ width: `${(value(r) / max) * 100}%` }} />
                        </div>
                        {canViewRevenue && (
                            <>
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-sm font-semibold text-gray-900 whitespace-nowrap">{money(r.revenue)}</span>
                                    {r.revenue_change_percent !== null && (
                                        <span className={`text-[11px] font-medium shrink-0 ${r.revenue_change_percent >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                            {r.revenue_change_percent >= 0 ? '▲' : '▼'} {num(Math.abs(r.revenue_change_percent), 1)}%
                                        </span>
                                    )}
                                </div>
                                {r.revenue_per_open_hour !== null && (
                                    <p className="text-[11px] text-gray-400 mt-1">{money(r.revenue_per_open_hour)} {t('dashboard.revenue_per_open_hour')}</p>
                                )}
                            </>
                        )}
                    </div>
                ))}
            </div>
            {rooms.length > 0 && !rooms[0].utilization_percent && (
                <p className="text-[11px] text-gray-400 mt-3">{t('dashboard.working_hours_not_configured')}</p>
            )}
        </div>
    );
}

function Heatmap({ grid, dayLabels }) {
    const cells = Object.values(grid || {}).flatMap((h) => Object.values(h || {}));
    const max = cells.length ? Math.max(...cells) : 0;
    if (max === 0) return <p className="text-sm text-gray-400">{t('dashboard.no_heatmap_data')}</p>;
    return (
        <div className="ls-heatmap" role="img" aria-label={t('dashboard.peak_hours_heatmap')}>
            <div className="ls-heatmap-row ls-heatmap-row--header">
                <div className="ls-heatmap-cell ls-heatmap-cell--label" />
                {dayLabels.map((label) => <div key={label} className="ls-heatmap-cell ls-heatmap-cell--header">{Array.from(label).slice(0, 3).join('')}</div>)}
            </div>
            {Array.from({ length: 24 }, (_, hour) => (
                <div key={hour} className="ls-heatmap-row">
                    <div className="ls-heatmap-cell ls-heatmap-cell--label">{pad2(hour)}</div>
                    {dayLabels.map((label, dow) => {
                        const count = grid?.[dow]?.[hour] ?? 0;
                        const intensity = Math.round((count / max) * 100);
                        return (
                            <div key={dow} className="ls-heatmap-cell" title={`${label} ${pad2(hour)}:00 — ${count}`}
                                style={count > 0 ? { background: `color-mix(in srgb, var(--color-chart) ${intensity}%, transparent)` } : undefined} />
                        );
                    })}
                </div>
            ))}
        </div>
    );
}

export default function DashboardIndex(props) {
    const { businessName, features, hotspot, canViewRevenue, isStaff, periodKey, customStart, customEnd, workingHours,
        showRevenue, revenue, showProducts, products, booking, showWorkspace, occupancy, roomUtilization, customers,
        needsAttention, smartInsights, ownerFeatures } = props;
    usePageTitle(t('section.dashboard'));
    const attention = needsAttention.count;

    return (
        <>
            <div className="flex flex-wrap items-center gap-3 mb-6">
                <h1 className="text-2xl font-bold text-gray-900">Welcome, {businessName}</h1>
                {workingHours && (
                    <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${workingHours.isOpenNow ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'}`}>
                        <span className={`w-1.5 h-1.5 rounded-full ${workingHours.isOpenNow ? 'bg-green-500' : 'bg-gray-400'}`} />
                        {workingHours.isOpenNow ? t('label.open_now') : t('label.closed_now')}
                    </span>
                )}
            </div>

            {hotspot && hotspot.mikrotikUnreachable && (
                <div className="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-lg mb-6">MikroTik unreachable — live stats unavailable</div>
            )}

            {/* Top KPIs — Needs Attention always renders, whatever the feature mix. */}
            <div className="ls-kpis-wrap mb-8"><div className="ls-kpis ls-kpis--5">
                {showRevenue && (
                    <div className="ls-kpi-hero bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
                        <div className="flex items-center justify-between">
                            <div className="min-w-0">
                                <p className="text-xs lg:text-sm font-medium text-gray-500">{t('dashboard.revenue_today')}</p>
                                <p className="text-2xl lg:text-3xl font-bold text-green-600 mt-1 whitespace-nowrap">{money(revenue.today)}</p>
                                <p className="text-xs text-gray-400 mt-1 truncate">{t('dashboard.revenue_this_month')}: {money(revenue.thisMonth)}</p>
                                {revenue.changePercent !== null && (
                                    <>
                                        <p className={`text-xs mt-1 font-medium ${revenue.up ? 'text-green-600' : 'text-red-600'}`}>
                                            {revenue.up ? '▲' : '▼'} {num(Math.abs(revenue.changePercent), 1)}% {t('dashboard.vs_previous_period')}
                                        </p>
                                        <p className="text-[11px] text-gray-400 mt-1">{revenue.changeSentence}</p>
                                    </>
                                )}
                            </div>
                            <KpiIcon tone="green" d={ICONS.money} />
                        </div>
                    </div>
                )}

                {booking && <Kpi label={t('label.today_bookings')} value={booking.todayBookings} tone="orange" icon={ICONS.calendar} />}

                {showWorkspace && (
                    <>
                        <Kpi label={t('dashboard.current_occupancy')} value={`${occupancy.percent}%`} valueClass="text-brand-600"
                            sub={t('dashboard.seats_occupied', { occupied: occupancy.occupied, capacity: occupancy.capacity })} tone="brand" icon={ICONS.people} />
                        <Kpi label={t('label.available_rooms')} value={occupancy.availableRoomsNow} valueClass="text-green-600" tone="green" icon={ICONS.building} />
                    </>
                )}

                <Link href="/notifications" className="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6 hover:shadow-md transition block">
                    <div className="flex items-center justify-between">
                        <div>
                            <p className="text-xs lg:text-sm font-medium text-gray-500">{t('dashboard.needs_attention')}</p>
                            <p className={`text-2xl lg:text-3xl font-bold ${attention > 0 ? 'text-red-600' : 'text-gray-900'} mt-1`}>{attention}</p>
                        </div>
                        <KpiIcon tone={attention > 0 ? 'red' : 'gray'} d={ICONS.bell} iconColor={attention > 0 ? undefined : 'text-gray-400'} />
                    </div>
                </Link>
            </div></div>

            {smartInsights.length > 0 && (
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6 mb-6" style={{ borderInlineStart: '3px solid var(--color-primary, #2e4f8f)' }}>
                    <h3 className="font-semibold text-gray-900 mb-3 flex items-center gap-2">
                        <svg className="w-4 h-4 text-brand-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 18h6m-5 3h4m-6-6a7 7 0 1114 0c0 2.5-1.5 3.5-2 4.5-.3.6-.5 1-.5 1.5H8.5c0-.5-.2-.9-.5-1.5-.5-1-2-2-2-4.5z" />
                        </svg>
                        {t('dashboard.smart_insights')}
                    </h3>
                    <ul className="space-y-2">
                        {smartInsights.map((insight, i) => (
                            <li key={i} className="text-sm text-gray-700 flex gap-2">
                                <span className="text-brand-500 shrink-0">•</span>
                                <span>{insight}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {(showRevenue || booking || showProducts) && <PeriodSelector key={`${periodKey}-${customStart}-${customEnd}`} periodKey={periodKey} customStart={customStart} customEnd={customEnd} />}

            {showRevenue && <RevenueTrend trend={revenue.trend} />}

            {showProducts && <ProductsSection products={products} />}

            {showWorkspace && booking && roomUtilization && <RoomPerformance rooms={roomUtilization} canViewRevenue={canViewRevenue} />}

            {booking && (
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 lg:gap-6 mb-6">
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6 lg:col-span-2">
                        <h3 className="font-semibold text-gray-900 mb-4">{t('dashboard.peak_hours_heatmap')}</h3>
                        <Heatmap grid={booking.peakHoursGrid} dayLabels={booking.dayLabels} />
                    </div>
                    <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6">
                        <h3 className="font-semibold text-gray-900 mb-4">{t('dashboard.booking_status')}</h3>
                        {booking.statusBreakdown.length === 0 ? (
                            <p className="text-sm text-gray-400">{t('dashboard.no_data_for_period')}</p>
                        ) : (
                            <div className="space-y-2">
                                {booking.statusBreakdown.map((s) => (
                                    <div key={s.status} className="flex items-center justify-between text-sm">
                                        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${s.class}`}>{s.label}</span>
                                        <span className="text-gray-500">{s.count}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            )}

            {hotspot && (
                <>
                    <div className="ls-kpis-wrap mb-8"><div className="ls-kpis ls-kpis--4">
                        <Kpi label={t('label.total_users')} value={hotspot.totalUsers} tone="blue" icon={ICONS.users} />
                        <Kpi label={t('label.active_users')} value={hotspot.activeUsers} tone="green" icon={ICONS.check} />
                        <Kpi label={t('label.online_now')} value={hotspot.activeSessions} sub="Live from MikroTik" tone="purple" icon={ICONS.wifi} />
                        <Kpi label={t('section.speed_profiles')} value={hotspot.totalProfiles} tone="orange" icon={ICONS.bolt} />
                    </div></div>

                    <h2 className="text-lg font-semibold text-gray-700 mb-4">{t('label.quick_links')}</h2>
                    <div className="flex flex-col sm:flex-row flex-wrap gap-3 lg:gap-4 mb-8">
                        <Link href="/users/create" className="bg-brand-600 text-white px-5 py-2.5 rounded-lg hover:bg-brand-700 transition text-sm font-medium shadow-sm text-center">{t('btn.add_user')}</Link>
                        <Link href="/sessions" className="bg-purple-600 text-white px-5 py-2.5 rounded-lg hover:bg-purple-700 transition text-sm font-medium shadow-sm text-center">{t('label.view_active_sessions')}</Link>
                        <Link href="/speed-profiles" className="bg-orange-600 text-white px-5 py-2.5 rounded-lg hover:bg-orange-700 transition text-sm font-medium shadow-sm text-center">{t('label.manage_speed_profiles')}</Link>
                    </div>
                </>
            )}

            {(booking || customers) && (
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 lg:gap-6 mb-6">
                    {booking && (
                        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6 lg:col-span-2">
                            <h3 className="font-semibold text-gray-900 mb-4">{t('dashboard.todays_schedule')}</h3>
                            {booking.todaysSchedule.length === 0 && <p className="text-sm text-gray-400">{t('dashboard.no_bookings_today')}</p>}
                            {booking.todaysSchedule.map((b) => (
                                <div key={b.id} className="flex items-center justify-between gap-3 py-2.5 border-b border-gray-50 last:border-0 text-sm">
                                    <div className="min-w-0">
                                        <p className="font-medium text-gray-800 truncate">{b.customer}</p>
                                        <p className="text-xs text-gray-400 truncate">{b.room} &middot; {b.time_range}</p>
                                    </div>
                                    <span className={`shrink-0 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${b.status_class}`}>{b.status_label}</span>
                                </div>
                            ))}
                        </div>
                    )}

                    {customers && (
                        <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6">
                            <p className="text-xs lg:text-sm font-medium text-gray-500">{t('dashboard.new_customers')}</p>
                            <p className="text-2xl lg:text-3xl font-bold text-gray-900 mt-1">{customers.newCustomers}</p>
                            <p className="text-[11px] text-gray-400 mt-1">{t(`dashboard.period_${periodKey}`)}</p>
                            <div className="grid grid-cols-2 gap-3 mt-4 pt-4 border-t border-gray-50">
                                <div>
                                    <p className="text-[11px] text-gray-500">{t('dashboard.returning_customers')}</p>
                                    <p className="text-lg font-semibold text-gray-900 mt-0.5">
                                        {customers.returningPercent !== null ? `${customers.returningPercent}%` : t('dashboard.not_enough_data')}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-[11px] text-gray-500">{t('dashboard.avg_spend_per_customer')}</p>
                                    <p className="text-lg font-semibold text-gray-900 mt-0.5 whitespace-nowrap">
                                        {customers.averageSpend !== null ? money(customers.averageSpend) : t('dashboard.not_enough_data')}
                                    </p>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            )}

            {showWorkspace && booking && roomUtilization && (
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6 mb-6">
                    <h3 className="font-semibold text-gray-900 mb-4">{t('dashboard.room_utilization_details')}</h3>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-left text-gray-500 border-b">
                                    <th className="pb-3">{t('table.th.room')}</th>
                                    <th className="pb-3 hidden sm:table-cell">{t('dashboard.hours_booked')}</th>
                                    <th className="pb-3 hidden sm:table-cell">{t('dashboard.bookings')}</th>
                                    {canViewRevenue && (
                                        <>
                                            <th className="pb-3">{t('financial.revenue')}</th>
                                            <th className="pb-3 hidden sm:table-cell">{t('dashboard.revenue_per_open_hour')}</th>
                                        </>
                                    )}
                                    <th className="pb-3">{t('dashboard.room_utilization')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {roomUtilization.length === 0 && (
                                    <tr><td colSpan={canViewRevenue ? 6 : 4} className="py-6 text-center text-gray-400">{t('dashboard.no_room_data')}</td></tr>
                                )}
                                {roomUtilization.map((r) => (
                                    <tr key={r.room_id} className="border-b last:border-0">
                                        <td className="py-3 pe-3 font-medium text-gray-900">
                                            {r.room_name}
                                            <span className="block sm:hidden text-xs font-normal text-gray-500">{r.hours_booked}{t('ui.unit_h')} · {r.bookings_count} {t('dashboard.bookings')}</span>
                                        </td>
                                        <td className="py-3 hidden sm:table-cell">{r.hours_booked}</td>
                                        <td className="py-3 hidden sm:table-cell">{r.bookings_count}</td>
                                        {canViewRevenue && (
                                            <>
                                                <td className="py-3 pe-3 whitespace-nowrap">{money(r.revenue)}</td>
                                                <td className="py-3 hidden sm:table-cell whitespace-nowrap">{r.revenue_per_open_hour !== null ? money(r.revenue_per_open_hour) : '—'}</td>
                                            </>
                                        )}
                                        <td className="py-3">{r.utilization_percent !== null ? `${r.utilization_percent}%` : '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6 mb-8">
                <div className="flex items-center justify-between mb-4">
                    <h3 className="font-semibold text-gray-900">{t('dashboard.needs_attention')}</h3>
                    <Link href="/notifications" className="text-xs font-medium text-brand-600 hover:text-brand-800">{t('notif.view_all')}</Link>
                </div>
                {needsAttention.items.length === 0 && <p className="text-sm text-gray-400">{t('dashboard.all_caught_up')}</p>}
                {needsAttention.items.map((n) => (
                    // Click-through is a server redirect to wherever the alert points: normal navigation.
                    <a key={n.id} href={`/notifications/${n.id}/open`} className="flex items-start gap-3 py-2.5 border-b border-gray-50 last:border-0 hover:bg-gray-50 -mx-2 px-2 rounded-lg transition">
                        <span className={`mt-0.5 shrink-0 w-8 h-8 rounded-full flex items-center justify-center bg-${n.level}-100 text-${n.level}-600`}>
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={n.icon_path} /></svg>
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="block text-sm font-medium text-gray-800">{n.title}</span>
                            {n.body && <span className="block text-xs text-gray-500">{n.body}</span>}
                            <span className="block text-[11px] text-gray-400 mt-0.5">{n.ago}</span>
                        </span>
                    </a>
                ))}
            </div>

            {!isStaff && ownerFeatures && (
                <div className="mt-8">
                    <h2 className="text-lg font-semibold text-gray-900 mb-4">{t('label.your_features')}</h2>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        {ownerFeatures.map((f) => (
                            <div key={f.id} className="bg-white rounded-xl border border-gray-100 shadow-sm p-5 flex items-center gap-4">
                                <FeatureIcon icon={f.icon} />
                                <div>
                                    <p className="font-medium text-gray-900 text-sm">{f.name}</p>
                                    <p className="text-xs text-gray-500 mt-1">{f.description}</p>
                                </div>
                            </div>
                        ))}
                        {ownerFeatures.length === 0 && <div className="col-span-3 text-center py-8 text-gray-400 text-sm">{t('empty.no_features')}</div>}
                    </div>
                </div>
            )}
        </>
    );
}
