import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Pagination } from '../../Components/ui';
import { money } from '../../lib/format';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

const inputCls = 'border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500';
const btnSecondary = 'bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition text-sm font-medium';

export default function BookingsIndex({ bookings, rooms, filters }) {
    usePageTitle(t('booking.bookings'));
    const [f, setF] = useState(filters);
    const set = (k) => (e) => setF((s) => ({ ...s, [k]: e.target.value }));
    const apply = (e) => {
        e.preventDefault();
        const params = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v !== null));
        router.get('/bookings', params, { preserveScroll: true, preserveState: true, replace: true });
    };
    // Whole-row navigation (the row-link behaviour), but never hijacking real links inside the row.
    const openRow = (e, id) => {
        if (e.target.closest('a, button, input, select, textarea, label')) return;
        if (e.metaKey || e.ctrlKey) { window.open(`/bookings/${id}`, '_blank'); return; }
        router.visit(`/bookings/${id}`);
    };

    return (
        <>
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <h1 className="text-2xl font-bold text-gray-900">{t('booking.bookings')}</h1>
                <div className="flex gap-2">
                    <Link href="/bookings/availability" className={btnSecondary}>{t('booking.quick_availability')}</Link>
                    <Link href="/bookings/calendar" className={btnSecondary}>{t('btn.calendar_view')}</Link>
                    {/* Plain link: the quick-booking modal intercepts /bookings/create. */}
                    <a href="/bookings/create" className="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">+ {t('booking.new_booking')}</a>
                </div>
            </div>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-6">
                <form onSubmit={apply} className="flex flex-wrap gap-3 items-end">
                    <div>
                        <label className="block text-xs text-gray-500 mb-1" htmlFor="bf-status">{t('common.status')}</label>
                        <select id="bf-status" name="status" value={f.status} onChange={set('status')} className={inputCls}>
                            <option value="">{t('common.all')}</option>
                            {['pending', 'confirmed', 'completed', 'cancelled'].map((s) => <option key={s} value={s}>{t(`status.${s}`)}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs text-gray-500 mb-1" htmlFor="bf-date">{t('common.date')}</label>
                        <input id="bf-date" type="date" name="date" value={f.date} onChange={set('date')} className={inputCls} />
                    </div>
                    <div>
                        <label className="block text-xs text-gray-500 mb-1" htmlFor="bf-room">{t('booking.room')}</label>
                        <select id="bf-room" name="room_id" value={f.room_id} onChange={set('room_id')} className={inputCls}>
                            <option value="">{t('common.all')} {t('workspace.rooms')}</option>
                            {rooms.map((r) => <option key={r.id} value={String(r.id)}>{r.label}</option>)}
                        </select>
                    </div>
                    <button type="submit" className="bg-gray-800 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition text-sm font-medium">{t('btn.filter')}</button>
                    <Link href="/bookings" className="text-sm text-gray-500 hover:text-gray-700 py-2">{t('btn.clear_filters')}</Link>
                </form>
            </div>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                {bookings.data.length === 0 ? (
                    <div className="p-12 text-center">
                        <p className="text-gray-400 text-sm">{t('empty.no_bookings')}</p>
                        <a href="/bookings/create" className="text-blue-600 hover:underline text-sm font-medium mt-2 inline-block">{t('booking.new_booking')}</a>
                    </div>
                ) : (
                    <>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left text-gray-500 bg-gray-50 border-b border-gray-100">
                                        <th className="px-4 py-3 font-medium">{t('table.th.date')}</th>
                                        <th className="px-4 py-3 font-medium">{t('table.th.time')}</th>
                                        <th className="px-4 py-3 font-medium">{t('table.th.user')}</th>
                                        <th className="px-4 py-3 font-medium">{t('table.th.room')}</th>
                                        <th className="px-4 py-3 font-medium">{t('table.th.hours')}</th>
                                        <th className="px-4 py-3 font-medium">{t('table.th.total')}</th>
                                        <th className="px-4 py-3 font-medium">{t('table.th.status')}</th>
                                        <th className="px-4 py-3 font-medium" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {bookings.data.map((b) => (
                                        <tr key={b.id} data-href={`/bookings/${b.id}`} onClick={(e) => openRow(e, b.id)}
                                            className={`row-link border-b border-gray-50 cursor-pointer hover:bg-gray-50 transition ${b.status === 'cancelled' ? 'text-gray-400 line-through' : 'text-gray-900'}`}>
                                            <td className="px-4 py-3 font-medium">
                                                {/* Real link: keeps the booking keyboard-reachable and ctrl/middle-clickable. */}
                                                <Link href={`/bookings/${b.id}`} className="hover:text-blue-600">{b.date_label}</Link>
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap">{b.time_range}</td>
                                            <td className="px-4 py-3">
                                                {b.customer_id && <Link href={`/users/${b.customer_id}`} className="text-blue-600 hover:underline font-medium">{b.customer_name}</Link>}
                                                {b.party_size > 1 && <span className="text-gray-400 text-xs"> &middot; {t('session.party_of', { count: b.party_size })}</span>}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className="text-gray-600">{b.workspace} /</span> {b.room}
                                            </td>
                                            <td className="px-4 py-3">{b.total_hours} {t('common.hours')}</td>
                                            <td className="px-4 py-3 font-medium">{money(b.total_price)}</td>
                                            <td className="px-4 py-3">
                                                <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${b.status_class}`}>{b.status_label}</span>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center justify-end gap-3">
                                                    {/* The booking form stays a Blade page: full page load. */}
                                                    {b.can_edit && <a href={`/bookings/${b.id}/edit`} className="text-gray-600 hover:text-gray-900 hover:underline text-xs font-medium">{t('common.edit')}</a>}
                                                    <svg className="w-4 h-4 text-gray-300 shrink-0 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 5l7 7-7 7" />
                                                    </svg>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="px-4 py-3 border-t border-gray-100"><Pagination paginator={bookings} /></div>
                    </>
                )}
            </div>
        </>
    );
}
