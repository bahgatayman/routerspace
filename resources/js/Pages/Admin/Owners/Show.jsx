import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';
import BusinessHeader from '../Business/Header';

const STATUS_CLASS = {
    active: 'bg-green-100 text-green-800',
    expiring_soon: 'bg-yellow-100 text-yellow-800',
    expired: 'bg-red-100 text-red-800',
    never: 'bg-gray-100 text-gray-800',
    disabled: 'bg-red-100 text-red-800',
};

/* Same icons as admin/features/_icon. */
const FEATURE_ICON = {
    wifi: ['text-blue-500', 'M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0'],
    building: ['text-purple-500', 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-4 8v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
    calendar: ['text-green-500', 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
};

function FeatureIcon({ icon }) {
    const [color, d] = FEATURE_ICON[icon] || ['text-gray-400', 'M13 10V3L4 14h7v7l9-11h-7z'];
    return (
        <svg className={`w-5 h-5 ${color} shrink-0`} fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={d} />
        </svg>
    );
}

function monthsBetween(d1, d2) {
    let months = (d2.getFullYear() - d1.getFullYear()) * 12;
    months += d2.getMonth() - d1.getMonth();
    return Math.max(1, months + (d2.getDate() >= d1.getDate() ? 0 : -1));
}

function Row({ label, children }) {
    return (
        <div className="flex justify-between">
            <dt className="text-gray-500">{label}</dt>
            {children}
        </div>
    );
}

export default function OwnerShow({ business, owner, usersCount, plans, subscriptions, features }) {
    usePageTitle(owner.business_name);
    const [mode, setMode] = useState('months');
    const form = useForm({ plan_id: owner.plan_id ?? '', months: 1, expires_at: owner.default_until || '', notes: '' });
    const { data, setData } = form;

    // On-page preview only (same rule as before); the server computes and records the real amount.
    const price = plans.find((p) => String(p.id) === String(data.plan_id))?.price_per_month || 0;
    let months = 0;
    if (mode === 'date') {
        if (data.expires_at) months = monthsBetween(new Date(owner.renew_base), new Date(data.expires_at + 'T23:59:59'));
    } else {
        months = parseInt(data.months, 10) || 0;
    }
    const total = data.plan_id ? price * months : 0;

    const submit = (e) => {
        e.preventDefault();
        form.transform((d) => ({ plan_id: d.plan_id, notes: d.notes, ...(mode === 'months' ? { months: d.months } : { expires_at: d.expires_at }) }));
        form.post(`/admin/owners/${owner.id}/renew`, { preserveScroll: true, onSuccess: () => form.reset('notes') });
    };
    const tabClass = (m) => 'flex-1 text-sm font-medium py-1.5 rounded-md ' + (mode === m ? 'bg-white shadow-sm' : 'text-gray-500 hover:text-gray-700');
    const statusText = {
        active: t('status.active'),
        expiring_soon: `${t('status.expiring_soon')} (${owner.days_left}d)`,
        expired: t('status.expired'),
        never: t('status.never_activated'),
        disabled: t('status.disabled'),
    }[owner.status];

    return (
        <>
            <BusinessHeader business={business} active="subscription" />

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Owner Info */}
                <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div className="flex items-center justify-between mb-4">
                        <h2 className="text-lg font-semibold text-gray-800">{t('admin.owner_info')}</h2>
                    </div>
                    <dl className="space-y-3 text-sm">
                        <Row label={t('common.name')}><dd className="text-gray-900 font-medium">{owner.name}</dd></Row>
                        <Row label={t('common.email')}><dd className="text-gray-900">{owner.email}</dd></Row>
                        <Row label={t('label.business')}><dd className="text-gray-900 font-medium">{owner.business_name}</dd></Row>
                        {owner.has_hotspot && (
                            <>
                                <Row label={t('label.mikrotik_host')}><dd className="text-gray-900">{owner.mikrotik_host || '—'}</dd></Row>
                                <Row label={t('label.mikrotik_username')}><dd className="text-gray-900">{owner.mikrotik_username || '—'}</dd></Row>
                            </>
                        )}
                        <Row label={t('user.hotspot_users')}>
                            <dd className="text-gray-900">
                                <Link href={`/admin/owners/${owner.id}/users`} className="text-blue-600 hover:underline font-medium">{usersCount}</Link>
                            </dd>
                        </Row>
                    </dl>
                </div>

                {/* Subscription Card */}
                <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h2 className="text-lg font-semibold text-gray-800 mb-4">{t('admin.subscription')}</h2>

                    <div className="mb-4">
                        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${STATUS_CLASS[owner.status] || ''}`}>{statusText}</span>
                    </div>

                    {owner.expires_at && (
                        <dl className="space-y-2 text-sm mb-4">
                            <Row label={t('label.expires_at')}><dd className="text-gray-900 font-medium">{owner.expires_at}</dd></Row>
                            <Row label={t('label.days_remaining')}><dd className="text-gray-900">{owner.days_left}</dd></Row>
                        </dl>
                    )}

                    <h3 className="text-md font-semibold text-gray-800 mb-3 mt-6">{t('label.renew_subscription')}</h3>
                    <form onSubmit={submit}>
                        {/* Plan selector cards */}
                        <div className="grid grid-cols-2 gap-3 mb-4">
                            {plans.map((plan) => (
                                <label key={plan.id} className="cursor-pointer">
                                    <input type="radio" name="plan_id" value={plan.id} checked={String(data.plan_id) === String(plan.id)}
                                        onChange={() => setData('plan_id', plan.id)} className="sr-only peer" />
                                    <div className="border-2 rounded-xl p-3 transition peer-checked:border-red-500 peer-checked:bg-red-50 hover:border-gray-300">
                                        <p className="font-semibold text-sm text-gray-900">{plan.name}</p>
                                        <p className="text-xs text-gray-500">{plan.max_members} {t('plan.members')}</p>
                                        <p className="text-sm font-bold text-red-600 mt-1">{plan.price_label}</p>
                                    </div>
                                </label>
                            ))}
                        </div>
                        {form.errors.plan_id && <p className="text-xs text-red-600 -mt-2 mb-3">{form.errors.plan_id}</p>}

                        {/* Toggle: Months / Custom Date */}
                        <div className="flex gap-2 mb-3 bg-gray-100 rounded-lg p-1">
                            <button type="button" className={tabClass('months')} onClick={() => setMode('months')}>{t('table.th.months')}</button>
                            <button type="button" className={tabClass('date')} onClick={() => setMode('date')}>{t('common.date')}</button>
                        </div>

                        {mode === 'months' ? (
                            <div className="mb-3">
                                <label className="text-sm font-medium text-gray-700" htmlFor="months-input">{t('table.th.months')}</label>
                                <input type="number" name="months" min="1" max="24" id="months-input" value={data.months}
                                    onChange={(e) => setData('months', e.target.value)} className="mt-1 w-full border rounded-xl px-4 py-2.5" />
                                {form.errors.months && <p className="text-xs text-red-600 mt-1">{form.errors.months}</p>}
                            </div>
                        ) : (
                            <div className="mb-3">
                                <label className="text-sm font-medium text-gray-700" htmlFor="date-input">{t('label.expires_at')}</label>
                                <input type="date" name="expires_at" id="date-input" value={data.expires_at}
                                    onChange={(e) => setData('expires_at', e.target.value)} className="mt-1 w-full border rounded-xl px-4 py-2.5" />
                                {form.errors.expires_at && <p className="text-xs text-red-600 mt-1">{form.errors.expires_at}</p>}
                            </div>
                        )}

                        {/* Auto-calculated total */}
                        <div className="bg-gray-50 rounded-xl p-3 mb-3 text-sm">
                            <div className="flex justify-between">
                                <span className="text-gray-600">{t('label.total_amount')}</span>
                                <span id="total-amount" className="font-bold text-red-600">{total === 0 ? 'Free' : 'ج.م ' + total.toLocaleString()}</span>
                            </div>
                        </div>

                        <input type="text" name="notes" placeholder={t('placeholder.notes_optional')} value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)} className="w-full border rounded-xl px-4 py-2.5 mb-3 text-sm" />

                        <button type="submit" disabled={form.processing} className="w-full bg-red-600 text-white py-2.5 rounded-xl font-semibold hover:bg-red-700 disabled:opacity-60">
                            {t('btn.renew_subscription')}
                        </button>
                    </form>
                </div>
            </div>

            {/* Subscription History */}
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mt-6">
                <h2 className="text-lg font-semibold text-gray-800 mb-4">{t('label.subscription_history')}</h2>
                {subscriptions.length === 0 ? (
                    <p className="text-sm text-gray-500">{t('empty.no_subscriptions')}</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-left text-gray-500 bg-gray-50 border-b border-gray-100">
                                    <th className="px-4 py-3 font-medium">{t('table.th.months')}</th>
                                    <th className="px-4 py-3 font-medium">{t('table.th.starts_at')}</th>
                                    <th className="px-4 py-3 font-medium">{t('table.th.expires_at')}</th>
                                    <th className="px-4 py-3 font-medium">{t('table.th.notes')}</th>
                                    <th className="px-4 py-3 font-medium">{t('table.th.renewed_by')}</th>
                                    <th className="px-4 py-3 font-medium">{t('table.th.date')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {subscriptions.map((sub) => (
                                    <tr key={sub.id} className="border-b border-gray-50">
                                        <td className="px-4 py-3 font-medium">{sub.months}</td>
                                        <td className="px-4 py-3 text-gray-600">{sub.starts_at}</td>
                                        <td className="px-4 py-3 text-gray-600">{sub.expires_at}</td>
                                        <td className="px-4 py-3 text-gray-500 max-w-[200px] truncate">{sub.notes ?? '—'}</td>
                                        <td className="px-4 py-3">{sub.admin ?? '—'}</td>
                                        <td className="px-4 py-3 text-gray-500">{sub.date}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            {/* Feature Access */}
            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mt-6">
                <h3 className="text-lg font-semibold text-gray-900 mb-4">{t('admin.feature_access')}</h3>
                <div className="grid grid-cols-1 gap-3">
                    {features.map((feature) => (
                        <div key={feature.id} className={`flex items-center justify-between p-3 rounded-lg border ${feature.enabled ? 'bg-green-50 border-green-200' : 'bg-gray-50 border-gray-200'}`}>
                            <div className="flex items-center gap-3">
                                <FeatureIcon icon={feature.icon} />
                                <div>
                                    <p className="text-sm font-medium text-gray-900">{feature.name}</p>
                                    <p className="text-xs text-gray-500">{feature.description}</p>
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                {!feature.is_active ? (
                                    <span className="text-xs text-gray-400 bg-gray-100 px-2 py-1 rounded-full">{t('status.globally_disabled')}</span>
                                ) : (
                                    <button type="button"
                                        onClick={() => router.post(`/admin/owners/${owner.id}/features/${feature.id}/toggle`, {}, { preserveScroll: true })}
                                        className={`text-xs px-3 py-1 rounded-full font-medium transition ${feature.enabled
                                            ? 'bg-green-100 text-green-700 hover:bg-red-100 hover:text-red-700'
                                            : 'bg-gray-100 text-gray-600 hover:bg-green-100 hover:text-green-700'}`}>
                                        {feature.enabled ? '✓ ' + t('status.enabled') : '+ ' + t('btn.enable')}
                                    </button>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}
