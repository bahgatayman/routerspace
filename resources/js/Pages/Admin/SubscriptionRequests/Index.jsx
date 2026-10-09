import { Link, useForm } from '@inertiajs/react';
import { useRef } from 'react';
import { ConfirmButton } from '../../../Components/ui';
import { num } from '../../../lib/format';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';

const TONE = { green: 'bg-green-100 text-green-700', red: 'bg-red-100 text-red-700', yellow: 'bg-yellow-100 text-yellow-800', gray: 'bg-gray-100 text-gray-600' };
const egp = (v) => `ج.م ${num(v, 2)}`;

function RejectForm({ id }) {
    const details = useRef(null);
    const form = useForm({ admin_note: '' });
    const submit = (e) => {
        e.preventDefault();
        form.post(`/admin/subscription-requests/${id}/reject`, {
            preserveScroll: true,
            onSuccess: () => { form.reset(); if (details.current) details.current.open = false; },
        });
    };
    return (
        <details ref={details} className="relative">
            <summary className="list-none cursor-pointer border border-gray-200 text-gray-600 hover:bg-gray-50 px-4 py-2 rounded-lg text-sm font-medium">
                {t('subscription.reject')}
            </summary>
            <form onSubmit={submit} className="absolute end-0 mt-2 w-72 bg-white rounded-lg shadow-lg border border-gray-100 p-3 z-20">
                <label htmlFor={`reject-${id}`} className="block text-xs font-medium text-gray-600 mb-1">{t('subscription.reject_reason')}</label>
                <input id={`reject-${id}`} type="text" name="admin_note" maxLength={500} placeholder={t('subscription.reject_reason_placeholder')}
                    value={form.data.admin_note} onChange={(e) => form.setData('admin_note', e.target.value)}
                    className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-500" />
                {form.errors.admin_note && <p className="text-xs text-red-600 mt-1">{form.errors.admin_note}</p>}
                <button type="submit" disabled={form.processing} className="mt-2 w-full bg-red-600 text-white px-3 py-2 rounded-lg hover:bg-red-700 transition text-sm font-medium disabled:opacity-60">
                    {t('subscription.confirm_reject')}
                </button>
            </form>
        </details>
    );
}

export default function AdminSubscriptionRequests({ pending, handled }) {
    usePageTitle(t('subscription.admin_requests'));

    return (
        <>
            <div className="flex items-center justify-between mb-6">
                <h1 className="text-2xl font-bold text-gray-900">{t('subscription.admin_requests')}</h1>
                {pending.length > 0 && (
                    <span className="bg-yellow-100 text-yellow-800 px-3 py-1 rounded-full text-sm font-medium">
                        {pending.length} {t('subscription.status_pending')}
                    </span>
                )}
            </div>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div className="px-5 py-4 border-b border-gray-100">
                    <h2 className="text-sm font-semibold text-gray-900">{t('subscription.awaiting_approval')}</h2>
                    <p className="text-xs text-gray-500 mt-0.5">{t('subscription.approve_hint')}</p>
                </div>

                {pending.length === 0 ? (
                    <p className="px-5 py-10 text-center text-sm text-gray-400">{t('subscription.no_pending_requests')}</p>
                ) : (
                    <ul className="divide-y divide-gray-100">
                        {pending.map((req) => (
                            <li key={req.id} className="px-5 py-4">
                                <div className="flex flex-col lg:flex-row lg:items-center gap-4">
                                    <div className="min-w-0 flex-1">
                                        <Link href={`/admin/owners/${req.owner_id}`} className="font-medium text-gray-900 hover:text-blue-600">{req.business}</Link>
                                        <p className="text-sm text-gray-500 mt-0.5">
                                            {req.plan} · {req.months} {t('subscription.months')} ·{' '}
                                            <span className="font-semibold text-gray-900">{egp(req.amount)}</span>
                                        </p>
                                        <p className="text-xs text-gray-400 mt-0.5">
                                            {t('subscription.requested_on')} {req.requested}
                                            {req.expires && <> · {t('label.expires')} {req.expires}</>}
                                        </p>
                                        {req.note && <p className="text-sm text-gray-600 mt-2 bg-gray-50 rounded-lg px-3 py-2">“{req.note}”</p>}
                                    </div>

                                    <div className="flex items-center gap-2 shrink-0">
                                        <ConfirmButton href={`/admin/subscription-requests/${req.id}/approve`} method="post" tone="primary"
                                            variant="primary" size="md" message={t('subscription.approve_confirm')} confirmLabel={t('subscription.approve')}
                                            className="!bg-green-600 hover:!bg-green-700 !border-green-600">
                                            {t('subscription.approve')}
                                        </ConfirmButton>
                                        <RejectForm id={req.id} />
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {handled.length > 0 && (
                <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mt-6">
                    <div className="px-5 py-4 border-b border-gray-100">
                        <h2 className="text-sm font-semibold text-gray-900">{t('subscription.request_history')}</h2>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm text-left">
                            <thead className="bg-gray-50 text-gray-500 uppercase text-xs tracking-wider">
                                <tr>
                                    <th className="px-5 py-3">{t('label.business')}</th>
                                    <th className="px-5 py-3">{t('common.plan')}</th>
                                    <th className="px-5 py-3">{t('label.months')}</th>
                                    <th className="px-5 py-3">{t('common.total')}</th>
                                    <th className="px-5 py-3">{t('common.status')}</th>
                                    <th className="px-5 py-3">{t('admin.admin')}</th>
                                    <th className="px-5 py-3">{t('common.date')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {handled.map((req) => (
                                    <tr key={req.id}>
                                        <td className="px-5 py-3 font-medium text-gray-900">{req.business}</td>
                                        <td className="px-5 py-3">{req.plan}</td>
                                        <td className="px-5 py-3">{req.months}</td>
                                        <td className="px-5 py-3">{egp(req.amount)}</td>
                                        <td className="px-5 py-3">
                                            <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${TONE[req.color]}`}>{t(`subscription.status_${req.status}`)}</span>
                                        </td>
                                        <td className="px-5 py-3 text-gray-500">{req.admin ?? '—'}</td>
                                        <td className="px-5 py-3 text-gray-500">{req.handled ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </>
    );
}
