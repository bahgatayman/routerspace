import { Link, router } from '@inertiajs/react';
import { Badge, Button, ConfirmButton, EmptyState, Icon, Money, PageHeader, Pagination, withQuery } from '../../Components/ui';
import { t, tc } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

/*
 * Products as a card grid: name and selling price first (what staff scan
 * for), then cost/profit, then stock with its status — Low and Out stand
 * out, untracked products show no stock at all. Filters are server-side.
 */
export default function ProductsIndex({ products, stock, counts }) {
    usePageTitle(t('sales.products'));
    const items = products.data;

    return (
        <>
            <PageHeader title={t('sales.products')} count={counts.all}
                actions={<Button variant="primary" icon="plus" href="/products/create">{t('sales.add_product')}</Button>} />

            {counts.all > 0 && (counts.low || counts.out || stock) ? (
                <nav className="ls-chips" style={{ marginBottom: 'var(--space-5)' }} aria-label={t('inventory.stock')}>
                    {[['all', null], ['low', 'low'], ['out', 'out']].map(([key, value]) => {
                        const on = stock === value;
                        return (
                            <Link key={key} href={withQuery(window.location.href, { stock: value, page: null })} preserveScroll
                                className={`ls-chip ${on ? 'is-active' : ''}`} aria-current={on ? 'page' : undefined}>
                                {t(`inventory.filter_${key}`)} <span className="ls-chip-count">{counts[key]}</span>
                            </Link>
                        );
                    })}
                </nav>
            ) : null}

            {items.length === 0 ? (
                <div className="ls-card">
                    {stock ? (
                        <EmptyState illustration="box" title={t(`inventory.filter_${stock}`)} text={t('sales.no_products')}>
                            <Button href="/products">{t('inventory.filter_all')}</Button>
                        </EmptyState>
                    ) : (
                        <EmptyState illustration="box" title={t('sales.no_products')}>
                            <Button variant="primary" icon="plus" href="/products/create">{t('sales.add_product')}</Button>
                        </EmptyState>
                    )}
                </div>
            ) : (
                <>
                    <div className="ls-product-grid">
                        {items.map((p) => <ProductCard key={p.id} product={p} />)}
                    </div>
                    <div className="mt-6"><Pagination paginator={products} /></div>
                </>
            )}
        </>
    );
}

function ProductCard({ product: p }) {
    const status = p.status;
    return (
        <article className={`ls-product ${p.is_active ? '' : 'is-inactive'} ${status === 'out' ? 'is-out' : ''}`} aria-labelledby={`product-${p.id}-name`}>
            <div className="ls-product-top">
                <span className={`ls-product-icon ${p.is_service ? 'is-service' : 'is-product'}`} aria-hidden="true">
                    <Icon name={p.is_service ? 'bolt' : 'box'} />
                </span>
                {!p.is_active ? (
                    <Badge tone="neutral" dot={false}>{t('status.inactive')}</Badge>
                ) : status === 'out' ? (
                    <Badge tone="danger">{t('inventory.status.out')}</Badge>
                ) : status === 'low' ? (
                    <Badge tone="warn">{t('inventory.status.low')}</Badge>
                ) : (
                    <span className="ls-status"><span className="ls-dot" />{t('status.active')}</span>
                )}
            </div>

            <div className="ls-product-body">
                <Link href={`/products/${p.id}`} className="ls-product-name ls-trunc" id={`product-${p.id}-name`} title={p.name}>{p.name}</Link>
                <div className="ls-product-meta">
                    <span>{p.is_service ? t('sales.service') : t('sales.product')}</span>
                    {p.sku && <><span aria-hidden="true">·</span><span className="ls-mono ls-trunc">{p.sku}</span></>}
                </div>
            </div>

            <div className="ls-product-price"><Money amount={p.price} /></div>

            <dl className="ls-inv-facts">
                {p.purchase_price > 0 && (
                    <>
                        <div><dt>{t('inventory.cost')}</dt><dd className="ls-num"><Money amount={p.purchase_price} /></dd></div>
                        <div><dt>{t('inventory.profit_unit')}</dt><dd className={`ls-num ${p.profit < 0 ? 'is-negative' : 'is-profit'}`}><Money amount={p.profit} /></dd></div>
                    </>
                )}
                {status !== 'untracked' && (
                    <div><dt>{t('inventory.stock')}</dt>
                        <dd className={`ls-num ls-inv-stock is-${status}`}>{tc('inventory.units', p.stock_quantity, { count: p.stock_quantity })}</dd></div>
                )}
            </dl>

            <div className="ls-product-foot">
                <Button size="sm" href={`/products/${p.id}/edit`}>{t('common.edit')}</Button>
                <Button size="sm" variant="ghost" href={`/products/${p.id}`}>{t('inventory.view')}</Button>
                <Button variant="ghost" size="sm" onClick={() => router.post(`/products/${p.id}/toggle`, {}, { preserveScroll: true })}>
                    {p.is_active ? t('btn.deactivate') : t('btn.activate')}
                </Button>
                <span className="ls-product-delete">
                    <ConfirmButton href={`/products/${p.id}`} method="delete" message={t('sales.delete_confirm')} confirmLabel={t('common.delete')}
                        icon="trash" iconOnly ariaLabel={`${t('common.delete')}: ${p.name}`} />
                </span>
            </div>
        </article>
    );
}
