import { Link, router } from '@inertiajs/react';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';
import { money } from '../../lib/format';
import DayRooms from '../../Components/Bookings/DayRooms';

const btnSecondary = 'bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition text-sm font-medium';
const selectCls = 'border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500';
const navLink = 'text-sm text-gray-600 hover:text-gray-900 font-medium';

/** Explains the calendar's two colour systems (room timeline vs booking status pills). */
function Legend({ showRoomAvailability, statusLegend }) {
    const swatch = (cls, label) => (
        <span className="inline-flex items-center gap-1.5 text-xs text-gray-600"><span className={`w-3 h-3 rounded-sm ${cls}`} />{label}</span>
    );
    return (
        <details className="bg-white rounded-xl shadow-sm border border-gray-100 mb-6 group">
            <summary className="cursor-pointer select-none list-none px-4 py-3 flex items-center justify-between text-sm font-medium text-gray-700 hover:bg-gray-50 rounded-xl transition">
                <span className="flex items-center gap-2">
                    <svg className="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    {t('booking.legend.title')}
                </span>
                <svg className="w-4 h-4 text-gray-400 transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 9l-7 7-7-7" /></svg>
            </summary>
            <div className="px-4 pb-4 pt-1 border-t border-gray-100 flex flex-wrap gap-x-10 gap-y-4">
                {showRoomAvailability && (
                    <>
                        <div>
                            <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">{t('booking.legend.room_availability')}</p>
                            <div className="flex flex-wrap gap-x-4 gap-y-2">
                                {swatch('bg-green-200', t('booking.legend.free'))}
                                {swatch('bg-yellow-300', t('booking.legend.partial'))}
                                {swatch('bg-red-300', t('booking.legend.full'))}
                                {swatch('bg-gray-200', t('booking.legend.closed'))}
                            </div>
                        </div>
                        <div>
                            <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">{t('booking.legend.click_to_book')}</p>
                            <div className="flex flex-wrap gap-x-4 gap-y-2">
                                <span className="inline-flex items-center gap-1.5 text-xs text-gray-600">
                                    <span className="px-1.5 py-0.5 rounded text-[10px] font-medium bg-green-100 text-green-700">09:00</span>{t('booking.legend.slot_available')}
                                </span>
                                <span className="inline-flex items-center gap-1.5 text-xs text-gray-600">
                                    <span className="px-1.5 py-0.5 rounded text-[10px] font-medium bg-gray-100 text-gray-400">09:00</span>{t('booking.legend.slot_unavailable')}
                                </span>
                            </div>
                        </div>
                    </>
                )}
                <div>
                    <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">{t('booking.legend.booking_status')}</p>
                    <div className="flex flex-wrap gap-x-4 gap-y-2">
                        {statusLegend.map((s) => <span key={s.label} className={`inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium ${s.class}`}>{s.label}</span>)}
                    </div>
                </div>
            </div>
        </details>
    );
}

