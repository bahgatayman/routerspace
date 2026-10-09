import { Link } from '@inertiajs/react';
import { Pagination } from '../../Components/ui';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

const RANGES = [['week', 'range_week'], ['month', 'range_month'], ['all', 'range_all']];

export default function StaffActivity({ staff, counts, activity, range }) {
    const title = t('staff.activity_for', { name: staff.name });
    usePageTitle(title);

    return (
        <>
            <div className="flex items-center justify-between mb-6">
                <h1 className="text-2xl font-bold text-gray-900">{title}</h1>
                <Link href="/staff" className="text-sm text-gray-500 hover:text-gray-700">&larr; {t('common.back')}</Link>
            </div>

            <div className="flex gap-2 mb-6">
                {RANGES.map(([value, labelKey]) => (
                    <Link key={value} href={`/staff/${staff.id}/activity?range=${value}`} preserveScroll
                        className={`px-3 py-1.5 rounded-lg text-sm font-medium transition ${range === value ? 'bg-blue-600 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:bg-gray-50'}`}>
                        {t('staff.' + labelKey)}
                    </Link>
                ))}
            </div>

            {counts.length === 0 ? (
                <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6 text-center text-gray-500 text-sm">{t('staff.no_activity')}</div>
            ) : (
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 mb-8">
                    {counts.map((c) => (
                        <div key={c.action} className="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                            <p className="text-2xl font-bold text-gray-900">{c.total}</p>
                            <p className="text-xs text-gray-500 mt-1">{c.label}</p>
                        </div>
                    ))}
                </div>
            )}

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-x-auto">
                <table className="w-full text-sm text-left">
                    <thead className="bg-gray-50 text-gray-500 uppercase text-xs tracking-wider">
                        <tr>
                            <th className="px-4 py-3">{t('table.th.created')}</th>
                            <th className="px-4 py-3">{t('common.action')}</th>
                            <th className="px-4 py-3">{t('common.description')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {activity.data.length === 0 && (
                            <tr><td colSpan={3} className="px-4 py-8 text-center text-gray-500">{t('staff.no_activity')}</td></tr>
                        )}
                        {activity.data.map((e) => (
                            <tr key={e.id}>
                                <td className="px-4 py-3 text-gray-500 whitespace-nowrap">{e.created}</td>
                                <td className="px-4 py-3 font-medium text-gray-900">{e.label}</td>
                                <td className="px-4 py-3 text-gray-500">{e.description}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <div className="mt-4"><Pagination paginator={activity} /></div>
        </>
    );
}
