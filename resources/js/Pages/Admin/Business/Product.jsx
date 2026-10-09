import { Link } from '@inertiajs/react';
import { FilterBar, PeriodFields, Stat, useFilters } from '../../../Components/analytics';
import { Badge, EmptyState } from '../../../Components/ui';
import { money, num } from '../../../lib/format';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';
import BusinessHeader from './Header';

const STOCK_TONE = { out: 'danger', low: 'warn', in_stock: 'ok', untracked: 'neutral' };

export default function BusinessProduct({ business, range, product: p, stats, recent, movements }) {
    usePageTitle(p.name, business.name);
    const filters = useFilters({ preset: range.preset, from: range.from, to: range.to });
    const tp = (k, r) => t(`admin_platform.${k}`, r);

    return (
        <div className="ls-biz-page">
            <BusinessHeader business={business} active="products" />

            <Link href={`/admin/owners/${business.id}/products`} className="ls-biz-back">&larr; {t('admin_biz.tabs.products')}</Link>
            <header className="ls-biz-head">
                <div className="ls-biz-id">
                    <h1 className="ls-biz-name">
                        {p.name}{' '}
                        {p.tracks_stock && <Badge tone={STOCK_TONE[p.stock_status]}>{tp(`stock_status.${p.stock_status}`)}</Badge>}{' '}
                        {!p.is_active && <Badge tone="neutral">{t('status.inactive')}</Badge>}
                    </h1>
                    <p className="ls-biz-meta">{tp(`product_types.${p.kind}`)}{p.sku && <> · <span dir="ltr">{p.sku}</span></>}</p>
                    {p.description && <p className="ls-faint" style={{ margin: 0 }}>{p.description}</p>}
                </div>
            </header>

            <FilterBar filters={filters} variant="secondary">
                <PeriodFields filters={filters} />
            </FilterBar>

            <div className="ls-akpis">
                <Stat label={tp('price')} value={money(p.price)} sub={p.margin !== null ? tp('margin', { pct: p.margin }) : null} />
                <Stat label={tp('stock')} value={p.tracks_stock ? num(p.stock_quantity) : '—'}
                    sub={p.tracks_stock && p.low_stock_threshold !== null ? tp('threshold', { n: p.low_stock_threshold }) : tp('not_tracked_stock')} />
                <Stat label={tp('sold_period')} value={num(stats.qty)} />
                <Stat label={tp('revenue_period')} value={money(stats.revenue)} tone="revenue" />
                <Stat label={tp('gross_profit')} value={money(stats.profit)} help={tp('help.gross_profit')} />
                <Stat label={tp('lifetime_revenue')} value={money(stats.revenue_all)} sub={tp('units_n', { n: num(stats.qty_all) })} />
            </div>

            <div className="ls-adm-grid">
                <section className="ls-card">
                    <div className="ls-card-head"><h2 className="ls-card-title">{tp('recent_sales')}</h2></div>
                    <div className="ls-card-body ls-card-body--flush">
                        {recent.length === 0 ? (
                            <EmptyState title={tp('no_sales')} />
                        ) : (
                            <div className="ls-table-wrap"><table className="ls-table">
                                <thead><tr>
                                    <th scope="col">{tp('col.date')}</th>
                                    <th scope="col" className="is-num">{tp('qty')}</th>
                                    <th scope="col" className="is-num">{tp('line_total')}</th>
                                    <th scope="col">{tp('related')}</th>
                                </tr></thead>
                                <tbody>
                                    {recent.map((i) => (
                                        <tr key={i.id}>
                                            <td className="ls-nowrap">{i.date}</td>
                                            <td className="is-num">{i.quantity}</td>
                                            <td className="is-money">{money(i.line_total)}</td>
                                            <td>{i.booking_id ? <Link href={`/admin/bookings/${i.booking_id}`} className="ls-link">#{i.booking_id}</Link> : tp('counter_sale')}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table></div>
                        )}
                    </div>
                </section>

                <section className="ls-card">
                    <div className="ls-card-head"><h2 className="ls-card-title">{tp('stock_movements')}</h2></div>
                    <div className="ls-card-body ls-card-body--flush">
                        {movements.length === 0 ? (
                            <EmptyState title={tp('no_movements')} />
                        ) : (
                            <div className="ls-table-wrap"><table className="ls-table">
                                <thead><tr>
                                    <th scope="col">{tp('col.date')}</th>
                                    <th scope="col">{tp('col.type')}</th>
                                    <th scope="col" className="is-num">{tp('change')}</th>
                                    <th scope="col" className="is-num">{tp('stock')}</th>
                                </tr></thead>
                                <tbody>
                                    {movements.map((m) => (
                                        <tr key={m.id}>
                                            <td className="ls-nowrap">{m.date}</td>
                                            <td>{m.type}{m.note && <small className="ls-adm-sub">{m.note}</small>}</td>
                                            <td className="is-num">{m.change}</td>
                                            <td className="is-num">{m.new_quantity}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table></div>
                        )}
                    </div>
                </section>
            </div>
        </div>
    );
}
