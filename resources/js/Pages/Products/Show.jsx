import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Badge, Banner, Button, Money, Modal, PageHeader, Pagination } from '../../Components/ui';
import { t, tc } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

const STATUS_TONE = { in_stock: 'ok', low: 'warn', out: 'danger' };

export default function ProductShow({ product, movements, sold, canManage, reasons }) {
    usePageTitle(product.name);
    const status = product.status;
    const tracked = status !== 'untracked';
    const [adjustOpen, setAdjustOpen] = useState(false);
    const subtitle = (product.is_service ? t('sales.service') : t('sales.product')) + (product.sku ? ` · ${product.sku}` : '');

    return (
        <>
            <Link href="/products" className="ls-link" style={{ display: 'inline-block', marginBottom: 'var(--space-4)' }}>&larr; {t('sales.back_to_products')}</Link>

            <PageHeader title={product.name} subtitle={subtitle}
                actions={canManage ? (
                    <>
                        <Button href={`/products/${product.id}/edit`}>{t('common.edit')}</Button>
                        {tracked && <Button variant="primary" onClick={() => setAdjustOpen(true)}>{t('inventory.adjust')}</Button>}
                    </>
                ) : null} />

            {/* Pricing + stock at a glance */}
            <div className="ls-strip">
                {tracked && (
                    <div>
                        <span className="ls-strip-label">{t('inventory.current_stock')}</span>
                        <span className={`ls-strip-value ${status === 'out' ? 'is-danger' : status === 'low' ? 'is-warn' : ''}`}>{product.stock_quantity}</span>
                        <span className="ls-strip-meta"><Badge tone={STATUS_TONE[status] || 'neutral'} dot={false}>{t(`inventory.status.${status}`)}</Badge></span>
                    </div>
                )}
                <div>
                    <span className="ls-strip-label">{t('inventory.purchase_price')}</span>
                    <span className="ls-strip-value"><Money amount={product.purchase_price} /></span>
                </div>
                <div>
                    <span className="ls-strip-label">{t('inventory.selling_price')}</span>
                    <span className="ls-strip-value"><Money amount={product.price} /></span>
                </div>
                <div>
                    <span className="ls-strip-label">{t('inventory.profit_unit')}</span>
                    <span className={`ls-strip-value ${product.profit < 0 ? 'is-danger' : 'is-revenue'}`}><Money amount={product.profit} /></span>
                    <span className="ls-strip-meta">{t('inventory.margin')} {product.margin !== null ? `${product.margin}%` : '—'}</span>
                </div>
                {tracked && (
                    <div>
                        <span className="ls-strip-label">{t('inventory.inventory_value')}</span>
                        <span className="ls-strip-value"><Money amount={product.inventory_value} /></span>
                        <span className="ls-strip-meta">{t('inventory.inventory_value_hint')}</span>
                    </div>
                )}
            </div>

            <div className="ls-inv-cols">
                {/* Sales to date (profit from each line's own cost snapshot) */}
                <section className="ls-card">
                    <div className="ls-card-head"><h2 className="ls-card-title">{t('inventory.sales_to_date')}</h2></div>
                    <div className="ls-card-body">
                        <dl className="ls-kv">
                            <dt>{t('inventory.sold_units')}</dt><dd>{sold.units}</dd>
                            <dt>{t('inventory.sold_revenue')}</dt><dd><Money amount={sold.revenue} /></dd>
                            <dt>{t('inventory.sold_profit')}</dt><dd className="ls-inv-profit"><Money amount={sold.profit} /></dd>
                        </dl>
                        <p className="ls-hint" style={{ margin: 'var(--space-3) 0 0' }}>{t('inventory.sales_basis')}</p>
                        {sold.unknown_cost_units > 0 && (
                            <p className="ls-hint" style={{ margin: 'var(--space-3) 0 0' }}>{tc('inventory.unknown_cost', sold.unknown_cost_units, { count: sold.unknown_cost_units })}</p>
                        )}
                    </div>
                </section>

                {tracked && (
                    <section className="ls-card">
                        <div className="ls-card-head"><h2 className="ls-card-title">{t('inventory.inventory')}</h2></div>
                        <div className="ls-card-body">
                            <dl className="ls-kv">
                                <dt>{t('inventory.inventory_value')}</dt><dd><Money amount={product.inventory_value} /></dd>
                                <dt>{t('inventory.potential_revenue')}</dt><dd><Money amount={product.potential_revenue} /></dd>
                                <dt>{t('inventory.potential_profit')}</dt><dd className="ls-inv-profit"><Money amount={product.potential_profit} /></dd>
                                {product.low_stock_threshold !== null && (
                                    <><dt>{t('inventory.low_alert')}</dt><dd>{product.low_stock_threshold}</dd></>
                                )}
                            </dl>
                        </div>
                    </section>
                )}
            </div>

            {!tracked && <Banner tone="info">{t('inventory.not_tracked_hint')}</Banner>}

            {/* Stock history */}
            {(tracked || movements.total > 0) && (
                <section className="ls-card" style={{ marginTop: 'var(--space-5)' }}>
                    <div className="ls-card-head"><h2 className="ls-card-title">{t('inventory.history')}</h2></div>
                    {movements.data.length === 0 ? (
                        <div className="ls-card-body"><p className="ls-hint" style={{ margin: 0 }}>{t('inventory.history_empty')}</p></div>
                    ) : (
                        <>
                            <div className="ls-table-wrap" style={{ marginTop: 'var(--space-3)' }}>
                                <table className="ls-table">
                                    <thead>
                                        <tr>
                                            <th>{t('inventory.col_date')}</th>
                                            <th className="is-num">{t('inventory.col_change')}</th>
                                            <th>{t('inventory.col_type')}</th>
                                            <th>{t('inventory.col_ref')}</th>
                                            <th>{t('inventory.col_by')}</th>
                                            <th className="is-num">{t('inventory.col_after')}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {movements.data.map((m) => (
                                            <tr key={m.id}>
                                                <td className="ls-num" title={m.date_full}>{m.date_short}</td>
                                                <td className="is-num"><span className={`ls-inv-change ${m.change >= 0 ? 'is-in' : 'is-out'}`}><bdi dir="ltr">{m.change > 0 ? '+' : ''}{m.change}</bdi></span></td>
                                                <td>
                                                    {m.label}
                                                    {m.reason && <span className="ls-faint"> · {m.reason}</span>}
                                                    {m.note && <div className="ls-faint" style={{ fontSize: '12.5px' }}>{m.note}</div>}
                                                </td>
                                                <td>
                                                    {m.booking_id ? (
                                                        <Link className="ls-link" href={`/bookings/${m.booking_id}`}>{m.booking_ref}</Link>
                                                    ) : m.walk_in ? (
                                                        <a className="ls-link" href="/active-sessions">{t('sales.walk_in')}</a>
                                                    ) : (
                                                        <span className="ls-faint">—</span>
                                                    )}
                                                </td>
                                                <td className="ls-faint">{m.by ?? '—'}</td>
                                                <td className="is-num">{m.after}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <div className="ls-card-body" style={{ paddingTop: 'var(--space-3)' }}><Pagination paginator={movements} /></div>
                        </>
                    )}
                </section>
            )}

            {tracked && canManage && (
                <AdjustStockModal product={product} reasons={reasons} open={adjustOpen} setOpen={setAdjustOpen} />
            )}
        </>
    );
}

/** Restock (+) or remove with a reason (−); never below zero (checked server-side). The preview is display only. */
function AdjustStockModal({ product, reasons, open, setOpen }) {
    const form = useForm({ direction: 'restock', quantity: '', reason: reasons[0]?.value ?? '', note: '' });
    const { data, setData } = form;
    const current = product.stock_quantity;
    const n = parseInt(data.quantity, 10) || 0;
    const next = data.direction === 'restock' ? current + n : current - n;

    const submit = (e) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, reason: d.direction === 'remove' ? d.reason : null }));
        form.post(`/products/${product.id}/stock`, {
            preserveScroll: true,
            onSuccess: (page) => {
                // An InsufficientStockException comes back as an error flash: keep the dialog open like the Blade page did.
                if (page.props.flash?.error) return;
                form.reset();
                setOpen(false);
            },
        });
    };

    return (
        <Modal id="adjust-stock" open={open} onClose={() => setOpen(false)} title={t('inventory.adjust')} subtitle={product.name} size="narrow"
            footer={<>
                <Button variant="ghost" onClick={() => setOpen(false)}>{t('common.cancel')}</Button>
                <span className="ls-push"><Button type="submit" variant="primary" form="adjust-form" processing={form.processing}>{t('inventory.save')}</Button></span>
            </>}>
            <form id="adjust-form" className="ls-stack" onSubmit={submit}>
                <div className="ls-inv-seg" role="radiogroup" aria-label={t('inventory.adjust')}>
                    <label><input type="radio" name="direction" value="restock" checked={data.direction === 'restock'} onChange={() => setData('direction', 'restock')} /><span>+ {t('inventory.restock')}</span></label>
                    <label><input type="radio" name="direction" value="remove" checked={data.direction === 'remove'} onChange={() => setData('direction', 'remove')} /><span>− {t('inventory.remove')}</span></label>
                </div>
                <div className="ls-field">
                    <label className="ls-label" htmlFor="adj-qty">{t('inventory.quantity')}</label>
                    <input type="number" min="1" step="1" id="adj-qty" name="quantity" className="ls-input" inputMode="numeric" required
                        value={data.quantity} onChange={(e) => setData('quantity', e.target.value)} />
                    {form.errors.quantity && <p className="ls-error">{form.errors.quantity}</p>}
                </div>
                <div className="ls-field" hidden={data.direction !== 'remove'}>
                    <label className="ls-label" htmlFor="adj-reason">{t('inventory.reason')}</label>
                    <select id="adj-reason" name="reason" className="ls-select" value={data.reason} onChange={(e) => setData('reason', e.target.value)}>
                        {reasons.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
                    </select>
                    {form.errors.reason && <p className="ls-error">{form.errors.reason}</p>}
                </div>
                <div className="ls-field">
                    <label className="ls-label" htmlFor="adj-note">{t('inventory.note')}</label>
                    <input type="text" id="adj-note" name="note" maxLength={255} className="ls-input" value={data.note} onChange={(e) => setData('note', e.target.value)} />
                </div>
                <div className="ls-paper">
                    <div className="ls-row-split"><span className="ls-muted">{t('inventory.current_stock')}</span><b className="ls-num">{current}</b></div>
                    <div className="ls-row-split"><span className="ls-muted">{t('inventory.new_stock')}</span><b className={`ls-num ${next < 0 ? 'is-negative' : ''}`}>{next}</b></div>
                </div>
            </form>
        </Modal>
    );
}
