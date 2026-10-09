import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Modal } from '../../Components/ui';
import Packages, { PackageSummary } from '../../Components/Users/Packages';
import { money } from '../../lib/format';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

const SVG = (d, className = 'w-4 h-4 text-blue-600', width = 2) => (
    <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={width} d={d} /></svg>
);

const P = {
    bolt: 'M13 10V3L4 14h7v7l9-11h-7z',
    user: 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
    phone: 'M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z',
    mail: 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
    calendar: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
    wifi: 'M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0',
    note: 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
    building: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
    check: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
};

const ACTIVITY = {
    booking: [P.calendar, 'bg-blue-100 text-blue-600', 'user.activity_booking'],
    session_open: [P.bolt, 'bg-orange-100 text-orange-600', 'user.activity_session_open'],
    session_closed: [P.check, 'bg-green-100 text-green-600', 'user.activity_session_closed'],
    created: [P.user, 'bg-gray-100 text-gray-500', 'user.activity_account_created'],
};

const initialsOf = (name) => String(name || '').trim().split(/\s+/u).filter(Boolean).slice(0, 2)
    .map((w) => Array.from(w)[0].toUpperCase()).join('');

function SpeedForm({ user, speedProfiles }) {
    const form = useForm({ speed_profile_id: user.speed_profile_id ? String(user.speed_profile_id) : '' });
    const submit = (e) => {
        e.preventDefault();
        form.post(`/users/${user.id}/speed`, { preserveScroll: true });
    };
    return (
        <form onSubmit={submit} className="mt-4">
            <label htmlFor="speed_profile_id" className="block text-xs font-medium text-gray-500 mb-1.5">{t('user.select_speed_profile')}</label>
            <div className="flex gap-2">
                <select name="speed_profile_id" id="speed_profile_id" required value={form.data.speed_profile_id} onChange={(e) => form.setData('speed_profile_id', e.target.value)}
                    className="flex-1 min-w-0 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">{t('placeholder.select_profile')}</option>
                    {speedProfiles.map((p) => (
                        <option key={p.id} value={p.id}>{p.name} (↓{p.speed_download} / ↑{p.speed_upload})</option>
                    ))}
                </select>
                <button type="submit" disabled={form.processing} className="shrink-0 bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm disabled:opacity-60">
                    {t('common.save')}
                </button>
            </div>
            {form.errors.speed_profile_id && <p className="text-red-600 text-xs mt-1">{form.errors.speed_profile_id}</p>}
            <p className="text-xs text-gray-400 mt-2">{t('user.speed_change_hint')}</p>
        </form>
    );
}

