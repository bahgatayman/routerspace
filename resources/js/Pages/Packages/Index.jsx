import { router } from '@inertiajs/react';
import { Badge, Button, ConfirmButton, EmptyState, Icon, Money, PageHeader } from '../../Components/ui';
import { t, tc } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

/** Hour Package templates as cards; assigning happens from the member's profile. */
export default function PackagesIndex({ templates, canManage }) {
    usePageTitle(t('packages.title'));
    const newBtn = canManage && <Button variant="primary" icon="plus" href="/packages/create">{t('packages.new_template')}</Button>;

    return (
        <>
            <PageHeader title={t('packages.title')} subtitle={t('packages.subtitle')} count={templates.length || null} actions={newBtn || null} />

            {templates.length === 0 ? (
                <div className="ls-card">
                    <EmptyState illustration="quiet" title={t('packages.empty_title')} text={t('packages.empty_body')}>{newBtn || null}</EmptyState>
                </div>
            ) : (
                <div className="ls-product-grid">
                    {templates.map((p) => (
                        <article key={p.id} className={`ls-product ls-pkg-tpl ${p.is_active ? '' : 'is-inactive'}`} id={`package-template-${p.id}`} aria-labelledby={`pkg-${p.id}-name`}>
                            <div className="ls-product-top">
                                <span className="ls-product-icon is-product" aria-hidden="true"><Icon name="clock" /></span>
                                {p.is_active
                                    ? <span className="ls-status"><span className="ls-dot" />{t('packages.active')}</span>
                                    : <Badge tone="neutral" dot={false}>{t('packages.inactive')}</Badge>}
                            </div>

                            <div className="ls-product-body">
                                <h3 className="ls-product-name ls-trunc" id={`pkg-${p.id}-name`} title={p.name}>{p.name}</h3>
                                <div className="ls-product-meta">
                                    <span>{p.hours_label}</span>
                                    <span aria-hidden="true">·</span>
                                    <span>{t('packages.validity_label', { days: p.validity_days })}</span>
                                </div>
                            </div>

                            <div className="ls-product-price"><Money amount={p.price} /></div>

                            <dl className="ls-inv-facts">
                                <div><dt>{t('packages.rate')}</dt><dd className="ls-num"><Money amount={p.per_hour} /></dd></div>
                                <div><dt>{t('packages.rooms')}</dt><dd className="ls-trunc" title={p.rooms_label}>{p.rooms_label}</dd></div>
                                <div><dt>{t('packages.sold')}</dt><dd>{tc('packages.sold_count', p.sold_count, { count: p.sold_count })}</dd></div>
                            </dl>

                            {canManage && (
                                <div className="ls-product-foot">
                                    <Button size="sm" href={`/packages/${p.id}/edit`}>{t('packages.edit')}</Button>
                                    <Button variant="ghost" size="sm" onClick={() => router.post(`/packages/${p.id}/toggle`, {}, { preserveScroll: true })}>
                                        {p.is_active ? t('packages.deactivate') : t('packages.activate')}
                                    </Button>
                                    {p.sold_count === 0 && (
                                        <span className="ls-product-delete">
                                            <ConfirmButton href={`/packages/${p.id}`} method="delete" message={t('packages.confirm_delete')} confirmLabel={t('packages.delete')}
                                                icon="trash" iconOnly ariaLabel={`${t('packages.delete')}: ${p.name}`} />
                                        </span>
                                    )}
                                </div>
                            )}
                        </article>
                    ))}
                </div>
            )}
        </>
    );
}
