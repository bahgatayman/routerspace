import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Badge, Button, ConfirmButton, Modal } from '../../Components/ui';
import { money } from '../../lib/format';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

/*
 * Booking detail. Every amount (totals, balance due, payment status, the open
 * session's running charge) is computed server-side (Booking helpers /
 * RoomPricingService) and only displayed here.
 */
const inputCls = 'border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500';
const PAY_BADGE = { paid: 'bg-green-100 text-green-700', partial: 'bg-amber-100 text-amber-700' };
const csrf = () => document.querySelector('meta[name="csrf-token"]').content;

function PaymentForm({ booking }) {
    const form = useForm({ amount: '' });
    const submit = (e) => {
        e.preventDefault();
        form.post(`/bookings/${booking.id}/payment`, { preserveScroll: true, onSuccess: () => form.reset('amount') });
    };
    return (
        <form onSubmit={submit} className="mt-4 pt-4 border-t border-gray-100 flex items-end gap-2">
            <div>
                <label className="block text-xs font-medium text-gray-500 mb-1" htmlFor="pay-amount">{t('booking.payment.record')}</label>
                <input id="pay-amount" type="number" name="amount" min="0.01" max={booking.balance_due} step="0.01"
                    placeholder={booking.balance_due.toFixed(2)} value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)}
                    className={`w-32 ${inputCls}`} />
            </div>
            <button type="submit" disabled={form.processing} className="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium">
                {t('booking.payment.record_submit')}
            </button>
            {form.errors.amount && <p className="text-xs text-red-600 self-center">{form.errors.amount}</p>}
        </form>
    );
}

