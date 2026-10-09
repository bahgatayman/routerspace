import { Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { ConfirmButton } from '../../Components/ui';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

const STATUS_CLS = {
    active: 'bg-green-100 text-green-800',
    expiring_soon: 'bg-yellow-100 text-yellow-800',
    expired: 'bg-red-100 text-red-800',
    never: 'bg-gray-100 text-gray-800',
    disabled: 'bg-red-100 text-red-800',
};

function Row({ label, children, strong }) {
    return (
        <div className="flex justify-between">
            <dt className="text-gray-500">{label}</dt>
            <dd className={`text-gray-900 ${strong ? 'font-medium' : ''}`}>{children}</dd>
        </div>
    );
}

export default function ProfileIndex({ owner, plan, usageCount, usagePct, subscription }) {
    usePageTitle(t('profile.my_profile'));
    const form = useForm({ logo: null });
    const fileRef = useRef(null);
    const [preview, setPreview] = useState(null);

    // Release the object URL of the previous preview.
    useEffect(() => () => { if (preview) URL.revokeObjectURL(preview); }, [preview]);

    const pick = (e) => {
        const file = e.target.files[0] || null;
        form.setData('logo', file);
        if (file) setPreview(URL.createObjectURL(file));
    };

    const upload = (e) => {
        e.preventDefault();
        form.post('/profile/logo', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setPreview(null);
                if (fileRef.current) fileRef.current.value = '';
            },
        });
    };

    const shown = preview || owner.logo_url;

    return (
        <div className="max-w-3xl mx-auto">
            {form.errors.logo && <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6">{form.errors.logo}</div>}

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-2 space-y-6">
                    {/* Brand image */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <h2 className="text-lg font-semibold text-gray-800 mb-1">{t('profile.brand_image')}</h2>
                        <p className="text-sm text-gray-500 mb-5">{t('profile.brand_image_hint')}</p>

                        <form onSubmit={upload} className="flex flex-col sm:flex-row sm:items-center gap-5">
                            <div className="shrink-0">
                                {shown ? (
                                    <img id="logo-preview" src={shown} alt={preview ? '' : owner.business_name} className="w-20 h-20 rounded-xl object-cover border border-gray-200 bg-white" />
                                ) : (
                                    <div className="w-20 h-20 rounded-xl bg-gradient-to-br from-blue-600 to-blue-400 text-white flex items-center justify-center text-2xl font-bold">
                                        {owner.initials}
                                    </div>
                                )}
                            </div>

                            <div className="min-w-0 flex-1">
                                <input ref={fileRef} type="file" name="logo" id="logo-input" accept="image/jpeg,image/png,image/webp" required onChange={pick}
                                    className="block w-full text-sm text-gray-600 file:me-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 file:cursor-pointer" />
                                <p className="text-xs text-gray-400 mt-2">{t('profile.brand_image_rules')}</p>

                                <div className="flex items-center gap-3 mt-4">
                                    <button type="submit" disabled={form.processing} className="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm disabled:opacity-60">
                                        {t('profile.upload_image')}
                                    </button>
                                </div>
                            </div>
                        </form>

                        {owner.logo_url && (
                            <div className="mt-4 pt-4 border-t border-gray-100">
                                <ConfirmButton href="/profile/logo" method="delete" message={t('profile.remove_image_confirm')} confirmLabel={t('profile.remove_image')}>
                                    {t('profile.remove_image')}
                                </ConfirmButton>
                            </div>
                        )}
                    </div>

                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <h2 className="text-lg font-semibold text-gray-800 mb-4">{t('profile.account_information')}</h2>
                        <dl className="space-y-3 text-sm">
                            <Row label={t('common.name')} strong>{owner.name}</Row>
                            <Row label={t('common.email')}>{owner.email}</Row>
                            <Row label={t('label.business')} strong>{owner.business_name}</Row>
                        </dl>
                    </div>

                    {owner.has_hotspot && (
                        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                            <h2 className="text-lg font-semibold text-gray-800 mb-4">{t('profile.mikrotik_connection')}</h2>
                            <dl className="space-y-3 text-sm">
                                <Row label={t('label.mikrotik_host')}>{owner.mikrotik_host || '—'}</Row>
                                <Row label={t('label.mikrotik_port')}>{owner.mikrotik_port}</Row>
                                <Row label={t('label.mikrotik_username')}>{owner.mikrotik_username || '—'}</Row>
                            </dl>
                        </div>
                    )}
                </div>

                <div className="space-y-6">
                    <div className="bg-gradient-to-br from-blue-500 to-blue-600 rounded-2xl text-white p-6">
                        <p className="text-blue-100 text-sm">{t('profile.current_plan')}</p>
                        <p className="text-3xl font-bold mt-1">{plan.name}</p>
                        <p className="text-blue-100 mt-1">{plan.price}</p>
                        <div className="mt-4 pt-4 border-t border-blue-400">
                            <div className="flex justify-between text-sm">
                                <span className="text-blue-100">{t('profile.members_used')}</span>
                                <span className="font-semibold">{usageCount} / {plan.max_members}</span>
                            </div>
                            <div className="w-full bg-blue-400 rounded-full h-2 mt-2">
                                <div className="h-2 rounded-full bg-white" style={{ width: `${usagePct}%` }} />
                            </div>
                        </div>
                    </div>

                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <h2 className="text-sm font-semibold text-gray-800 mb-3">{t('profile.subscription')}</h2>
                        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${STATUS_CLS[subscription.status] || ''}`}>
                            {subscription.label}
                        </span>
                        {subscription.expires && (
                            <dl className="space-y-2 text-sm mt-3">
                                <Row label={t('profile.expires')} strong>{subscription.expires}</Row>
                                <Row label={t('profile.days_remaining')}>{subscription.days_remaining}</Row>
                            </dl>
                        )}

                        <Link href="/subscription/plans" className="mt-4 w-full inline-flex items-center justify-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                            {t('subscription.renew_subscription')}
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    );
}
