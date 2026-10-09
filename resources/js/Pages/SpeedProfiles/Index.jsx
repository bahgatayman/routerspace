import { Link, router } from '@inertiajs/react';
import { ConfirmButton } from '../../Components/ui';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

export default function SpeedProfilesIndex({ profiles }) {
    usePageTitle(t('speed.speed_profiles'));

    return (
        <>
            <div className="flex items-center justify-between mb-6">
                <h1 className="text-2xl font-bold text-gray-900">{t('speed.speed_profiles')}</h1>
                <Link href="/speed-profiles/create" className="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
                    {t('speed.add_profile')}
                </Link>
            </div>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
                <table className="w-full text-sm text-left">
                    <thead className="bg-gray-50 text-gray-500 uppercase text-xs tracking-wider">
                        <tr>
                            <th className="px-4 py-3">{t('speed.name')}</th>
                            <th className="px-4 py-3">{t('speed.download')}</th>
                            <th className="px-4 py-3">{t('speed.upload')}</th>
                            <th className="px-4 py-3">{t('speed.default')}</th>
                            <th className="px-4 py-3">{t('speed.users')}</th>
                            <th className="px-4 py-3">{t('common.actions')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {profiles.map((p) => (
                            <tr key={p.id} className="hover:bg-gray-50 transition">
                                <td className="px-4 py-3 font-medium text-gray-900">{p.name}</td>
                                <td className="px-4 py-3">{p.speed_download}</td>
                                <td className="px-4 py-3">{p.speed_upload}</td>
                                <td className="px-4 py-3">
                                    {p.is_default && <span className="bg-green-100 text-green-700 px-2 py-1 rounded-full text-xs font-medium">{t('speed.default')}</span>}
                                </td>
                                <td className="px-4 py-3">{p.users_count}</td>
                                <td className="px-4 py-3 flex gap-2 items-center">
                                    <Link href={`/speed-profiles/${p.id}/edit`} className="text-blue-600 hover:underline text-sm font-medium">{t('common.edit')}</Link>
                                    <ConfirmButton href={`/speed-profiles/${p.id}`} method="delete" message={t('speed.delete_confirm')} confirmLabel={t('common.delete')} variant="ghost">
                                        <span className="text-red-600">{t('common.delete')}</span>
                                    </ConfirmButton>
                                    {!p.is_default && (
                                        <button type="button" onClick={() => router.post(`/speed-profiles/${p.id}/set-default`, {}, { preserveScroll: true })}
                                            className="text-blue-600 hover:underline text-sm font-medium">{t('speed.set_as_default')}</button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <p className="text-xs text-gray-400 mt-4">{t('speed.default_profile_note')}</p>
        </>
    );
}
