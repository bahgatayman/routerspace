import { Head, usePage } from '@inertiajs/react';
import ErrorSummary from '../../Components/ErrorSummary';
import PlanPicker from '../../Components/Subscription/PlanPicker';
import { t } from '../../lib/i18n';

/** Standalone lock screen for a lapsed subscription: plan request form + logout, no panel shell. */
export default function SubscriptionExpired(props) {
    const { owner } = props;
    const { flash = {} } = usePage().props;
    const csrf = typeof document !== 'undefined' ? document.querySelector('meta[name="csrf-token"]')?.content || '' : '';

    return (
        // dvh, not vh: mobile URL bars make 100vh taller than the visible area.
        <div className="min-h-[100dvh] bg-gradient-to-br from-gray-50 to-red-50 p-4 sm:p-8">
            <Head title={`${t('status.expired')} - ${t('auth.linkspace')}`} />
            <div className="w-full max-w-4xl mx-auto">
                <div className="bg-white rounded-2xl shadow-lg p-6 sm:p-8 text-center">
                    <div className="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg className="w-8 h-8 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                        </svg>
                    </div>

                    <h1 className="text-2xl font-bold text-gray-900">{t('label.subscription')} {t('status.expired')}</h1>
                    <p className="text-gray-600 text-sm mt-2 max-w-lg mx-auto">{t('subscription.expired_hint')}</p>

                    <dl className="mt-6 inline-flex flex-wrap justify-center gap-x-8 gap-y-2 text-sm">
                        <div className="flex items-center gap-2">
                            <dt className="text-gray-500">{t('label.business')}</dt>
                            <dd className="text-gray-900 font-medium">{owner.business_name}</dd>
                        </div>
                        {owner.plan_name != null && (
                            <div className="flex items-center gap-2">
                                <dt className="text-gray-500">{t('profile.current_plan')}</dt>
                                <dd className="text-gray-900 font-medium">{owner.plan_name}</dd>
                            </div>
                        )}
                        {owner.expires_at && (
                            <div className="flex items-center gap-2">
                                <dt className="text-gray-500">{t('label.expires')}</dt>
                                <dd className="text-red-600 font-medium">{owner.expires_at}</dd>
                            </div>
                        )}
                    </dl>
                </div>

                {flash.success && <div className="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mt-4">{flash.success}</div>}
                {flash.error && <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mt-4">{flash.error}</div>}
                <ErrorSummary className="mt-4 space-y-1" />

                <div className="bg-white rounded-2xl shadow-lg p-6 sm:p-8 mt-6">
                    <h2 className="text-lg font-semibold text-gray-900 text-center">{t('subscription.choose_plan')}</h2>
                    <p className="text-sm text-gray-500 text-center mt-1 mb-6">{t('subscription.choose_plan_hint')}</p>
                    <PlanPicker {...props} />
                </div>

                <div className="text-center mt-6">
                    {/* Plain POST: logging out must reload the whole document. */}
                    <form method="POST" action="/logout">
                        <input type="hidden" name="_token" value={csrf} />
                        <button type="submit" className="text-sm text-gray-500 hover:text-gray-700 font-medium">{t('nav.logout')}</button>
                    </form>
                </div>
            </div>
        </div>
    );
}

SubscriptionExpired.layout = (page) => page;
