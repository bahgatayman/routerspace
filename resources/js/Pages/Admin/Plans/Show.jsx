import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import rowLink from '../../../Components/Admin/rowLink';
import { Stat } from '../../../Components/analytics';
import { Badge, Banner, Button, EmptyState, Field, Icon, Modal, Pagination } from '../../../Components/ui';
import { money, num } from '../../../lib/format';
import { t, tc } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';
import { LIMIT_KEYS, PlanToggle, limit } from './Index';

const tp = (key, params) => t(`admin_platform.${key}`, params);

export default function AdminPlanShow({ plan, active, mrr, requests, features, owners, payments, targets, used, inUse }) {
    usePageTitle(plan.name, t('nav.plans'));
    const [dialog, setDialog] = useState(null); // 'delete' | 'migrate' | null
    const [busy, setBusy] = useState(false);
    const canMigrate = plan.owners_count > 0 && targets.length > 0;
    const close = () => setDialog(null);
    const migrate = useForm({ target_plan_id: '', deactivate: true, reason: '' });

    const visit = (url, method) => router.visit(url, {
        method, preserveScroll: true,
        onStart: () => setBusy(true),
        onFinish: () => { setBusy(false); close(); },
    });
    const submitMigrate = (e) => {
        e.preventDefault();
        migrate.post(`/admin/plans/${plan.id}/migrate`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => { close(); migrate.reset(); },
        });
    };

    return (
        <div className="ls-adm">
            <Link href="/admin/plans" className="ls-biz-back">&larr; {t('nav.plans')}</Link>
            <header className="ls-biz-head">
                <div className="ls-biz-id">
                    <h1 className="ls-biz-name">{plan.name}{' '}
                        {plan.is_active ? <Badge tone="ok">{t('status.active')}</Badge> : <Badge tone="neutral">{t('status.inactive')}</Badge>}
                    </h1>
                    <p className="ls-biz-meta"><span dir="ltr">{plan.slug}</span> · {money(plan.price_per_month)} / {tp('month')}</p>
                </div>
                <div className="ls-biz-actions">
                    <Button size="sm" href={`/admin/plans/${plan.id}/edit`}>{t('common.edit')}</Button>
                    <PlanToggle plan={plan} withReason />
                    {canMigrate && <Button size="sm" variant="secondary" onClick={() => setDialog('migrate')}>{tp('move_businesses')}</Button>}
                    <Button size="sm" variant="danger-quiet" onClick={() => setDialog('delete')}>{t('common.delete')}</Button>
                </div>
            </header>

            <div className="ls-akpis">
                <Stat label={tp('chart.active_workspaces')} value={num(active)} sub={tp('of_total', { total: plan.owners_count })} />
                <Stat label={tp('kpi.mrr')} value={money(mrr)} help={tp('help.mrr')} />
                <Stat label={tp('lifetime_revenue')} value={money(plan.revenue)} tone="brand" />
                <Stat label={tp('chart.renewals')} value={num(plan.subscriptions_count)} />
                <Stat label={tp('renewal_requests')} value={num(requests)} />
            </div>

            <div className="ls-adm-grid">
                <section className="ls-card">
                    <div className="ls-card-head"><h2 className="ls-card-title">{tp('limits_features')}</h2></div>
                    <div className="ls-card-body ls-stack">
                        <dl className="ls-kv">
                            {LIMIT_KEYS.map((k) => <FragmentKV key={k} label={t(`plan.${k}`)} value={limit(plan.limits[k])} />)}
                        </dl>
                        <ul className="ls-adm-list">
                            {features.map((f) => (
                                <li key={f.key}><span>{f.name}</span>{f.on ? <Badge tone="ok" dot={false}>{tp('included')}</Badge> : <span className="ls-faint">—</span>}</li>
                            ))}
                        </ul>
                    </div>
                </section>

                <section className="ls-card">
                    <div className="ls-card-head"><h2 className="ls-card-title">{tp('recent_payments')}</h2></div>
                    <div className="ls-card-body">
                        {payments.length === 0 ? <p className="ls-faint">{tp('nothing_yet')}</p> : payments.map((s) => (
                            <Link key={s.id} href={`/admin/owners/${s.owner_id}/subscription`} className="ls-adm-row">
                                <span className="ls-adm-row-main"><b className="ls-trunc">{s.business ?? '—'}</b><small>{tc('admin_platform.months', s.months, { count: s.months })} · {s.admin}</small></span>
                                <span className="ls-adm-row-end"><span className="ls-num">{money(s.amount)}</span><small>{s.date}</small></span>
                            </Link>
                        ))}
                    </div>
                </section>
            </div>

            <section className="ls-card">
                <div className="ls-card-head"><h2 className="ls-card-title">{tp('businesses_on_plan')} <span className="ls-count">{plan.owners_count}</span></h2></div>
                <div className="ls-card-body ls-card-body--flush">
                    {owners.data.length === 0 ? (
                        <EmptyState title={tp('no_businesses_on_plan')} />
                    ) : (
                        <>
                            <div className="ls-table-wrap">
                                <table className="ls-table ls-adm-table">
                                    <thead><tr>
                                        <th scope="col">{tp('col.workspace')}</th>
                                        <th scope="col">{t('common.status')}</th>
                                        <th scope="col" className="is-num">{tp('nav.rooms')}</th>
                                        <th scope="col" className="is-num">{tp('col.products')}</th>
                                        <th scope="col">{tp('col.expires')}</th>
                                    </tr></thead>
                                    <tbody>
                                        {owners.data.map((o) => (
                                            <tr key={o.id} {...rowLink(`/admin/owners/${o.id}`)}>
                                                <td><Link href={`/admin/owners/${o.id}`} className="ls-adm-ident-name">{o.name}</Link></td>
                                                <td><Badge tone={o.status_tone}>{t(`admin_biz.status.${o.status}`)}</Badge></td>
                                                <td className="is-num">{o.rooms_count}</td>
                                                <td className="is-num">{o.products_count}</td>
                                                <td>{o.expires ?? '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <div className="ls-adm-pager"><span /><Pagination paginator={owners} /></div>
                        </>
                    )}
                </div>
            </section>

            <Modal open={dialog === 'delete'} onClose={close} size="narrow" title={tp('delete_plan_title', { plan: plan.name })}
                footer={<>
                    <Button variant="secondary" onClick={close}>{t('common.cancel')}</Button>
                    {inUse ? (
                        <>
                            {plan.is_active && <Button variant="secondary" processing={busy} onClick={() => visit(`/admin/plans/${plan.id}/toggle`, 'post')}>{tp('deactivate_instead')}</Button>}
                            {canMigrate && <Button variant="primary" onClick={() => setDialog('migrate')}>{tp('move_businesses')}</Button>}
                        </>
                    ) : (
                        <Button variant="danger" processing={busy} onClick={() => visit(`/admin/plans/${plan.id}`, 'delete')}>{t('common.delete')}</Button>
                    )}
                </>}>
                {inUse ? (
                    <>
                        <Banner tone="warn">{tp('plan_in_use', { owners: used.owners, subscriptions: used.subscriptions, requests: used.requests })}</Banner>
                        <p className="ls-muted" style={{ margin: 0 }}>{tp('plan_in_use_options')}</p>
                    </>
                ) : (
                    <p className="ls-muted" style={{ margin: 0 }}>{tp('plan_delete_safe')}</p>
                )}
            </Modal>

            {targets.length > 0 && (
                <Modal open={dialog === 'migrate'} onClose={close} title={tp('move_businesses')} subtitle={tp('move_sub', { count: plan.owners_count, plan: plan.name })}
                    footer={<>
                        <Button variant="secondary" onClick={close}>{t('common.cancel')}</Button>
                        <Button type="submit" form="migrate-form" variant="primary" processing={migrate.processing}>{tp('move_confirm')}</Button>
                    </>}>
                    <form id="migrate-form" className="ls-stack" onSubmit={submitMigrate}>
                        <Field label={tp('target_plan')} htmlFor="m-target" error={migrate.errors.target_plan_id}>
                            <select id="m-target" className={`ls-select${migrate.errors.target_plan_id ? ' is-invalid' : ''}`} required
                                value={migrate.data.target_plan_id} onChange={(e) => migrate.setData('target_plan_id', e.target.value)}>
                                <option value="">—</option>
                                {targets.map((tg) => (
                                    <option key={tg.id} value={tg.id}>{tg.name} · {money(tg.price_per_month)}{!tg.is_active && ` (${t('status.inactive')})`}</option>
                                ))}
                            </select>
                        </Field>
                        <label className="ls-adm-check">
                            <input type="checkbox" checked={migrate.data.deactivate} onChange={(e) => migrate.setData('deactivate', e.target.checked)} />
                            {' '}{tp('deactivate_after_move', { plan: plan.name })}
                        </label>
                        {migrate.errors.deactivate && <span className="ls-error"><Icon name="alert" />{migrate.errors.deactivate}</span>}
                        <Field label={<>{tp('reason')} <span className="ls-opt">({tp('optional')})</span></>} htmlFor="m-reason" error={migrate.errors.reason}>
                            <textarea id="m-reason" className="ls-textarea" rows={2} maxLength={500}
                                value={migrate.data.reason} onChange={(e) => migrate.setData('reason', e.target.value)} />
                        </Field>
                        <p className="ls-hint" style={{ margin: 0 }}>{tp('move_note')}</p>
                    </form>
                </Modal>
            )}
        </div>
    );
}

function FragmentKV({ label, value }) {
    return <><dt>{label}</dt><dd>{value}</dd></>;
}
