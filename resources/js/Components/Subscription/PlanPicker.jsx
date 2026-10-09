import { useForm } from '@inertiajs/react';
import { ConfirmButton } from '../ui';
import { t } from '../../lib/i18n';

const Check = ({ className = 'w-4 h-4 text-green-500 shrink-0', width = 3 }) => (
    <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={width} d="M5 13l4 4L19 7" /></svg>
);

const TONE = { green: 'bg-green-100 text-green-700', red: 'bg-red-100 text-red-700', yellow: 'bg-yellow-100 text-yellow-800', gray: 'bg-gray-100 text-gray-600' };

/** A request is open: show its state instead of a second form. */
function PendingRequest({ req }) {
    return (
        <div className="rounded-xl border border-yellow-200 bg-yellow-50 p-5 text-start">
            <div className="flex items-start gap-3">
                <span className="mt-0.5 shrink-0 w-8 h-8 rounded-full bg-yellow-100 text-yellow-700 flex items-center justify-center">
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </span>
                <div className="min-w-0 flex-1">
                    <p className="font-semibold text-gray-900">{t('subscription.request_pending_title')}</p>
                    <p className="text-sm text-gray-600 mt-1">
                        {req.plan_name} · {req.months} {t('subscription.months')} · <span className="font-medium">{req.amount}</span>
                    </p>
                    <p className="text-xs text-gray-500 mt-1">{t('subscription.requested_on')} {req.requested_on}</p>
                    <p className="text-sm text-gray-600 mt-3">{t('subscription.request_pending_hint')}</p>
                    <div className="mt-3">
                        <ConfirmButton href={`/subscription/request/${req.id}`} method="delete" message={t('subscription.cancel_request_confirm')} confirmLabel={t('subscription.cancel_request')}>
                            {t('subscription.cancel_request')}
                        </ConfirmButton>
                    </div>
                </div>
            </div>
        </div>
    );
}

