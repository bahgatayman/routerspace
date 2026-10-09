/*
 * Member profile → Hour Packages (port of users/_packages + users/_package-summary):
 * every package as its own card, the usage history, and the Add / Cancel
 * package modals. Balances, labels and statuses come from the controller
 * (MemberPackage / PackageUsage helpers); nothing is computed here.
 */
import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Badge, Button, Icon, Input, Modal, Money } from '../ui';
import { locale, t } from '../../lib/i18n';
import { num } from '../../lib/format';

const ADD_FIELDS = ['name', 'hours', 'price_paid', 'starts_on', 'expires_on', 'notes', 'package_template_id'];

export function PackageSummary({ summary }) {
    if (!summary) return null;
    return (
        <a href="#packages" className={`ls-pkg-summary ${summary.soon ? 'is-soon' : ''}`} id="package-summary">
            <span className="ls-pkg-summary-icon" aria-hidden="true"><Icon name="clock" /></span>
            <span className="ls-pkg-summary-main">
                <span className="ls-pkg-summary-top">
                    <span className="ls-pkg-summary-label">{t('packages.summary_title')} · {summary.name}</span>
                    {summary.soon && <Badge tone="warn">{t('packages.expiring_soon')}</Badge>}
                    {summary.more > 0 && <span className="ls-pkg-summary-more">{t('packages.more_active', { count: summary.more })}</span>}
                </span>
                <span className="ls-pkg-summary-value">{summary.remaining_text}</span>
                <span className={`ls-meter ${summary.percent >= 80 ? 'is-warn' : ''}`}><i style={{ width: `${summary.percent}%` }} /></span>
                <span className="ls-pkg-summary-meta">
                    {t('packages.used')} {summary.used_label} · {summary.expires_text}
                </span>
            </span>
        </a>
    );
}

