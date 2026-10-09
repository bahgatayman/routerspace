import { Link } from '@inertiajs/react';
import { egp } from '../../Components/Financials/PeriodFilter';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

export default function FinancialsTransactionShow({ booking, createdBy }) {
    usePageTitle(`${t('financials.transaction')} ${booking.ref}`);
    const sale = booking.sale;

    return (
        <div className="max-w-2xl mx-auto">
            <Link href="/financials/transactions" className="text-sm text-blue-600 hover:text-blue-800 mb-4 inline-block">&larr; {t('financials.back_to_transactions')}</Link>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-xl font-bold text-gray-900">{t('financials.transaction')} {booking.ref}</h1>
                        <p className="text-sm text-gray-500 mt-1">{booking.date} &middot; {booking.time_range}</p>
                    </div>
                    <span className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${booking.status_class}`}>{booking.status_label}</span>
                </div>

                {booking.status !== 'completed' && (
                    <div className="bg-gray-50 border border-gray-100 text-gray-500 text-xs rounded-lg px-3 py-2 mb-6">{t('financials.not_counted')}</div>
                )}

                <dl className="grid grid-cols-2 gap-4 text-sm mb-6">
                    <div>
                        <dt className="text-gray-500">{t('financials.customer')}</dt>
                        <dd className="text-gray-900 font-medium mt-1">{booking.customer ?? '—'}</dd>
                    </div>
                    <div>
                        <dt className="text-gray-500">{t('financials.room')}</dt>
                        <dd className="text-gray-900 font-medium mt-1">{booking.room ?? '—'}</dd>
                    </div>
                    <div>
                        <dt className="text-gray-500">{t('financials.origin')}</dt>
                        <dd className="text-gray-900 font-medium mt-1">{booking.origin}</dd>
                    </div>
                    {createdBy && (
                        <div>
                            <dt className="text-gray-500">{t('financials.created_by')}</dt>
                            <dd className="text-gray-900 font-medium mt-1">{createdBy.name}</dd>
                        </div>
                    )}
                </dl>

                <div className="border-t border-gray-100 pt-4 mb-4">
                    <div className="flex items-center justify-between text-sm">
                        <span className="text-gray-600">{t('financials.room_charge')} ({booking.pricing_note})</span>
                        <span className="font-medium text-gray-900">{egp(booking.total_price)}</span>
                    </div>
                    {booking.coupon_code !== null && (
                        <div className="flex items-center justify-between text-sm mt-1 text-red-600">
                            <span>{t('coupons.checkout.coupon_line', { code: booking.coupon_code })}</span>
                            <span>&minus;{egp(booking.discount_total)}</span>
                        </div>
                    )}
                </div>

                {sale && (
                    <div className="border-t border-gray-100 pt-4 mb-4">
                        <h3 className="text-sm font-semibold text-gray-700 mb-2">{t('financials.products')}</h3>
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-left text-gray-500">
                                    <th className="py-2 font-medium">{t('sales.item')}</th>
                                    <th className="py-2 font-medium text-center">{t('sales.qty')}</th>
                                    <th className="py-2 font-medium text-right">{t('sales.total')}</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-50">
                                {sale.items.map((item) => (
                                    <tr key={item.id}>
                                        <td className="py-2 text-gray-900">{item.name}</td>
                                        <td className="py-2 text-center text-gray-600">{item.quantity}</td>
                                        <td className="py-2 text-right text-gray-900">{egp(item.line_total)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {(sale.discount_total > 0 || sale.tax_total > 0) && (
                            <div className="mt-2 space-y-1 text-xs text-gray-500">
                                {sale.discount_total > 0 && <div className="flex justify-between"><span>{t('sales.total')} &ndash; discount</span><span>-{egp(sale.discount_total)}</span></div>}
                                {sale.tax_total > 0 && <div className="flex justify-between"><span>tax</span><span>{egp(sale.tax_total)}</span></div>}
                            </div>
                        )}
                    </div>
                )}

                <div className="border-t border-gray-200 pt-4 flex items-center justify-between">
                    <span className="font-semibold text-gray-700">{t('financials.grand_total')}</span>
                    <span className="text-xl font-bold text-blue-600">{egp(booking.grand_total)}</span>
                </div>
            </div>
        </div>
    );
}
