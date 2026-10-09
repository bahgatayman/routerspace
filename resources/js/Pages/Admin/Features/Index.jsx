import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmButton } from '../../../Components/ui';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';

/* Port of admin/features/_icon.blade.php */
const ICONS = {
    wifi: ['text-blue-500', 'M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0'],
    building: ['text-purple-500', 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-4 8v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
    calendar: ['text-green-500', 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
    default: ['text-gray-400', 'M13 10V3L4 14h7v7l9-11h-7z'],
};

function FeatureIcon({ icon }) {
    const [color, d] = ICONS[icon] || ICONS.default;
    return (
        <svg className={`w-5 h-5 ${color} shrink-0`} fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={d} />
        </svg>
    );
}

const th = 'px-4 lg:px-6 py-3 font-medium';
const td = 'px-4 lg:px-6 py-3';

export default function AdminFeaturesIndex({ features, owners }) {
    usePageTitle(t('section.features'));
    const [busy, setBusy] = useState(null);

    // Per-business switch: POST /admin/owners/{owner}/features/{feature}/toggle.
    const toggleForOwner = (owner, feature) => router.post(`/admin/owners/${owner.id}/features/${feature.id}/toggle`, {}, {
        preserveScroll: true,
        onStart: () => setBusy(`${owner.id}-${feature.id}`),
        onFinish: () => setBusy(null),
    });

    return (
        <>
            <h1 className="text-2xl font-bold text-gray-900 mb-6">{t('section.features')}</h1>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-8">
                <div className="px-4 lg:px-6 py-4 border-b border-gray-100">
                    <h2 className="text-lg font-semibold text-gray-800">{t('admin.global_features')}</h2>
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-start text-gray-500 bg-gray-50 border-b border-gray-100">
                                <th className={`${th} text-start`}>{t('admin.feature')}</th>
                                <th className={`${th} text-start`}>{t('admin.key')}</th>
                                <th className={`${th} text-start`}>{t('admin.description')}</th>
                                <th className={`${th} text-start`}>{t('admin.owners_using')}</th>
                                <th className={`${th} text-start`}>{t('admin.global_status')}</th>
                                <th className={`${th} text-start`}>{t('common.actions')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {features.map((f) => {
                                const label = f.is_active ? t('admin.disable_globally') : t('admin.enable_globally');
                                return (
                                    <tr key={f.id} className="border-b border-gray-50 hover:bg-gray-50 transition">
                                        <td className={td}>
                                            <div className="flex items-center gap-3">
                                                <FeatureIcon icon={f.icon} />
                                                <span className="font-medium text-gray-900">{f.name}</span>
                                            </div>
                                        </td>
                                        <td className={`${td} text-gray-500 font-mono text-xs`}>{f.key}</td>
                                        <td className={`${td} text-gray-500 max-w-[250px]`}>{f.description}</td>
                                        <td className={`${td} text-gray-700`}>{f.owners_count}</td>
                                        <td className={td}>
                                            {f.is_active
                                                ? <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">{t('status.active')}</span>
                                                : <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">{t('status.disabled')}</span>}
                                        </td>
                                        <td className={td}>
                                            <ConfirmButton href={`/admin/features/${f.id}/toggle-global`} method="post" variant="ghost"
                                                tone={f.is_active ? 'danger' : 'primary'} message={`${label} '${f.name}'?`} confirmLabel={label}
                                                className={f.is_active ? '!text-red-600 hover:!text-red-800' : '!text-green-600 hover:!text-green-800'}>
                                                {label}
                                            </ConfirmButton>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div className="px-4 lg:px-6 py-4 border-b border-gray-100">
                    <h2 className="text-lg font-semibold text-gray-800">{t('admin.feature_access_by_owner')}</h2>
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-gray-500 bg-gray-50 border-b border-gray-100">
                                <th className={`${th} text-start`}>{t('admin.owner')}</th>
                                <th className={`${th} text-start`}>{t('admin.business')}</th>
                                {features.map((f) => <th key={f.id} className={`${th} text-center`}>{f.name}</th>)}
                            </tr>
                        </thead>
                        <tbody>
                            {owners.length === 0 ? (
                                <tr><td colSpan={2 + features.length} className="px-4 lg:px-6 py-8 text-center text-gray-500">{t('empty.no_owners')}</td></tr>
                            ) : owners.map((o) => (
                                <tr key={o.id} className="border-b border-gray-50 hover:bg-gray-50 transition">
                                    <td className={td}><Link href={`/admin/owners/${o.id}`} className="text-blue-600 hover:underline font-medium">{o.name}</Link></td>
                                    <td className={`${td} text-gray-700`}>{o.business_name}</td>
                                    {features.map((f) => {
                                        const on = o.feature_ids.includes(f.id);
                                        return (
                                            <td key={f.id} className={`${td} text-center`}>
                                                {!f.is_active ? (
                                                    <span className="text-xs text-gray-400">—</span>
                                                ) : (
                                                    <button type="button" onClick={() => toggleForOwner(o, f)} disabled={busy === `${o.id}-${f.id}`}
                                                        title={on ? t('btn.disable') : t('btn.enable')}
                                                        aria-label={`${on ? t('btn.disable') : t('btn.enable')}: ${f.name} — ${o.business_name || o.name}`}
                                                        aria-pressed={on}
                                                        className={on
                                                            ? 'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 hover:bg-green-200 disabled:opacity-60'
                                                            : 'inline-flex items-center px-2 py-0.5 rounded-full text-xs text-gray-400 hover:bg-gray-100 hover:text-gray-600 disabled:opacity-60'}>
                                                        {on ? t('status.enabled') : '—'}
                                                    </button>
                                                )}
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
}
