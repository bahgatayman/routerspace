import { router } from '@inertiajs/react';
import { ConfirmButton, Pagination } from '../../Components/ui';
import { t, tc } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

const TINT = { green: 'bg-green-100 text-green-600', yellow: 'bg-yellow-100 text-yellow-600', red: 'bg-red-100 text-red-600', blue: 'bg-blue-100 text-blue-600' };

export default function NotificationsIndex({ notifications, unreadCount }) {
    usePageTitle(t('notif.title'));
    const items = notifications.data;

    return (
        <>
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <div>
                    <h1 className="text-2xl font-bold text-gray-900">{t('notif.title')}</h1>
                    <p className="text-sm text-gray-500 mt-0.5">{tc('notif.unread_count', unreadCount, { count: unreadCount })}</p>
                </div>
                {unreadCount > 0 && (
                    <button type="button" onClick={() => router.post('/notifications/read-all', {}, { preserveScroll: true })}
                        className="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition text-sm font-medium">
                        {t('notif.mark_all_read')}
                    </button>
                )}
            </div>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 divide-y divide-gray-100">
                {items.length === 0 && (
                    <div className="px-6 py-16 text-center">
                        <svg className="w-12 h-12 mx-auto text-gray-300 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" /></svg>
                        <p className="text-gray-400 text-sm">{t('notif.empty')}</p>
                    </div>
                )}
                {items.map((n) => (
                    <div key={n.id} className={`flex items-start gap-4 px-4 sm:px-6 py-4 ${n.read ? '' : 'bg-blue-50/40'}`}>
                        <span className={`mt-0.5 shrink-0 w-10 h-10 rounded-full flex items-center justify-center ${TINT[n.level] || TINT.blue}`}>
                            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={n.icon_path} /></svg>
                        </span>
                        <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-2">
                                <h3 className="text-sm font-semibold text-gray-800">{n.title}</h3>
                                {!n.read && <span className="shrink-0 w-2 h-2 rounded-full bg-blue-500" />}
                            </div>
                            {n.body && <p className="text-sm text-gray-600 mt-0.5">{n.body}</p>}
                            <div className="flex items-center gap-3 mt-2">
                                <span className="text-xs text-gray-400">{n.ago}</span>
                                {/* Click-through is a server redirect to wherever the alert points (possibly a Blade page): normal navigation. */}
                                {n.has_action && <a href={`/notifications/${n.id}/open`} className="text-xs font-medium text-blue-600 hover:text-blue-800">{t('common.view')} →</a>}
                            </div>
                        </div>
                        <div className="flex items-center gap-1 shrink-0">
                            {!n.read && (
                                <button type="button" title={t('notif.mark_read')} aria-label={t('notif.mark_read')}
                                    onClick={() => router.post(`/notifications/${n.id}/read`, {}, { preserveScroll: true })}
                                    className="p-2 text-gray-400 hover:text-blue-600 rounded-lg hover:bg-gray-100 transition">
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" /></svg>
                                </button>
                            )}
                            <ConfirmButton href={`/notifications/${n.id}`} method="delete" message={t('notif.delete_confirm')} confirmLabel={t('common.delete')}
                                variant="ghost" icon="trash" iconOnly ariaLabel={t('common.delete')} className="text-gray-400 hover:text-red-600" />
                        </div>
                    </div>
                ))}
            </div>

            <div className="mt-4"><Pagination paginator={notifications} /></div>
        </>
    );
}
