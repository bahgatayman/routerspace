import { Link } from '@inertiajs/react';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

export default function SessionsIndex({ sessions, error, updatedAt }) {
    usePageTitle(t('section.wifi_sessions'));

    return (
        <>
            <div className="flex items-center justify-between mb-6">
                <h1 className="text-2xl font-bold text-gray-900">{t('section.wifi_sessions')}</h1>
                <Link href="/sessions" preserveScroll className="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition text-sm font-medium">
                    {t('btn.refresh')}
                </Link>
            </div>

            <p className="text-sm text-gray-500 mb-4">Last updated: {updatedAt}</p>

            {error && (
                <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
                    {t('msg.mikrotik_connection_error', { message: error })}<br />
                    <Link href="/settings" className="underline font-medium">{t('msg.check_mikrotik_settings')}</Link>
                </div>
            )}

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-4">
                <p className="text-sm font-medium text-gray-700">{sessions.length} user(s) currently online</p>
            </div>

            {sessions.length > 0 ? (
                <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
                    <table className="w-full text-sm text-left">
                        <thead className="bg-gray-50 text-gray-500 uppercase text-xs tracking-wider">
                            <tr>
                                <th className="px-4 py-3">{t('table.th.name')}</th>
                                <th className="px-4 py-3">{t('table.th.phone')} / {t('auth.username')}</th>
                                <th className="px-4 py-3">IP Address</th>
                                <th className="px-4 py-3">Uptime</th>
                                <th className="px-4 py-3">Downloaded</th>
                                <th className="px-4 py-3">Uploaded</th>
                                <th className="px-4 py-3">{t('common.actions')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {sessions.map((s, i) => (
                                <tr key={i} className="hover:bg-gray-50 transition">
                                    <td className="px-4 py-3 font-medium text-gray-900">{s.name}</td>
                                    <td className="px-4 py-3">{s.login ?? '-'}</td>
                                    <td className="px-4 py-3 font-mono text-xs">{s.ip ?? '-'}</td>
                                    <td className="px-4 py-3">{s.uptime ?? '-'}</td>
                                    <td className="px-4 py-3">{s.downloaded ?? '-'}</td>
                                    <td className="px-4 py-3">{s.uploaded ?? '-'}</td>
                                    <td className="px-4 py-3">
                                        {s.user_id
                                            ? <Link href={`/users/${s.user_id}`} className="text-blue-600 hover:underline text-sm font-medium">{t('common.view')}</Link>
                                            : <span className="text-gray-400 text-xs">-</span>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : !error && (
                <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-12 text-center">
                    <p className="text-gray-500 text-lg">{t('empty.no_active_sessions')}</p>
                </div>
            )}
        </>
    );
}
