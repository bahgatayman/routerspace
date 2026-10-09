import ErrorSummary from '../../Components/ErrorSummary';
import PlanPicker from '../../Components/Subscription/PlanPicker';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

const STATUS_TONE = { active: 'bg-green-100 text-green-700', expiring_soon: 'bg-yellow-100 text-yellow-800' };

export default function SubscriptionPlans(props) {
    const { owner } = props;
    usePageTitle(t('subscription.plans_title'));
    const status = owner.status;
    const statusKey = status === 'expiring_soon' ? 'expiring_soon' : status === 'active' ? 'active' : 'expired';

    return (
        <div className="max-w-5xl mx-auto">
            <ErrorSummary className="mb-6 space-y-1" />

            <div className="bg-gradient-to-br from-blue-800 to-blue-600 rounded-2xl text-white p-6 mb-6">
                <div className="flex flex-col sm:flex-row sm:items-start gap-6">
                    <div className="min-w-0 flex-1">
                        <p className="text-xs uppercase tracking-wide text-blue-100/80">{t('profile.current_plan')}</p>
                        <div className="flex flex-wrap items-center gap-2 mt-1">
                            <p className="text-2xl font-bold">{owner.plan_name ?? t('profile.no_plan')}</p>
                            <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${STATUS_TONE[status] || 'bg-red-100 text-red-700'}`}>{t('status.' + statusKey)}</span>
                        </div>

                        {owner.expires_at && (
                            <p className="text-sm text-blue-100/90 mt-2">
                                {status === 'expired' || status === 'never'
                                    ? t('subscription.expired_since', { date: owner.expires_at })
                                    : <>{t('subscription.current_expires')} <span className="font-semibold">{owner.expires_at}</span> · {t('subscription.days_left', { count: owner.days_left })}</>}
                            </p>
                        )}
                    </div>

                    {owner.plan_name != null && (
                        <div className="sm:w-56 shrink-0">
                            <div className="flex justify-between text-xs text-blue-100/90">
                                <span>{t('profile.members_used')}</span>
                                <span className="font-semibold">{owner.used_slots} / {owner.max_slots}</span>
                            </div>
                            <div className="w-full bg-white/25 rounded-full h-2 mt-2">
                                <div className={`h-2 rounded-full ${owner.usage >= 100 ? 'bg-red-300' : 'bg-white'}`} style={{ width: `${owner.usage}%` }} />
                            </div>
                            {owner.usage >= 100 && (
                                <p className="text-xs text-red-100 mt-2">{t('subscription.members_in_use', { used: owner.used_slots, total: owner.max_slots })}</p>
                            )}
                        </div>
                    )}
                </div>
            </div>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h1 className="text-lg font-semibold text-gray-900">{t('subscription.choose_plan')}</h1>
                <p className="text-sm text-gray-500 mt-1 mb-6">
                    {t('subscription.choose_plan_hint')}
                    {owner.expires_at && status !== 'expired' && <> {t('subscription.stacks_hint')}</>}
                </p>
                <PlanPicker {...props} />
            </div>
        </div>
    );
}
