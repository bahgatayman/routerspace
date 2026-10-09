import { Pagination } from '../../../Components/ui';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';
import BusinessHeader from '../Business/Header';

export default function OwnerUsers({ business, users }) {
    usePageTitle(`${business.name} - ${t('user.hotspot_users')}`);
    const rows = users.data;

    return (
        <>
            <BusinessHeader business={business} active="members" />

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-gray-500 bg-gray-50 border-b border-gray-100">
                                <th className="px-4 lg:px-6 py-3 font-medium">{t('table.th.name')}</th>
                                <th className="px-4 lg:px-6 py-3 font-medium">{t('table.th.phone')}</th>
                                <th className="px-4 lg:px-6 py-3 font-medium">{t('table.th.download')}</th>
                                <th className="px-4 lg:px-6 py-3 font-medium">{t('table.th.upload')}</th>
                                <th className="px-4 lg:px-6 py-3 font-medium">{t('table.th.status')}</th>
                                <th className="px-4 lg:px-6 py-3 font-medium">{t('table.th.created')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="px-4 lg:px-6 py-8 text-center text-gray-500">{t('empty.no_owner_users')}</td>
                                </tr>
                            )}
                            {rows.map((user) => (
                                <tr key={user.id} className="border-b border-gray-50 hover:bg-gray-50 transition">
                                    <td className="px-4 lg:px-6 py-3 font-medium text-gray-900">{user.name}</td>
                                    <td className="px-4 lg:px-6 py-3 text-gray-700">{user.phone}</td>
                                    <td className="px-4 lg:px-6 py-3 text-gray-700">{user.speed_download}</td>
                                    <td className="px-4 lg:px-6 py-3 text-gray-700">{user.speed_upload}</td>
                                    <td className="px-4 lg:px-6 py-3">
                                        {user.active
                                            ? <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">{t('status.active')}</span>
                                            : <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">{t('status.inactive')}</span>}
                                    </td>
                                    <td className="px-4 lg:px-6 py-3 text-gray-500">{user.created}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {users.last_page > 1 && (
                    <div className="px-4 lg:px-6 py-3 border-t border-gray-100">
                        <Pagination paginator={users} />
                    </div>
                )}
            </div>
        </>
    );
}