function RequestForm({ plans, owner, monthOptions }) {
    const initialPlan = plans.some((p) => p.id === owner.plan_id) ? owner.plan_id : '';
    const form = useForm({ plan_id: initialPlan, months: 1, note: '' });
    const term = Number(form.data.months) || 1;
    const chosen = plans.find((p) => p.id === form.data.plan_id);

    const submit = (e) => {
        e.preventDefault();
        form.post('/subscription/request', { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} id="plan-form" className="text-start">
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                {plans.map((plan) => {
                    const on = form.data.plan_id === plan.id;
                    return (
                        <label key={plan.id} className={`plan-option group relative flex flex-col cursor-pointer rounded-2xl border-2 bg-white overflow-hidden transition hover:border-blue-300 hover:shadow-md focus-within:ring-2 focus-within:ring-blue-500 ${on ? 'border-blue-600 shadow-md' : 'border-gray-200'}`}>
                            <input type="radio" name="plan_id" value={plan.id} className="sr-only" checked={on} onChange={() => form.setData('plan_id', plan.id)} required />

                            <span className={`plan-check absolute top-4 end-4 w-6 h-6 rounded-full bg-blue-600 text-white items-center justify-center ${on ? 'flex' : 'hidden'}`}>
                                <Check className="w-3.5 h-3.5" />
                            </span>

                            <div className="p-5 pb-4">
                                {owner.plan_id === plan.id && (
                                    <span className="inline-block mb-2 text-[10px] font-semibold uppercase tracking-wide bg-gray-100 text-gray-500 px-2 py-0.5 rounded-full">
                                        {t('subscription.your_plan')}
                                    </span>
                                )}
                                <p className="font-semibold text-gray-900">{plan.name}</p>
                                <div className="mt-3 flex items-baseline gap-1.5">
                                    {plan.is_free ? (
                                        <span className="text-3xl font-bold text-gray-900">{t('subscription.free')}</span>
                                    ) : (
                                        <>
                                            <span className="text-sm text-gray-500">ج.م</span>
                                            <span className="text-3xl font-bold text-gray-900">{plan.price_whole}</span>
                                            <span className="text-xs text-gray-500">{t('subscription.per_month_each')}</span>
                                        </>
                                    )}
                                </div>
                                <p className="plan-card-total text-xs text-gray-400 mt-1 h-4">
                                    {plan.is_free || term === 1 ? '' : `${plan.totals[term]} / ${term} ${t('subscription.months')}`}
                                </p>
                            </div>

                            <div className="border-t border-gray-100 p-5 pt-4 flex-1">
                                <ul className="space-y-2 text-sm">
                                    {plan.limits.map((l) => (
                                        <li key={l.label} className="flex items-center gap-2 text-gray-600">
                                            <Check />
                                            <span><span className="font-medium text-gray-900">{l.value}</span> {l.label}</span>
                                        </li>
                                    ))}
                                    {plan.features.map((f) => (
                                        <li key={f} className="flex items-center gap-2 text-gray-600"><Check />{f}</li>
                                    ))}
                                </ul>
                            </div>
                        </label>
                    );
                })}
            </div>

            <div className="mt-8">
                <p className="text-sm font-medium text-gray-700 mb-2">{t('subscription.duration')}</p>
                <div className="inline-flex flex-wrap gap-2" role="radiogroup">
                    {monthOptions.map((m) => {
                        const on = term === m;
                        return (
                            <label key={m} className="month-option cursor-pointer">
                                <input type="radio" name="months" value={m} className="sr-only" checked={on} onChange={() => form.setData('months', m)} required />
                                <span className={`month-pill inline-flex items-center justify-center min-w-[5.5rem] px-4 py-2 rounded-lg border-2 bg-white text-sm font-medium transition hover:border-blue-300 ${on ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-gray-200 text-gray-600'}`}>
                                    {m} {t('subscription.months')}
                                </span>
                            </label>
                        );
                    })}
                </div>
            </div>

            <div className="mt-6 rounded-xl border border-gray-200 bg-gray-50 p-5">
                <div className="flex flex-col sm:flex-row sm:items-center gap-4">
                    <div className="min-w-0 flex-1">
                        <p className="text-xs font-semibold uppercase tracking-wide text-gray-400">{t('subscription.summary')}</p>
                        <p id="summary-line" className="text-sm text-gray-700 mt-1">
                            {chosen ? `${chosen.name} · ${term} ${t('subscription.months')}${chosen.is_free ? '' : ` × ${chosen.price}`}` : '—'}
                        </p>
                    </div>
                    <div className="sm:text-end">
                        <p className="text-xs text-gray-500">{t('subscription.billed_total')}</p>
                        <p id="plan-total" className="text-2xl font-bold text-gray-900">{chosen ? (chosen.is_free ? t('subscription.free') : chosen.totals[term]) : '—'}</p>
                    </div>
                </div>

                <div className="mt-4 pt-4 border-t border-gray-200">
                    <label htmlFor="note" className="block text-xs font-medium text-gray-600 mb-1.5">
                        {t('common.notes')} <span className="text-gray-400 font-normal">({t('admin_notif.optional')})</span>
                    </label>
                    <input type="text" name="note" id="note" maxLength={500} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)}
                        placeholder={t('subscription.note_placeholder')}
                        className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>

                <button type="submit" id="plan-submit" disabled={form.processing}
                    className="mt-4 w-full sm:w-auto inline-flex items-center justify-center gap-2 bg-blue-600 text-white px-6 py-2.5 rounded-lg hover:bg-blue-700 transition font-medium text-sm shadow-sm disabled:opacity-60">
                    <span id="plan-submit-label">{chosen?.is_free ? t('subscription.activate_free') : t('subscription.request_renewal')}</span>
                    <svg className="w-4 h-4 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M17 8l4 4m0 0l-4 4m4-4H3" /></svg>
                </button>

                {/* Free plan is self-serve — no admin approval or payment step. */}
                {!chosen?.is_free && <p id="payment-hint" className="text-xs text-gray-400 mt-3">{t('subscription.no_online_payment_hint')}</p>}
            </div>
        </form>
    );
}

/**
 * Port of partials/plan-picker: plan grid + renewal request form, shared by
 * /subscription/expired (standalone) and /subscription/plans. There is no
 * payment gateway — submitting raises a request an admin approves.
 */
export default function PlanPicker({ owner, plans, pendingRequest, recentRequests, monthOptions }) {
    return (
        <>
            {pendingRequest ? <PendingRequest req={pendingRequest} />
                : plans.length === 0 ? <p className="text-sm text-gray-500 text-center py-8">{t('subscription.no_plans')}</p>
                    : <RequestForm plans={plans} owner={owner} monthOptions={monthOptions} />}

            {recentRequests.length > 0 && (
                <div className="mt-8 text-start">
                    <h3 className="text-sm font-semibold text-gray-700 mb-3">{t('subscription.your_requests')}</h3>
                    <ul className="divide-y divide-gray-100 rounded-xl border border-gray-100 bg-white">
                        {recentRequests.map((req) => (
                            <li key={req.id} className="flex items-center gap-3 px-4 py-3 text-sm">
                                <div className="min-w-0 flex-1">
                                    <p className="font-medium text-gray-900 truncate">{req.plan_name} · {req.months} {t('subscription.months')}</p>
                                    <p className="text-xs text-gray-400">{req.date}</p>
                                    {req.admin_note && <p className="text-xs text-gray-500 mt-0.5">{req.admin_note}</p>}
                                </div>
                                <span className="text-gray-600">{req.amount}</span>
                                <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${TONE[req.color] || TONE.gray}`}>{req.status_label}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </>
    );
}