function AddPackageModal({ open, onClose, userName, storeUrl, templates, defaults }) {
    const currency = locale() === 'ar' ? 'ج.م' : 'EGP';
    const form = useForm({
        pkg_mode: templates.length ? 'template' : 'custom',
        package_template_id: '',
        name: '',
        hours: '',
        price_paid: '',
        starts_on: defaults.starts_on,
        expires_on: defaults.expires_on,
        notes: '',
    });
    const [days, setDays] = useState(null);

    // valid N days, inclusive
    const expiryFor = (startsOn, d) => {
        if (!d || !startsOn) return null;
        const date = new Date(startsOn + 'T00:00:00Z');
        date.setUTCDate(date.getUTCDate() + d - 1);
        return date.toISOString().slice(0, 10);
    };

    const setMode = (mode) => {
        if (mode === 'custom') {
            form.setData((d) => ({ ...d, pkg_mode: mode, package_template_id: '' }));
            setDays(null);
        } else {
            form.setData('pkg_mode', mode);
        }
    };

    const pickTemplate = (id) => {
        const tpl = templates.find((x) => String(x.id) === String(id));
        if (!tpl) {
            form.setData('package_template_id', id);
            setDays(null);
            return;
        }
        setDays(tpl.days);
        form.setData((d) => ({
            ...d,
            package_template_id: id,
            name: tpl.name,
            hours: tpl.hours,
            price_paid: tpl.price,
            expires_on: expiryFor(d.starts_on, tpl.days) || d.expires_on,
        }));
    };

    const setStartsOn = (value) => {
        form.setData((d) => ({ ...d, starts_on: value, expires_on: expiryFor(value, days) || d.expires_on }));
    };

    const submit = (e) => {
        e.preventDefault();
        form.post(storeUrl, {
            preserveScroll: true,
            onSuccess: () => { form.reset(); setDays(null); onClose(); },
        });
    };

    const custom = form.data.pkg_mode === 'custom';
    const field = (name) => ({ name, value: form.data[name], onChange: (e) => form.setData(name, e.target.value), error: form.errors[name] });

    return (
        <Modal open={open} onClose={onClose} id="add-package" title={t('packages.add_title')} subtitle={userName}
            footer={<>
                <Button variant="ghost" onClick={onClose}>{t('packages.cancel')}</Button>
                <Button variant="primary" type="submit" form="add-package-form" processing={form.processing}>{t('packages.assign_cta')}</Button>
            </>}>
            <form onSubmit={submit} id="add-package-form" className="ls-pkg-form">
                <div className="ls-inv-seg" role="radiogroup">
                    <label><input type="radio" name="pkg_mode" value="template" checked={!custom} onChange={() => setMode('template')} /><span>{t('packages.from_template')}</span></label>
                    <label><input type="radio" name="pkg_mode" value="custom" checked={custom} onChange={() => setMode('custom')} /><span>{t('packages.custom')}</span></label>
                </div>

                <div hidden={custom}>
                    {templates.length === 0 ? (
                        <p className="ls-hint">{t('packages.no_templates')}</p>
                    ) : (
                        <div className="ls-field">
                            <label className="ls-label" htmlFor="f-package_template_id">{t('packages.choose_template')}</label>
                            <select id="f-package_template_id" name="package_template_id" className="ls-select" value={form.data.package_template_id} onChange={(e) => pickTemplate(e.target.value)}>
                                <option value="">—</option>
                                {templates.map((tpl) => (
                                    <option key={tpl.id} value={tpl.id}>{tpl.name} · {tpl.hours_label} · {currency} {num(tpl.price, 2)}</option>
                                ))}
                            </select>
                            {form.errors.package_template_id && <span className="ls-error"><Icon name="alert" />{form.errors.package_template_id}</span>}
                        </div>
                    )}
                </div>

                <Input {...field('name')} label={t('packages.name')} placeholder={t('packages.name_ph')} maxLength={80} required />
                <div className="ls-pkg-form-row">
                    <Input {...field('hours')} type="number" label={t('packages.hours')} step="0.25" min="0.25" inputMode="decimal" required />
                    <div className="ls-field">
                        <label className="ls-label" htmlFor="f-price_paid">{t('packages.price_paid')} <span className="ls-req" aria-hidden="true">*</span></label>
                        <div className="ls-input-affix"><span aria-hidden="true">{currency}</span>
                            <input type="number" step="0.01" min="0" id="f-price_paid" name="price_paid" className={`ls-input ${form.errors.price_paid ? 'is-invalid' : ''}`}
                                inputMode="decimal" required value={form.data.price_paid} onChange={(e) => form.setData('price_paid', e.target.value)} />
                        </div>
                        {form.errors.price_paid && <span className="ls-error"><Icon name="alert" />{form.errors.price_paid}</span>}
                    </div>
                </div>
                <div className="ls-pkg-form-row">
                    <Input name="starts_on" type="date" label={t('packages.starts_on')} value={form.data.starts_on} onChange={(e) => setStartsOn(e.target.value)} error={form.errors.starts_on} required />
                    <Input {...field('expires_on')} type="date" label={t('packages.expires_on')} required />
                </div>
                <Input {...field('notes')} label={t('packages.notes')} placeholder={t('packages.notes_ph')} maxLength={500} optional />
            </form>
        </Modal>
    );
}

function CancelPackageModal({ target, onClose }) {
    const form = useForm({ reason: '' });
    useEffect(() => { if (target) form.reset(); }, [target]);

    const submit = (e) => {
        e.preventDefault();
        if (!target) return;
        form.post(target.url, { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Modal open={!!target} onClose={onClose} id="cancel-package" title={t('packages.cancel_title')} size="narrow"
            footer={<>
                <Button variant="ghost" onClick={onClose}>{t('packages.keep')}</Button>
                <Button variant="danger" type="submit" form="cancel-package-form" processing={form.processing}>{t('packages.cancel_confirm')}</Button>
            </>}>
            <form onSubmit={submit} id="cancel-package-form" className="ls-pkg-form">
                <p className="ls-pkg-cancel-body"><b>{target?.name}</b><br />{t('packages.cancel_body')}</p>
                <Input name="reason" label={t('packages.cancel_reason')} maxLength={255} optional value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)} error={form.errors.reason} />
            </form>
        </Modal>
    );
}

