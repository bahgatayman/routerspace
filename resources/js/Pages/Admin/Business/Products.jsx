import rowLink from '../../../Components/Admin/rowLink';
import { Link, usePage } from '@inertiajs/react';
import { ChartCard, FilterBar, PeriodFields, Stat, useFilters } from '../../../Components/analytics';
import { Badge, EmptyState, Icon, Pagination } from '../../../Components/ui';
import { money, num } from '../../../lib/format';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';
import BusinessHeader from './Header';

const STOCK_TONE = { out: 'danger', low: 'warn', in_stock: 'ok', untracked: 'neutral' };

export default function BusinessProducts({ business, products, stats, range, hasProductSales, productSummary, productTrendChart, topProductsChart, productInsights, filters: initial }) {
    usePageTitle(business.name, t('admin_biz.tabs.products'));
    const { url } = usePage();
    const query = new URLSearchParams(url.split('?')[1] || '');
    const hasFilters = ['q', 'type', 'stock', 'preset'].some((k) => query.has(k));
    const base = `/admin/owners/${business.id}/products`;
    const filters = useFilters({ q: initial.q || '', type: initial.type || '', stock: initial.stock || '', preset: range.preset, from: range.from, to: range.to });
    const { values, set } = filters;
    const tp = (k, r) => t(`admin_platform.${k}`, r);
    const rows = products.data;

    return (
        <div className="ls-biz-page">
            <BusinessHeader business={business} active="products" />

            <div className="ls-akpis">
                <Stat label={tp('col.products')} value={num(stats.total)} sub={tp('active_n', { n: stats.active })} />
                <Stat label={tp('low_stock')} value={num(stats.low)} tone={stats.low ? 'warn' : null} href={`${base}?stock=low`} />
                <Stat label={tp('out_of_stock')} value={num(stats.out)} tone={stats.out ? 'warn' : null} href={`${base}?stock=out`} />
                <Stat label={tp('inventory_value')} value={money(stats.inventory_value)} help={tp('help.inventory_value')} />
                <Stat label={tp('kpi.sales')} value={money(stats.sales)} tone="revenue" help={tp('help.sales')} />
            </div>

            {hasProductSales && (
                <>
                    <div className="ls-akpis">
                        <Stat label={t('dashboard.top_selling_product')} value={productSummary.topProduct || '—'} />
                        <Stat label={t('dashboard.total_units_sold')} value={num(productSummary.totalUnits)} />
                        <Stat label={t('dashboard.avg_product_order_value')} value={productSummary.avgOrderValue !== null ? money(productSummary.avgOrderValue) : '—'} />
                        <Stat label={t('dashboard.product_orders')} value={num(productSummary.orderCount)} />
                    </div>

                    <div className="ls-adm-grid">
                        <ChartCard id="biz-product-trend" title={t('dashboard.product_sales_trend')} spec={productTrendChart} height={240} />
                        <ChartCard id="biz-top-products" title={t('dashboard.top_selling_products')} spec={topProductsChart} height={240} />
                    </div>

                    {productInsights.length > 0 && (
                        <section className="ls-card">
                            <div className="ls-card-head"><h2 className="ls-card-title">{t('dashboard.product_insights')}</h2></div>
                            <div className="ls-card-body">
                                <ul style={{ margin: 0, paddingInlineStart: '1.25rem' }}>
                                    {productInsights.map((insight, i) => <li key={i} style={{ marginBottom: 'var(--space-2, 8px)' }}>{insight.text}</li>)}
                                </ul>
                            </div>
                        </section>
                    )}
                </>
            )}

            <FilterBar filters={filters} resetHref={base} showReset={hasFilters}>
                <div className="ls-field ls-filter-field ls-filter-field--grow">
                    <label className="ls-label" htmlFor="f-q">{t('common.search')}</label>
                    <div className="ls-search">
                        <Icon name="search" />
                        <input id="f-q" type="search" className="ls-input" placeholder={tp('search_products')} value={values.q} onChange={(e) => set('q', e.target.value)} />
                    </div>
                </div>
                <div className="ls-field ls-filter-field">
                    <label className="ls-label" htmlFor="f-type">{tp('col.type')}</label>
                    <select id="f-type" className="ls-select" value={values.type} onChange={(e) => set('type', e.target.value)}>
                        <option value="">{t('common.all')}</option>
                        <option value="product">{tp('product_types.product')}</option>
                        <option value="service">{tp('product_types.service')}</option>
                    </select>
                </div>
                <div className="ls-field ls-filter-field">
                    <label className="ls-label" htmlFor="f-stock">{tp('stock')}</label>
                    <select id="f-stock" className="ls-select" value={values.stock} onChange={(e) => set('stock', e.target.value)}>
                        <option value="">{t('common.all')}</option>
                        {['low', 'out', 'inactive'].map((s) => <option key={s} value={s}>{tp(`stock_filter.${s}`)}</option>)}
                    </select>
                </div>
                <PeriodFields filters={filters} />
            </FilterBar>

            <section className="ls-card">
                {rows.length === 0 ? (
                    <EmptyState title={tp('no_products')} />
                ) : (
                    <>
                        <div className="ls-table-wrap">
                            <table className="ls-table ls-adm-table">
                                <thead><tr>
                                    <th scope="col">{tp('col.product')}</th>
                                    <th scope="col">{tp('col.type')}</th>
                                    <th scope="col" className="is-num">{tp('price')}</th>
                                    <th scope="col" className="is-num">{tp('cost')}</th>
                                    <th scope="col" className="is-num">{tp('stock')}</th>
                                    <th scope="col" className="is-num">{tp('sold_period')}</th>
                                    <th scope="col" className="is-num">{tp('revenue_period')}</th>
                                    <th scope="col">{t('common.status')}</th>
                                </tr></thead>
                                <tbody>
                                    {rows.map((p) => (
                                        <tr key={p.id} {...rowLink(`${base}/${p.id}`)}>
                                            <td>
                                                <Link href={`${base}/${p.id}`} className="ls-adm-ident-name">{p.name}</Link>
                                                {p.sku && <small className="ls-adm-sub" dir="ltr">{p.sku}</small>}
                                            </td>
                                            <td>{tp(`product_types.${p.kind}`)}</td>
                                            <td className="is-money">{money(p.price)}</td>
                                            <td className="is-money">{p.purchase_price !== null ? money(p.purchase_price) : '—'}</td>
                                            <td className="is-num">{p.tracks_stock ? <Badge tone={STOCK_TONE[p.stock_status]} dot={false}>{num(p.stock_quantity)}</Badge> : '—'}</td>
                                            <td className="is-num">{num(p.sold_qty)}</td>
                                            <td className="is-money">{money(p.sold_revenue)}</td>
                                            <td>{p.is_active
                                                ? <span className="ls-status"><span className="ls-dot" />{t('status.active')}</span>
                                                : <Badge tone="neutral">{t('status.inactive')}</Badge>}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="ls-adm-pager">
                            <span className="ls-faint">{tp('showing', { from: products.from, to: products.to, total: products.total })}</span>
                            <Pagination paginator={products} />
                        </div>
                    </>
                )}
            </section>
        </div>
    );
}
