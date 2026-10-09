import { Link, router } from '@inertiajs/react';
import PeriodFilter, { egp } from '../../Components/Financials/PeriodFilter';
import { Pagination } from '../../Components/ui';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

const SELECT = 'border border-gray-200 rounded-md text-sm px-2 py-1.5 text-gray-700';
const PAY_CLASS = { paid: 'bg-green-100 text-green-700', partial: 'bg-amber-100 text-amber-700' };

export default function FinancialsTransactions({ bookings, exportUrl, periodKey, customStart, customEnd, status, source }) {
    usePageTitle(t('financials.transactions'));
    const rows = bookings.data;

    // Same GET params the Blade filter form submitted (period/start/end kept, page reset).
    const filter = (changes) => {
        const params = { period: periodKey, start: customStart ?? '', end: customEnd ?? '', status, source, ...changes };
        router.get('/financials/transactions', params, { preserveScroll: true });
    };

    return (
        <>
            <div className="flex flex-wrap items-center justify-between gap-3 mb-6">
                <h1 className="text-2xl font-bold text-gray-900">{t('financials.transactions')}</h1>
                <div className="flex items-center gap-2">
                    <Link href="/financials" className="px-4 py-2 rounded-lg text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 transition">{t('financials.overview')}</Link>
                    {/* File download: plain link. */}
                    <a href={exportUrl} className="px-4 py-2 rounded-lg text-sm font-medium bg-brand-600 text-white hover:bg-brand-700 transition">{t('financials.export')}</a>
                </div>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3 mb-6">
                <PeriodFilter periodKey={periodKey} customStart={customStart} customEnd={customEnd} />

                <div className="flex items-center gap-2">
                    <select name="status" value={status} onChange={(e) => filter({ status: e.target.value })} className={SELECT}>
                        <option value="completed">{t('financials.status_completed')}</option>
                        <option value="all">{t('financials.status_all')}</option>
                    </select>
                    <select name="source" value={source} onChange={(e) => filter({ source: e.target.value })} className={SELECT}>
                        <option value="all">{t('financials.source_all')}</option>
                        <option value="direct_booking">{t('financials.source_direct_booking')}</option>
                        <option value="shared_session">{t('financials.source_shared_session')}</option>
                        <option value="with_products">{t('financials.source_with_products')}</option>
                    </select>
                </div>
            </div>

            {rows.length === 0 ? (
                <div className="text-center py-16 bg-white rounded-xl border border-gray-100">
                    <p className="text-gray-500 text-sm">{t('financials.no_transactions')}</p>
                </div>
            ) : (
                <>
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left text-gray-500 border-b border-gray-100 bg-gray-50">
                                        <th className="px-6 py-3 font-medium">{t('financials.booking_number')}</th>
                                        <th className="px-6 py-3 font-medium">{t('financials.date')}</th>
                                        <th className="px-6 py-3 font-medium">{t('financials.customer')}</th>
                                        <th className="px-6 py-3 font-medium">{t('financials.room')}</th>
                                        <th className="px-6 py-3 font-medium">{t('financials.origin')}</th>
                                        <th className="px-6 py-3 font-medium">{t('financials.status')}</th>
                                        <th className="px-6 py-3 font-medium">{t('financials.payment_status')}</th>
                                        <th className="px-6 py-3 font-medium text-right">{t('financials.amount_paid')}</th>
                                        <th className="px-6 py-3 font-medium text-right">{t('financials.grand_total')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {rows.map((b) => (
                                        <tr key={b.id} className="hover:bg-gray-50 cursor-pointer" onClick={() => router.visit(`/financials/transactions/${b.id}`)}>
                                            <td className="px-6 py-4 font-medium text-gray-900">{b.ref}</td>
                                            <td className="px-6 py-4 text-gray-600">{b.date}</td>
                                            <td className="px-6 py-4 text-gray-600">{b.customer ?? '—'}</td>
                                            <td className="px-6 py-4 text-gray-600">{b.room ?? '—'}</td>
                                            <td className="px-6 py-4 text-gray-500 text-xs">{b.origin}</td>
                                            <td className="px-6 py-4">
                                                <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium ${b.status_class}`}>{b.status_label}</span>
                                                {b.status !== 'completed' && <span className="block text-[11px] text-gray-400 mt-0.5">{t('financials.not_counted')}</span>}
                                            </td>
                                            <td className="px-6 py-4">
                                                <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium ${PAY_CLASS[b.payment_status] || 'bg-gray-100 text-gray-600'}`}>{b.payment_label}</span>
                                            </td>
                                            <td className="px-6 py-4 text-right text-gray-600">{egp(b.amount_paid)}</td>
                                            <td className="px-6 py-4 text-right font-medium text-gray-900">{egp(b.grand_total)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div className="mt-6"><Pagination paginator={bookings} /></div>
                </>
            )}
        </>
    );
}
