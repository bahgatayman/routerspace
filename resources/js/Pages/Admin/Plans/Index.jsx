import { Link } from '@inertiajs/react';
import { Badge, Button, ConfirmButton, EmptyState, cx } from '../../../Components/ui';
import { money, num } from '../../../lib/format';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';

const tp = (key, params) => t(`admin_platform.${key}`, params);
export const limit = (v) => (Number(v) > 0 ? num(v) : tp('unlimited'));
export const LIMIT_KEYS = ['max_members', 'max_workspaces', 'max_rooms', 'max_products'];

/** Enable/disable a plan, confirmed first (same wording as the Blade data-confirm form). */
export function PlanToggle({ plan, withReason = false, children }) {
    return (
        <ConfirmButton href={`/admin/plans/${plan.id}/toggle`} method="post" variant="ghost" withReason={withReason}
            message={plan.is_active ? tp('confirm_disable_plan', { plan: plan.name }) : tp('confirm_enable_plan', { plan: plan.name })}
            tone={plan.is_active ? 'danger' : 'primary'}
            confirmLabel={plan.is_active ? t('btn.disable') : t('btn.enable')}>
            {children || (plan.is_active ? t('btn.disable') : t('btn.enable'))}
        </ConfirmButton>
    );
}

export default function AdminPlansIndex({ plans }) {
    usePageTitle(t('nav.plans'));

    return (
        <div className="ls-adm">
            <header className="ls-page-head ls-adm-head">
                <div>
                    <h1 className="ls-title">{t('nav.plans')} <span className="ls-count">{plans.length}</span></h1>
                    <p className="ls-subtitle">{tp('plans_sub')}</p>
                </div>
                <div className="ls-actions"><Button variant="primary" icon="plus" href="/admin/plans/create">{t('btn.add_plan')}</Button></div>
            </header>

            {plans.length === 0 ? (
                <section className="ls-card">
                    <EmptyState title={tp('no_plans')}><Button variant="primary" href="/admin/plans/create">{t('btn.add_plan')}</Button></EmptyState>
                </section>
            ) : (
                <div className="ls-plan-grid">
                    {plans.map((plan) => (
                        <article key={plan.id} className={cx('ls-card ls-plan-card', !plan.is_active && 'is-off')} data-plan={plan.id}>
                            <header className="ls-plan-card-head">
                                <div>
                                    <h2 className="ls-plan-name"><Link href={`/admin/plans/${plan.id}`}>{plan.name}</Link></h2>
                                    <span className="ls-faint ls-num" dir="ltr">{plan.slug}</span>
                                </div>
                                {plan.is_active ? <Badge tone="ok">{t('status.active')}</Badge> : <Badge tone="neutral">{t('status.inactive')}</Badge>}
                            </header>
                            <p className="ls-plan-price"><b>{money(plan.price_per_month)}</b><span>/ {tp('month')}</span></p>
                            <dl className="ls-plan-usage">
                                <div><dt>{tp('chart.active_workspaces')}</dt><dd>{num(plan.active_owners_count)}<small> / {num(plan.owners_count)}</small></dd></div>
                                <div><dt>{tp('kpi.mrr')}</dt><dd>{money(plan.mrr)}</dd></div>
                                <div><dt>{tp('lifetime_revenue')}</dt><dd>{money(plan.revenue)}</dd></div>
                            </dl>
                            <ul className="ls-plan-limits">
                                {LIMIT_KEYS.map((k) => <li key={k}><span>{t(`plan.${k}`)}</span><b>{limit(plan.limits[k])}</b></li>)}
                            </ul>
                            {plan.features.length > 0 && (
                                <p className="ls-plan-features">{plan.features.map((f) => <span key={f}>{f}</span>)}</p>
                            )}
                            <footer className="ls-plan-actions">
                                <Button size="sm" variant="tonal" href={`/admin/plans/${plan.id}`}>{tp('details')}</Button>
                                <Button size="sm" variant="ghost" href={`/admin/plans/${plan.id}/edit`}>{t('common.edit')}</Button>
                                <PlanToggle plan={plan} />
                                {!plan.used && (
                                    <ConfirmButton href={`/admin/plans/${plan.id}`} method="delete" withReason
                                        message={tp('confirm_delete_plan', { plan: plan.name })} confirmLabel={t('common.delete')}>
                                        {t('common.delete')}
                                    </ConfirmButton>
                                )}
                            </footer>
                        </article>
                    ))}
                </div>
            )}
        </div>
    );
}