export default function UsersShow(props) {
    const {
        user, hasHotspot, hasBooking, speedProfiles, recentBookings, hasOpenSession, stats, activity,
        showPackages, canAssignPackages, packageSummary, packages, packageUsages, packageTemplates, packageStoreUrl, packageDefaults,
    } = props;
    const { errors } = usePage().props;
    usePageTitle(user.name);
    const isActive = user.status === 'active';
    const bookHref = `/bookings/create?hotspot_user_id=${user.id}`;

    // Delete confirmation lives outside the <details> menu so the dialog never sits inside its stacking context.
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const destroy = () => router.delete(`/users/${user.id}`, {
        onStart: () => setDeleting(true),
        onFinish: () => { setDeleting(false); setConfirmDelete(false); },
    });

    const toggleStatus = () => {
        router.post(`/users/${user.id}/toggle-status`, {}, { preserveScroll: true });
    };

    return (
        <>
            {/* ── Identity header ── */}
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5 lg:p-6 mb-6">
                <div className="flex flex-col sm:flex-row sm:items-center gap-4">
                    <div className="w-16 h-16 shrink-0 rounded-full bg-gradient-to-br from-blue-600 to-blue-400 text-white flex items-center justify-center text-xl font-bold">
                        {initialsOf(user.name) || '?'}
                    </div>

                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-xl lg:text-2xl font-bold text-gray-900 truncate">{user.name}</h1>
                            <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${isActive ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
                                {isActive ? t('status.active') : t('status.inactive')}
                            </span>
                            {hasHotspot && user.speed_profile_name && (
                                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700">
                                    {SVG(P.bolt, 'w-3 h-3')}
                                    {user.speed_profile_name}
                                </span>
                            )}
                            {hasOpenSession && (
                                <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-orange-50 text-orange-700">
                                    <span className="w-1.5 h-1.5 rounded-full bg-orange-500 animate-pulse" />
                                    {t('user.session_in_progress')}
                                </span>
                            )}
                        </div>
                        <p className="text-sm text-gray-500 mt-1">{t('user.member_since')} {user.member_since}</p>
                    </div>

                    <div className="flex items-center gap-2 shrink-0">
                        <Link href={`/users/${user.id}/edit`} className="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
                            {t('common.edit')}
                        </Link>

                        <details className="relative group">
                            <summary className="list-none cursor-pointer w-9 h-9 rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 flex items-center justify-center">
                                <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zm0 6a2 2 0 110-4 2 2 0 010 4zm0 6a2 2 0 110-4 2 2 0 010 4z" /></svg>
                            </summary>
                            <div className="absolute end-0 mt-2 w-52 bg-white rounded-lg shadow-lg border border-gray-100 py-1 z-20">
                                <button type="button" onClick={toggleStatus} className="w-full text-start px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                    {isActive ? t('btn.deactivate') : t('btn.activate')}
                                </button>
                                {hasBooking && (
                                    // Plain link: the global quick-booking modal intercepts /bookings/create.
                                    <a href={bookHref} data-book-name={user.name} data-book-phone={user.phone} className="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                        {t('booking.new_booking')}
                                    </a>
                                )}
                                <div className="border-t border-gray-100 my-1" />
                                <button type="button" onClick={() => setConfirmDelete(true)} className="w-full text-start px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                                    {t('common.delete')}
                                </button>
                            </div>
                        </details>
                    </div>
                </div>
            </div>

            <Modal open={confirmDelete} onClose={() => setConfirmDelete(false)} title={t('admin_platform.confirm_title')} size="narrow"
                footer={<>
                    <Button variant="secondary" onClick={() => setConfirmDelete(false)}>{t('common.cancel')}</Button>
                    <Button variant="danger" processing={deleting} onClick={destroy}>{t('common.delete')}</Button>
                </>}>
                <p className="ls-muted" style={{ margin: 0 }}>Delete this user?</p>
            </Modal>

            {showPackages && <PackageSummary summary={packageSummary} />}

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                {/* ── Left + centre ── */}
                <div className="lg:col-span-2 space-y-6">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                        {/* Contact information */}
                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5 lg:p-6">
                            <h2 className="flex items-center gap-2 text-sm font-semibold text-gray-900 mb-4">
                                {SVG(P.user)}
                                {t('user.contact_information')}
                            </h2>
                            <ul className="space-y-3.5">
                                <li className="flex items-start gap-3">
                                    {SVG(P.phone, 'w-4 h-4 mt-0.5 text-gray-400 shrink-0')}
                                    <div className="min-w-0">
                                        <p className="text-sm text-gray-900 font-medium">{user.phone}</p>
                                        {hasHotspot && <p className="text-xs text-gray-400">{t('user.router_username')}</p>}
                                    </div>
                                </li>
                                <li className="flex items-start gap-3">
                                    {SVG(P.mail, 'w-4 h-4 mt-0.5 text-gray-400 shrink-0')}
                                    <p className={`text-sm ${user.email ? 'text-gray-900' : 'text-gray-400 italic'} break-all`}>
                                        {user.email || t('user.no_email')}
                                    </p>
                                </li>
                                <li className="flex items-start gap-3">
                                    {SVG(P.calendar, 'w-4 h-4 mt-0.5 text-gray-400 shrink-0')}
                                    <p className="text-sm text-gray-900">{user.created}</p>
                                </li>
                            </ul>
                        </div>

                        {/* Internet plan (hotspot) — the member's speed profile */}
                        {hasHotspot && (
                            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-5 lg:p-6">
                                <h2 className="flex items-center gap-2 text-sm font-semibold text-gray-900 mb-4">
                                    {SVG(P.wifi)}
                                    {t('user.internet_plan')}
                                </h2>
                                <div className="rounded-xl bg-gradient-to-br from-blue-800 to-blue-600 text-white p-4">
                                    <p className="font-semibold">{user.speed_profile_name || t('user.no_speed_profile')}</p>
                                    <div className="grid grid-cols-2 gap-3 mt-4">
                                        <div className="rounded-lg bg-white/10 px-3 py-2">
                                            <p className="text-[0.65rem] uppercase tracking-wide text-blue-100/80">{t('user.download_speed')}</p>
                                            <p className="text-sm font-semibold">↓ {user.speed_download}</p>
                                        </div>
                                        <div className="rounded-lg bg-white/10 px-3 py-2">
                                            <p className="text-[0.65rem] uppercase tracking-wide text-blue-100/80">{t('user.upload_speed')}</p>
                                            <p className="text-sm font-semibold">↑ {user.speed_upload}</p>
                                        </div>
                                    </div>
                                </div>
                                <SpeedForm user={user} speedProfiles={speedProfiles} />
                            </div>
                        )}

                        {/* Notes */}
                        <div className={`bg-white rounded-xl shadow-sm border border-gray-100 p-5 lg:p-6 ${hasHotspot ? 'md:col-span-2' : ''}`}>
                            <h2 className="flex items-center gap-2 text-sm font-semibold text-gray-900 mb-3">
                                {SVG(P.note, 'w-4 h-4 text-yellow-500')}
                                {t('user.notes')}
                            </h2>
                            <p className={`text-sm ${user.notes ? 'text-gray-600' : 'text-gray-400 italic'}`}>
                                {user.notes || t('user.no_notes')}
                            </p>
                        </div>
                    </div>

                    {showPackages && (
                        <Packages user={user} packages={packages} usages={packageUsages} templates={packageTemplates}
                            canAssign={canAssignPackages} storeUrl={packageStoreUrl} defaults={packageDefaults} errors={errors} />
                    )}

                    {/* Booking history */}
                    {hasBooking && (
                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                            <div className="flex items-center justify-between px-5 lg:px-6 py-4 border-b border-gray-100">
                                <h2 className="flex items-center gap-2 text-sm font-semibold text-gray-900">
                                    {SVG(P.calendar)}
                                    {t('user.booking_history')}
                                </h2>
                                {recentBookings.length > 0 && (
                                    <Link href={`/bookings?hotspot_user_id=${user.id}`} className="text-xs font-semibold text-blue-600 hover:text-blue-700">
                                        {t('user.view_all_bookings')} →
                                    </Link>
                                )}
                            </div>

                            {recentBookings.length === 0 ? (
                                <div className="px-5 lg:px-6 py-8 text-center">
                                    <p className="text-sm text-gray-400">{t('empty.no_bookings')}</p>
                                    <a href={bookHref} data-book-name={user.name} data-book-phone={user.phone} className="text-blue-600 hover:underline text-sm font-medium mt-2 inline-block">
                                        {t('booking.new_booking')}
                                    </a>
                                </div>
                            ) : (
                                <ul className="divide-y divide-gray-50">
                                    {recentBookings.map((b) => (
                                        <li key={b.id}>
                                            <Link href={`/bookings/${b.id}`} className="flex items-center gap-4 px-5 lg:px-6 py-3.5 hover:bg-gray-50 transition">
                                                <span className="w-11 h-11 shrink-0 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center">
                                                    {SVG(P.building, 'w-5 h-5', 1.8)}
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <p className="text-sm font-medium text-gray-900 truncate">{b.place}</p>
                                                    <p className="text-xs text-gray-400 mt-0.5">{b.date} · {b.total_hours} {t('table.th.hours')}</p>
                                                </div>
                                                <span className={`hidden sm:inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${b.status_class}`}>
                                                    {b.status_label}
                                                </span>
                                                <span className="text-sm font-semibold text-gray-900 shrink-0">{money(b.total_price)}</span>
                                                <svg className="w-4 h-4 text-gray-300 shrink-0 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 5l7 7-7 7" /></svg>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}
                </div>

                {/* ── Activity timeline ── */}
                <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden self-start">
                    <div className="px-5 lg:px-6 py-4 border-b border-gray-100">
                        <h2 className="flex items-center gap-2 text-sm font-semibold text-gray-900">
                            {SVG(P.bolt)}
                            {t('user.activity')}
                        </h2>
                    </div>
                    <ol className="px-5 lg:px-6 py-5 space-y-5">
                        {activity.map((item, i) => {
                            const [icon, tone, label] = ACTIVITY[item.type] || ACTIVITY.created;
                            const last = i === activity.length - 1;
                            return (
                                <li key={i} className={`relative flex gap-3 ${last ? '' : 'pb-5'}`}>
                                    {!last && <span className="absolute top-9 start-[0.9rem] bottom-0 w-px bg-gray-100" />}
                                    <span className={`relative z-10 w-7 h-7 shrink-0 rounded-full flex items-center justify-center ${tone}`}>
                                        {SVG(icon, 'w-3.5 h-3.5')}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm text-gray-800 leading-snug">
                                            {t(label)}
                                            {item.room && <> <span className="font-medium">{item.room}</span></>}
                                        </p>
                                        {item.price > 0 && (
                                            <p className="text-xs text-gray-500 mt-0.5">
                                                {money(item.price)}
                                                {item.minutes > 0 && <> · {item.minutes} {t('user.minutes')}</>}
                                            </p>
                                        )}
                                        <p className="text-xs text-gray-400 mt-0.5">{item.ago}</p>
                                    </div>
                                </li>
                            );
                        })}
                    </ol>
                </div>
            </div>

            {/* ── Lifetime figures ── */}
            {stats && (
                <div className="bg-white rounded-xl shadow-sm border border-gray-100 mt-6 grid grid-cols-1 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x rtl:sm:divide-x-reverse divide-gray-100">
                    <div className="px-6 py-5 text-center">
                        <p className="text-xs font-medium text-gray-500">{t('user.total_bookings')}</p>
                        <p className="text-2xl font-bold text-gray-900 mt-1">{stats.bookings}</p>
                    </div>
                    <div className="px-6 py-5 text-center">
                        <p className="text-xs font-medium text-gray-500">{t('user.total_spent')}</p>
                        <p className="text-2xl font-bold text-gray-900 mt-1">{money(stats.spent)}</p>
                        {stats.minutes > 0 && <p className="text-xs text-gray-400 mt-0.5">{stats.minutes} {t('user.shared_minutes')}</p>}
                    </div>
                    <div className="px-6 py-5 text-center">
                        <p className="text-xs font-medium text-gray-500">{t('user.last_booking')}</p>
                        <p className="text-2xl font-bold text-gray-900 mt-1">{stats.last || '—'}</p>
                    </div>
                </div>
            )}
        </>
    );
}