export default function BookingCalendar(props) {
    const { view, date, roomId, workspaceId, today, workspaces, allRooms, statusLegend } = props;
    usePageTitle(t('booking.calendar_view'));

    const url = (overrides = {}) => {
        const q = new URLSearchParams();
        Object.entries({ view, date, room_id: roomId, workspace_id: workspaceId, ...overrides })
            .forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') q.set(k, v); });
        return `/bookings/calendar?${q.toString()}`;
    };
    const filter = (key) => (e) => router.get(url({ [key]: e.target.value }), {}, { preserveScroll: true });

    return (
        <>
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <h1 className="text-2xl font-bold text-gray-900">{t('booking.calendar_view')}</h1>
                <div className="flex gap-2">
                    <Link href="/bookings/availability" className={btnSecondary}>{t('booking.quick_availability')}</Link>
                    <Link href="/bookings" className={btnSecondary}>{t('btn.list_view')}</Link>
                    <a href="/bookings/create" className="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">+ {t('booking.new_booking')}</a>
                </div>
            </div>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="inline-flex rounded-lg border border-gray-200 overflow-hidden">
                        {[['day', t('booking.day_view')], ['week', t('booking.week_view')], ['month', t('booking.month_view')]].map(([v, label]) => (
                            <Link key={v} href={url({ view: v })} preserveScroll
                                className={`px-4 py-2 text-sm font-medium transition ${view === v ? 'bg-blue-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50'}`}>{label}</Link>
                        ))}
                    </div>
                    <div className="flex flex-wrap items-end gap-3">
                        <div>
                            <label className="block text-xs text-gray-500 mb-1" htmlFor="cal-ws">{t('session.workspace')}</label>
                            <select id="cal-ws" name="workspace_id" value={workspaceId} onChange={filter('workspace_id')} className={selectCls}>
                                <option value="">{t('common.all')}</option>
                                {workspaces.map((ws) => <option key={ws.id} value={String(ws.id)}>{ws.name}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs text-gray-500 mb-1" htmlFor="cal-room">{t('booking.room')}</label>
                            <select id="cal-room" name="room_id" value={roomId} onChange={filter('room_id')} className={selectCls}>
                                <option value="">{t('common.all')}</option>
                                {allRooms.map((r) => <option key={r.id} value={String(r.id)}>{r.label}</option>)}
                            </select>
                        </div>
                        {(roomId || workspaceId) && <Link href={url({ room_id: null, workspace_id: null })} className="text-sm text-gray-500 hover:text-gray-700 py-2">{t('btn.clear_filters')}</Link>}
                    </div>
                </div>
            </div>

            <Legend showRoomAvailability={view === 'day' || view === 'week'} statusLegend={statusLegend} />

            {view === 'day' && (
                <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-6">
                    <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                        <Link href={url({ date: props.day.prev })} preserveScroll className={navLink}>&larr; {t('common.back')}</Link>
                        <div className="flex items-center gap-3">
                            <h2 className="text-lg font-semibold text-gray-900">{props.day.title}</h2>
                            <Link href={url({ date: today })} className="text-xs text-blue-600 hover:underline font-medium">{t('common.today')}</Link>
                        </div>
                        <Link href={url({ date: props.day.next })} preserveScroll className={navLink}>{t('common.back')} &rarr;</Link>
                    </div>
                    <div className="p-4"><DayRooms dayRooms={props.dayRooms} /></div>
                </div>
            )}

            {view === 'week' && (
                <>
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-6">
                        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                            <Link href={url({ date: props.week.prev })} preserveScroll className={navLink}>&larr; {t('common.back')}</Link>
                            <h2 className="text-lg font-semibold text-gray-900">{props.week.title}</h2>
                            <Link href={url({ date: props.week.next })} preserveScroll className={navLink}>{t('common.back')} &rarr;</Link>
                        </div>
                    </div>
                    <div className="space-y-4">
                        {props.days.map((day) => (
                            <div key={day.date} className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                                <Link href={url({ view: 'day', date: day.date })} className="flex items-center justify-between px-6 py-3 border-b border-gray-100 hover:bg-gray-50 transition">
                                    <span className="font-semibold text-gray-900">
                                        {day.label}
                                        {day.is_today && <span className="ml-2 text-xs text-blue-600 font-medium">&middot; {t('common.today')}</span>}
                                    </span>
                                    <span className="text-xs text-gray-400">{t('common.view')} {t('booking.day_view')} &rarr;</span>
                                </Link>
                                <div className="p-4"><DayRooms dayRooms={day.rooms} /></div>
                            </div>
                        ))}
                    </div>
                </>
            )}

            {view === 'month' && <MonthView month={props.month} bookings={props.bookings} date={date} url={url} />}
        </>
    );
}

function MonthView({ month, bookings, date, url }) {
    const selected = (bookings && bookings[date]) || [];
    return (
        <>
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-6">
                <div className="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                    <Link href={url({ date: month.prev })} preserveScroll className={navLink}>&larr; {t('common.back')}</Link>
                    <h2 className="text-lg font-semibold text-gray-900">{month.title}</h2>
                    <Link href={url({ date: month.next })} preserveScroll className={navLink}>{t('common.back')} &rarr;</Link>
                </div>
                <div className="grid grid-cols-7 text-center">
                    {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((d) => <div key={d} className="py-2 text-xs font-medium text-gray-500 bg-gray-50 border-b border-gray-100">{d}</div>)}
                    {month.cells.map((c) => (
                        <Link key={c.date} href={url({ view: 'day', date: c.date })}
                            className={`relative p-2 sm:p-3 text-sm border-b border-r border-gray-50 transition ${!c.in_month ? 'text-gray-300' : 'text-gray-700 hover:bg-blue-50'} ${c.is_today ? 'bg-blue-50' : ''} ${c.is_selected ? 'ring-2 ring-blue-500 bg-blue-50' : ''}`}>
                            <span className="font-medium">{c.day}</span>
                            {c.count > 0 && (
                                <span className={`block mt-1 mx-auto w-5 h-5 rounded-full text-[10px] font-bold ${c.count > 3 ? 'bg-red-500 text-white' : 'bg-blue-100 text-blue-700'}`}>{c.count}</span>
                            )}
                        </Link>
                    ))}
                </div>
            </div>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 className="text-lg font-semibold text-gray-900 mb-4">{t('booking.bookings')} {month.selectedLabel}</h3>
                {selected.length > 0 ? (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-left text-gray-500 bg-gray-50 border-b border-gray-100">
                                    <th className="px-4 py-3 font-medium">{t('table.th.time')}</th>
                                    <th className="px-4 py-3 font-medium">{t('table.th.user')}</th>
                                    <th className="px-4 py-3 font-medium">{t('table.th.room')}</th>
                                    <th className="px-4 py-3 font-medium">{t('table.th.status')}</th>
                                    <th className="px-4 py-3 font-medium">{t('table.th.total')}</th>
                                    <th className="px-4 py-3 font-medium" />
                                </tr>
                            </thead>
                            <tbody>
                                {selected.map((b) => (
                                    <tr key={b.id} className="border-b border-gray-50">
                                        <td className="px-4 py-3 whitespace-nowrap font-medium">{b.time_range}</td>
                                        <td className="px-4 py-3">
                                            {b.customer}
                                            {b.party_size > 1 && <span className="text-gray-400 text-xs"> &middot; {t('session.party_of', { count: b.party_size })}</span>}
                                        </td>
                                        <td className="px-4 py-3">{b.room}</td>
                                        <td className="px-4 py-3"><span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${b.status_class}`}>{b.status_label}</span></td>
                                        <td className="px-4 py-3 font-medium">{money(b.total_price)}</td>
                                        <td className="px-4 py-3"><Link href={`/bookings/${b.id}`} className="text-blue-600 hover:underline text-xs font-medium">{t('common.view')}</Link></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <div className="text-center py-8">
                        <p className="text-gray-400 text-sm">{t('empty.no_bookings_on_day')}</p>
                        <a href="/bookings/create" className="text-blue-600 hover:underline text-sm font-medium mt-2 inline-block">+ {t('booking.new_booking')}</a>
                    </div>
                )}
            </div>
        </>
    );
}