export default function Packages({ user, packages, usages, templates, canAssign, storeUrl, defaults, errors = {} }) {
    const [addOpen, setAddOpen] = useState(false);
    const [cancelTarget, setCancelTarget] = useState(null);
    const manyPackages = packages.length > 1;

    // Re-open the Add modal when the server bounced the assignment back with field errors.
    useEffect(() => {
        if (canAssign && ADD_FIELDS.some((f) => errors[f])) setAddOpen(true);
    }, [errors]);

    return (
        <>
            <section className="ls-card ls-pkg-section" id="packages" aria-labelledby="packages-title">
                <div className="ls-card-head">
                    <h2 className="ls-card-title" id="packages-title">{t('packages.section')}</h2>
                    {canAssign && (
                        <div className="ls-actions">
                            <Button size="sm" icon="plus" onClick={() => setAddOpen(true)}>{t('packages.add')}</Button>
                        </div>
                    )}
                </div>
                <div className="ls-card-body">
                    {packages.length === 0 ? (
                        <p className="ls-pkg-none">{t('packages.none')}</p>
                    ) : (
                        <div className="ls-pkg-list">
                            {packages.map((pkg) => (
                                <article key={pkg.id} className={`ls-pkg is-${pkg.status}`} id={`member-package-${pkg.id}`}>
                                    <div className="ls-pkg-head">
                                        <div className="ls-pkg-title">
                                            <h3>{pkg.name}</h3>
                                            <span className="ls-pkg-dates">
                                                {pkg.starts_text && <>{pkg.starts_text} · </>}
                                                {pkg.expires_text}
                                            </span>
                                        </div>
                                        {pkg.soon
                                            ? <Badge tone="warn">{t('packages.expiring_soon')}</Badge>
                                            : <Badge tone={pkg.status_tone}>{t(`packages.status.${pkg.status}`)}</Badge>}
                                    </div>

                                    {/* One bar: how much of the total has been consumed. */}
                                    <div className={`ls-pkg-progress ${pkg.remaining_minutes <= 0 ? 'is-full' : pkg.percent >= 80 ? 'is-high' : ''}`} role="progressbar"
                                        aria-valuemin="0" aria-valuemax="100" aria-valuenow={pkg.percent} aria-label={pkg.used_of}>
                                        <i style={{ width: `${pkg.percent}%` }} />
                                    </div>
                                    <div className="ls-pkg-progress-legend">
                                        <span>{pkg.used_of} <span className="ls-pkg-pct">· {pkg.percent}%</span></span>
                                        <b>{pkg.left_text}</b>
                                    </div>

                                    <div className="ls-pkg-meta">
                                        <span><Money amount={pkg.price_paid} />{pkg.cancelled_reason && <> · {pkg.cancelled_reason}</>}</span>
                                        {canAssign && pkg.cancellable && (
                                            <button type="button" className="ls-link ls-pkg-cancel" onClick={() => setCancelTarget({ url: pkg.cancel_url, name: pkg.name })}>
                                                {t('packages.cancel_pkg')}
                                            </button>
                                        )}
                                    </div>
                                </article>
                            ))}
                        </div>
                    )}
                </div>

                {/* Usage history as a short list: what happened · when · how many hours. */}
                {usages.length > 0 && (
                    <details className="ls-pkg-usage" open={usages.length <= 5 || undefined}>
                        <summary className="ls-pkg-usage-title">{t('packages.usage_title')} <span className="ls-pkg-usage-count">{usages.length}</span><Icon name="chevron-right" className="ls-pkg-usage-chev" /></summary>
                        <ul className="ls-pkg-log">
                            {usages.map((u) => (
                                <li key={u.id}>
                                    <span className="ls-pkg-log-main">
                                        <b>{u.label}{u.booking_id && <> · <a href={`/bookings/${u.booking_id}`} className="ls-link">#{u.booking_id}</a></>}</b>
                                        <small>{u.at}{manyPackages && <> · {u.package_name}</>}</small>
                                    </span>
                                    <span className={`ls-pkg-change ${u.is_out ? 'is-out' : 'is-in'}`}>{u.change}</span>
                                </li>
                            ))}
                        </ul>
                    </details>
                )}
            </section>

            {canAssign && (
                <>
                    <AddPackageModal open={addOpen} onClose={() => setAddOpen(false)} userName={user.name} storeUrl={storeUrl} templates={templates} defaults={defaults} />
                    <CancelPackageModal target={cancelTarget} onClose={() => setCancelTarget(null)} />
                </>
            )}
        </>
    );
}
