import { Link, useForm } from '@inertiajs/react';
import { locale, t } from '../../lib/i18n';
import { money } from '../../lib/format';
import { usePageTitle } from '../../lib/pageTitle';

const INPUT = 'w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent';
const fixed = (v) => (v === null || v === undefined || v === '' ? '' : Number(v).toFixed(2));

/*
 * Product form (create + edit). Three groups: what it is → how it's priced
 * (purchase + selling, with a live profit/margin preview) → inventory (only
 * for physical products). The preview is display only; the server computes
 * and stores everything (ProductController, InventoryService).
 */
export default function ProductForm({ product }) {
    const isEdit = !!product;
    const title = isEdit ? t('sales.edit_product') : t('sales.add_product');
    usePageTitle(title);

    // Stock is set here only when tracking starts; afterwards it changes only via Restock/Remove.
    const stockLocked = isEdit && product.tracks_stock;
    const currency = locale() === 'ar' ? 'ج.م' : 'EGP';

    const form = useForm({
        name: product?.name ?? '',
        type: product?.type ?? 'product',
        sku: product?.sku ?? '',
        purchase_price: isEdit ? fixed(product.purchase_price) : '0.00',
        price: isEdit ? fixed(product.price) : '',
        track_stock: product?.track_stock ?? false,
        stock_quantity: product?.stock_quantity ?? 0,
        low_stock_threshold: product?.low_stock_threshold ?? '',
        description: product?.description ?? '',
        is_active: product?.is_active ?? true,
    });
    const { data, setData, errors } = form;

    const submit = (e) => {
        e.preventDefault();
        form.transform((d) => {
            const out = { ...d };
            if (stockLocked) delete out.stock_quantity;
            return out;
        });
        if (isEdit) form.put(`/products/${product.id}`); else form.post('/products');
    };

    // Display-only preview of the same formulas the server uses (Product::profitPerUnit/marginPercent).
    const p = parseFloat(data.price), c = parseFloat(data.purchase_price) || 0;
    const hasPreview = p > 0;
    const profit = hasPreview ? Math.round((p - c) * 100) / 100 : null;
    const margin = hasPreview ? Math.round((profit / p) * 1000) / 10 : null;

    const err = (k, cls = 'text-xs text-red-500 mt-1') => errors[k] && <p className={cls}>{errors[k]}</p>;

    return (
        <div className="max-w-2xl mx-auto">
            <Link href="/products" className="text-sm text-blue-600 hover:text-blue-800 mb-4 inline-block">&larr; {t('sales.back_to_products')}</Link>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h1 className="text-xl font-bold text-gray-900 mb-6">{title}</h1>

                <form onSubmit={submit}>
                    <div className="space-y-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1" htmlFor="p-name">{t('sales.name')} <span className="text-red-500">*</span></label>
                            <input type="text" id="p-name" name="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required className={INPUT} />
                            {err('name')}
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1" htmlFor="p-type">{t('sales.type')} <span className="text-red-500">*</span></label>
                                <select name="type" id="p-type" className={INPUT} value={data.type} onChange={(e) => setData('type', e.target.value)}>
                                    <option value="product">{t('sales.product')}</option>
                                    <option value="service">{t('sales.service')}</option>
                                </select>
                                <p className="text-xs text-gray-400 mt-1">{t('sales.type_hint')}</p>
                                {err('type')}
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1" htmlFor="p-sku">{t('sales.sku')}</label>
                                <input type="text" id="p-sku" name="sku" value={data.sku} onChange={(e) => setData('sku', e.target.value)} className={INPUT} />
                                {err('sku')}
                            </div>
                        </div>

                        {/* Pricing */}
                        <fieldset className="ls-inv-group">
                            <legend className="ls-inv-legend">{t('inventory.pricing')}</legend>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div className="ls-field">
                                    <label className="ls-label" htmlFor="p-cost">{t('inventory.purchase_price')}</label>
                                    <div className="ls-input-affix"><span aria-hidden="true">{currency}</span>
                                        <input type="number" step="0.01" min="0" id="p-cost" name="purchase_price" className="ls-input" inputMode="decimal"
                                            value={data.purchase_price} onChange={(e) => setData('purchase_price', e.target.value)} aria-describedby="p-cost-hint" />
                                    </div>
                                    <p className="ls-hint" id="p-cost-hint">{t('inventory.purchase_hint')}</p>
                                    {err('purchase_price', 'ls-error')}
                                </div>
                                <div className="ls-field">
                                    <label className="ls-label" htmlFor="p-price">{t('inventory.selling_price')} <span className="ls-req" aria-hidden="true">*</span></label>
                                    <div className="ls-input-affix"><span aria-hidden="true">{currency}</span>
                                        <input type="number" step="0.01" min="0.01" id="p-price" name="price" className="ls-input" inputMode="decimal" required
                                            value={data.price} onChange={(e) => setData('price', e.target.value)} />
                                    </div>
                                    {err('price', 'ls-error')}
                                </div>
                            </div>
                            <div className="ls-inv-preview" aria-live="polite">
                                <div><span>{t('inventory.profit_unit')}</span><b className={`ls-num ${hasPreview && profit < 0 ? 'is-negative' : ''}`}>{hasPreview ? money(profit) : '—'}</b></div>
                                <div><span>{t('inventory.margin')}</span><b className="ls-num">{hasPreview ? `${margin}%` : '—'}</b></div>
                            </div>
                        </fieldset>

                        {/* Inventory (physical products only) */}
                        <fieldset className="ls-inv-group" hidden={data.type === 'service'}>
                            <legend className="ls-inv-legend">{t('inventory.inventory')}</legend>
                            <label className="ls-inv-switch">
                                <input type="checkbox" name="track_stock" value="1" id="p-track" checked={!!data.track_stock} onChange={(e) => setData('track_stock', e.target.checked)} />
                                <span><b>{t('inventory.track')}</b><small>{t('inventory.track_hint')}</small></span>
                            </label>

                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4" hidden={!data.track_stock}>
                                <div className="ls-field">
                                    <label className="ls-label" htmlFor="p-stock">{t('inventory.current_stock')}</label>
                                    {stockLocked ? (
                                        <>
                                            <div className="ls-inv-locked">
                                                <b className="ls-num">{product.stock_quantity}</b>
                                                <Link className="ls-link" href={`/products/${product.id}`}>{t('inventory.adjust')}</Link>
                                            </div>
                                            <p className="ls-hint">{t('inventory.stock_locked_hint')}</p>
                                        </>
                                    ) : (
                                        <input type="number" min="0" step="1" id="p-stock" name="stock_quantity" className="ls-input" inputMode="numeric"
                                            value={data.stock_quantity} onChange={(e) => setData('stock_quantity', e.target.value)} />
                                    )}
                                    {err('stock_quantity', 'ls-error')}
                                </div>
                                <div className="ls-field">
                                    <label className="ls-label" htmlFor="p-threshold">{t('inventory.low_alert')}</label>
                                    <input type="number" min="0" step="1" id="p-threshold" name="low_stock_threshold" className="ls-input" inputMode="numeric" aria-describedby="p-threshold-hint"
                                        placeholder={t('inventory.low_alert_ph')} value={data.low_stock_threshold} onChange={(e) => setData('low_stock_threshold', e.target.value)} />
                                    <p className="ls-hint" id="p-threshold-hint">{t('inventory.low_alert_hint')}</p>
                                    {err('low_stock_threshold', 'ls-error')}
                                </div>
                            </div>
                        </fieldset>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1" htmlFor="p-desc">{t('sales.description')}</label>
                            <textarea name="description" id="p-desc" rows={3} className={INPUT} value={data.description} onChange={(e) => setData('description', e.target.value)} />
                            {err('description')}
                        </div>

                        <label className="flex items-center gap-2">
                            <input type="checkbox" name="is_active" value="1" checked={!!data.is_active} onChange={(e) => setData('is_active', e.target.checked)}
                                className="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                            <span className="text-sm text-gray-700">{t('sales.is_active')}</span>
                        </label>
                    </div>

                    <div className="mt-6 flex items-center gap-3">
                        <button type="submit" disabled={form.processing} className="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
                            {isEdit ? t('common.save') : t('sales.add_product')}
                        </button>
                        <Link href="/products" className="text-sm text-gray-600 hover:text-gray-800">{t('common.cancel')}</Link>
                    </div>
                </form>
            </div>
        </div>
    );
}