function ItemsCard({ booking, sales }) {
    const add = useForm({ product_id: sales.products[0] ? String(sales.products[0].id) : '', quantity: 1 });
    const visit = (url, method, data) => router.visit(url, { method, data, preserveScroll: true });
    const editable = sales.editable;
    return (
        <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <h3 className="font-semibold text-gray-900 mb-4">{t('sales.items_extras')}</h3>
            {!editable && <p className="text-xs text-gray-400 mb-3">{t('sales.invoice_not_editable')}</p>}

            {sales.items.length > 0 ? (
                <div className="overflow-x-auto -mx-1 px-1">
                    <table className="w-full text-sm mb-4">
                        <tbody className="divide-y divide-gray-100">
                            {sales.items.map((item) => (
                                <tr key={item.id}>
                                    <td className="py-2 text-gray-900">{item.name}</td>
                                    <td className="py-2 text-center text-gray-500">
                                        {editable && (
                                            <button type="button" className="text-gray-400 hover:text-gray-700 px-1" aria-label={t('sales.decrease_quantity')}
                                                onClick={() => visit(`/bookings/${booking.id}/items/${item.id}`, 'patch', { quantity: item.quantity - 1 })}>&minus;</button>
                                        )}
                                        &times;{item.quantity}
                                        {editable && (
                                            <button type="button" className="text-gray-400 hover:text-gray-700 px-1" aria-label={t('sales.increase_quantity')}
                                                onClick={() => visit(`/bookings/${booking.id}/items/${item.id}`, 'patch', { quantity: item.quantity + 1 })}>&#43;</button>
                                        )}
                                    </td>
                                    <td className="py-2 text-right text-gray-900 font-medium">{money(item.line_total)}</td>
                                    <td className="py-2 text-right w-8">
                                        {editable && (
                                            <button type="button" className="text-gray-400 hover:text-red-600" title={t('common.delete')} aria-label={t('common.delete')}
                                                onClick={() => visit(`/bookings/${booking.id}/items/${item.id}`, 'delete')}>&times;</button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <p className="text-sm text-gray-400 mb-4">{t('sales.no_items_yet')}</p>
            )}

            {editable && (sales.products.length > 0 ? (
                <form onSubmit={(e) => { e.preventDefault(); add.post(`/bookings/${booking.id}/items`, { preserveScroll: true, onSuccess: () => add.setData('quantity', 1) }); }}
                    className="flex items-end gap-2 pt-4 border-t border-gray-100">
                    <div className="flex-1">
                        <label className="block text-xs font-medium text-gray-500 mb-1" htmlFor="item-product">{t('sales.product')}</label>
                        <select id="item-product" name="product_id" required value={add.data.product_id} onChange={(e) => add.setData('product_id', e.target.value)} className={`w-full ${inputCls}`}>
                            {sales.products.map((p) => <option key={p.id} value={String(p.id)}>{p.name} — {money(p.price)}</option>)}
                        </select>
                    </div>
                    <div className="w-20">
                        <label className="block text-xs font-medium text-gray-500 mb-1" htmlFor="item-qty">{t('sales.qty')}</label>
                        <input id="item-qty" type="number" name="quantity" min="1" max="1000" value={add.data.quantity} onChange={(e) => add.setData('quantity', e.target.value)} className={`w-full ${inputCls}`} />
                    </div>
                    <button type="submit" disabled={add.processing} className="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium">{t('sales.add')}</button>
                </form>
            ) : (
                <p className="text-xs text-gray-400 pt-4 border-t border-gray-100">
                    {t('sales.no_products_hint')} <Link href="/products/create" className="text-blue-600 hover:underline">{t('sales.add_product')}</Link>
                </p>
            ))}

            <div className="mt-5 pt-4 border-t border-gray-200 space-y-1 text-sm">
                <div className="flex justify-between text-gray-500"><span>{t('sales.room_charge')}</span><span>{money(sales.room_charge)}</span></div>
                <div className="flex justify-between text-gray-500"><span>{t('sales.items')}</span><span>{money(sales.items_total)}</span></div>
                <div className="flex justify-between text-base font-bold text-gray-900 pt-1">
                    <span>{t('sales.grand_total')}</span><span className="text-blue-600">{money(sales.grand_total)}</span>
                </div>
            </div>
        </div>
    );
}

/** Open Session checkout: live quote from close-preview, then POST close (both existing JSON endpoints). */
function CheckoutModal({ booking, open, onClose }) {
    const [preview, setPreview] = useState(null);
    const [busy, setBusy] = useState(false);

    // A fresh quote every time the modal opens (the old ls:open handler).
    useEffect(() => {
        if (!open) return undefined;
        let live = true;
        setPreview(null);
        setBusy(false);
        fetch(`/bookings/${booking.id}/close-preview`, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((data) => { if (live) setPreview(data); });
        return () => { live = false; };
    }, [open, booking.id]);

    const confirm = () => {
        setBusy(true);
        fetch(`/bookings/${booking.id}/close`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        })
            .then((r) => r.json())
            .then((data) => {
                if (!data.success) { setBusy(false); window.LS?.toast(data.message, { tone: 'danger' }); return; }
                onClose();
                router.reload({ onFinish: () => window.LS?.toast(data.message) });
            })
            .catch(() => setBusy(false));
    };

    return (
        <Modal open={open} onClose={onClose} title={t('booking.duration_type.checkout_confirm_title')}
            footer={<>
                <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
                <Button variant="primary" disabled={!preview} processing={busy} onClick={confirm}>{t('booking.duration_type.checkout')}</Button>
            </>}>
            {!preview ? (
                <div className="text-sm text-gray-500">{t('session.calculating')}</div>
            ) : (
                <div className="space-y-2 text-sm">
                    <div className="flex justify-between"><span className="text-gray-500">{t('booking.duration_type.duration_so_far')}</span><span className="font-medium">{preview.duration}</span></div>
                    <div className="flex justify-between"><span className="text-gray-500">{t('sales.room_charge')}</span><span className="font-medium">ج.م {preview.total_price}</span></div>
                    <div className="flex justify-between"><span className="text-gray-500">{t('sales.items')}</span><span className="font-medium">ج.م {preview.items_total}</span></div>
                    <div className="flex justify-between text-base font-bold pt-2 border-t border-gray-100"><span>{t('sales.grand_total')}</span><span className="text-cyan-600">ج.م {preview.grand_total}</span></div>
                </div>
            )}
        </Modal>
    );
}

function StatusButton({ booking, status, className, children }) {
    const [busy, setBusy] = useState(false);
    return (
        <button type="button" disabled={busy} className={className}
            onClick={() => router.post(`/bookings/${booking.id}/status`, { status }, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })}>
            {children}
        </button>
    );
}

export default function BookingShow({ booking: b, sales, canDelete }) {
    usePageTitle(`${t('booking.bookings')} #${b.number}`);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [checkoutOpen, setCheckoutOpen] = useState(false);

    const cancelButton = (
        <ConfirmButton href={`/bookings/${b.id}/status`} method="post" data={{ status: 'cancelled' }} message={t('booking.cancel_booking')}
            confirmLabel={t('btn.cancel_booking')} variant="danger-quiet" size={null} className="w-full">
            {t('btn.cancel_booking')}
        </ConfirmButton>
    );

    return (
        <>
            <div className="mb-6 flex items-center justify-between">
                <div><Link href="/bookings" className="text-sm text-gray-500 hover:text-gray-700">&larr; {t('btn.back_to_bookings')}</Link></div>
                {b.can_edit_link && (
                    // The booking form stays a Blade page: full page load.
                    <a href={`/bookings/${b.id}/edit`} className="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition text-sm font-medium">{t('booking.edit_booking')}</a>
                )}
            </div>

            <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div className="lg:col-span-2 space-y-6">
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <div className="flex items-center justify-between mb-6">
                            <div>
                                <h1 className="text-2xl font-bold text-gray-900">{t('booking.bookings')} #{b.number}</h1>
                                <p className="text-sm text-gray-500 mt-1">{t('table.th.created')} {b.created_label}</p>
                            </div>
                            <span className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${b.status_class}`}>{b.status_label}</span>
                        </div>

                        <dl className="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                            <div>
                                <dt className="text-gray-500">{t('booking.room')}</dt>
                                <dd className="text-gray-900 font-medium mt-1">
                                    {b.workspace_id && <Link href={`/workspaces/${b.workspace_id}`} className="text-blue-600 hover:underline">{b.workspace_name}</Link>} / {b.room_name}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-gray-500">{t('booking.user')}</dt>
                                <dd className="font-medium mt-1">
                                    {b.customer && <Link href={`/users/${b.customer.id}`} className="text-blue-600 hover:underline">{b.customer.name}</Link>}
                                    {b.party_size > 1 && <span className="text-gray-500 text-sm"> &middot; {t('session.party_of', { count: b.party_size })}</span>}
                                    {b.customer?.phone && <span className="text-gray-500"> &middot; {b.customer.phone}</span>}
                                    {b.customer?.email && <span className="text-gray-500 block">{b.customer.email}</span>}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-gray-500">{t('booking.date')}</dt>
                                <dd className="text-gray-900 font-medium mt-1">{b.date_label}</dd>
                            </div>
                            <div>
                                <dt className="text-gray-500">{t('common.time')}</dt>
                                <dd className="text-gray-900 font-medium mt-1">{b.time_range}</dd>
                            </div>
                            <div>
                                <dt className="text-gray-500">{t('booking.duration')}</dt>
                                <dd className="text-gray-900 font-medium mt-1">{b.duration_label}</dd>
                            </div>
                            <div>
                                {b.pricing_note ? (
                                    <>
                                        <dt className="text-gray-500">{t('pricing.applied')}</dt>
                                        <dd className="text-gray-900 font-medium mt-1">{b.pricing_note}{b.has_plan && <span className="ls-plan-tag">{t('plans.badge')}</span>}</dd>
                                    </>
                                ) : (
                                    <>
                                        <dt className="text-gray-500">{t('workspace.price_per_hour')}</dt>
                                        <dd className="text-gray-900 font-medium mt-1">{money(b.price_per_hour)}</dd>
                                    </>
                                )}
                            </div>
                            <div className="md:col-span-2">
                                {b.is_open ? (
                                    <>
                                        <dt className="text-gray-500">{t('booking.duration_type.current_amount')}</dt>
                                        <dd className="text-2xl font-bold text-cyan-600 mt-1" id="open-current-amount">{money(b.current_amount)}</dd>
                                    </>
                                ) : (
                                    <>
                                        <dt className="text-gray-500">{t('booking.total')}</dt>
                                        <dd className="text-2xl font-bold text-blue-600 mt-1">{money(b.net_room_charge)}</dd>
                                        {b.original_amount_label && <dd className="text-xs text-gray-400 mt-0.5">{b.original_amount_label}</dd>}
                                    </>
                                )}
                            </div>
                        </dl>

                        {b.package ? (
                            // Paid with prepaid hours: no cash due; the value is what those hours are worth.
                            <div className="mt-4 pt-4 border-t border-gray-100 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm" id="booking-package-coverage">
                                <Badge tone="info">{t('packages.covered_by')}</Badge>
                                <span className="font-medium text-gray-900">{b.package.name} · {b.package.hours_label}</span>
                                <span className="text-gray-500">{b.package.worth_label}</span>
                                {b.customer && <Link href={`/users/${b.customer.id}#packages`} className="ls-link">{b.customer.name} &rarr;</Link>}
                            </div>
                        ) : (
                            <div className="mt-4 pt-4 border-t border-gray-100 flex flex-wrap items-center gap-x-8 gap-y-2 text-sm">
                                <div><span className="text-gray-500">{t('booking.payment.paid_now')}</span><span className="font-medium text-gray-900 ms-1">{money(b.amount_paid)}</span></div>
                                <div><span className="text-gray-500">{t('booking.payment.remaining')}</span><span className="font-medium text-gray-900 ms-1">{money(b.balance_due)}</span></div>
                                <span className={`inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium ${PAY_BADGE[b.payment_status] || 'bg-gray-100 text-gray-600'}`}>{b.payment_status_label}</span>
                            </div>
                        )}

                        {b.can_record_payment && <PaymentForm booking={b} />}

                        {b.notes && (
                            <div className="mt-4 pt-4 border-t border-gray-100">
                                <dt className="text-sm text-gray-500">{t('booking.notes')}</dt>
                                <dd className="text-sm text-gray-900 mt-1">{b.notes}</dd>
                            </div>
                        )}
                    </div>

                    {sales && <ItemsCard booking={b} sales={sales} />}
                </div>

                <div className="space-y-4">
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                        <h3 className="font-semibold text-gray-900 mb-4">{t('common.actions')}</h3>

                        {b.status === 'pending' && (
                            <div className="space-y-2">
                                <StatusButton booking={b} status="confirmed" className="w-full bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium">{t('btn.confirm_booking')}</StatusButton>
                                {cancelButton}
                            </div>
                        )}
                        {b.status === 'confirmed' && (
                            <div className="space-y-2">
                                <StatusButton booking={b} status="completed" className="w-full bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition text-sm font-medium">{t('btn.mark_completed')}</StatusButton>
                                {cancelButton}
                            </div>
                        )}
                        {b.status === 'completed' && <p className="text-sm text-green-600 font-medium text-center">{t('status.completed')}</p>}
                        {b.is_open && (
                            <button type="button" className="w-full bg-cyan-600 text-white px-4 py-2 rounded-lg hover:bg-cyan-700 transition text-sm font-medium" onClick={() => setCheckoutOpen(true)}>
                                {t('booking.duration_type.checkout')}
                            </button>
                        )}

                        {b.status === 'checked_in' ? (
                            <p className="text-xs text-gray-500 mt-3">{t('booking.delete_disabled_checked_in')}</p>
                        ) : b.is_open ? (
                            <p className="text-xs text-gray-500 mt-3">{t('booking.duration_type.delete_disabled_open')}</p>
                        ) : canDelete ? (
                            <button type="button" className="w-full mt-3 bg-white border border-red-200 text-red-600 px-4 py-2 rounded-lg hover:bg-red-50 transition text-sm font-medium" onClick={() => setDeleteOpen(true)}>
                                {t('btn.delete_booking')}
                            </button>
                        ) : null}
                    </div>
                </div>
            </div>

            {canDelete && !b.is_open && b.status !== 'checked_in' && (
                <Modal open={deleteOpen} onClose={() => setDeleteOpen(false)} title={t('booking.delete_booking')}
                    footer={<>
                        <Button variant="ghost" onClick={() => setDeleteOpen(false)}>{t('common.cancel')}</Button>
                        <Button variant="danger" processing={deleting}
                            onClick={() => router.delete(`/bookings/${b.id}`, { onStart: () => setDeleting(true), onFinish: () => { setDeleting(false); setDeleteOpen(false); } })}>
                            {t('btn.delete_booking')}
                        </Button>
                    </>}>
                    <p>{t('booking.delete_confirm_intro')}</p>
                    <ul className="list-disc ps-5 text-sm text-gray-600 space-y-1 mt-2">
                        <li>{t('booking.delete_consequence_revenue')}</li>
                        <li>{t('booking.delete_consequence_package')}</li>
                        <li>{t('booking.delete_consequence_coupon')}</li>
                        <li>{t('booking.delete_consequence_stock')}</li>
                    </ul>
                    <p className="text-xs text-gray-500 mt-3">{t('common.cannot_be_undone')}</p>
                </Modal>
            )}

            {b.is_open && <CheckoutModal booking={b} open={checkoutOpen} onClose={() => setCheckoutOpen(false)} />}
        </>
    );
}
