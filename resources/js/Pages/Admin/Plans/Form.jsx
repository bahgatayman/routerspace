/* Plan create / edit (port of admin/plans/_form + create/edit). */
import { Link, useForm } from '@inertiajs/react';
import { useRef } from 'react';
import { Icon, cx } from '../../../Components/ui';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';

const FIELDS = [
    ['name', 'text', { required: true, maxLength: 100 }],
    ['slug', 'text', { required: true, dir: 'ltr' }],
    ['price_per_month', 'number', { required: true, min: 0, step: '0.01' }],
    ['max_members', 'number', { required: true, min: 1 }],
    ['max_workspaces', 'number', { required: true, min: 0 }, true],
    ['max_rooms', 'number', { required: true, min: 0 }, true],
    ['max_products', 'number', { required: true, min: 0 }, true],
    ['sort_order', 'number', { min: 0 }],
];

const slugify = (s) => s.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

export default function AdminPlanForm({ plan, features }) {
    const editing = !!plan;
    usePageTitle(editing ? t('plan.edit_plan') : t('btn.add_plan'), t('nav.plans'));
    const form = useForm({
        name: plan?.name ?? '',
        slug: plan?.slug ?? '',
        price_per_month: plan?.price_per_month ?? '',
        max_members: plan?.max_members ?? '',
        max_workspaces: plan?.max_workspaces ?? 0,
        max_rooms: plan?.max_rooms ?? 0,
        max_products: plan?.max_products ?? 0,
        sort_order: plan?.sort_order ?? 0,
        features: plan?.features ?? [],
    });
    // Suggest a slug from the name until the slug is edited by hand (create only).
    const slugTouched = useRef(editing || !!form.data.slug);

    const change = (name, value) => {
        if (name === 'slug') slugTouched.current = true;
        if (name === 'name' && !slugTouched.current) form.setData((d) => ({ ...d, name: value, slug: slugify(value) }));
        else form.setData(name, value);
    };
    const toggleFeature = (key, on) => form.setData('features', on ? [...form.data.features, key] : form.data.features.filter((k) => k !== key));
    const submit = (e) => {
        e.preventDefault();
        if (editing) form.put(`/admin/plans/${plan.id}`);
        else form.post('/admin/plans');
    };
    const back = editing ? `/admin/plans/${plan.id}` : '/admin/plans';

    return (
        <div className="ls-adm ls-adm-narrow">
            <Link href={back} className="ls-biz-back">&larr; {editing ? plan.name : t('nav.plans')}</Link>
            <h1 className="ls-title">{editing ? `${t('plan.edit_plan')}: ${plan.name}` : t('btn.add_plan')}</h1>

            <form onSubmit={submit} className="ls-card ls-adm-form">
                <div className="ls-card-body ls-stack">
                    <div className="ls-adm-form-grid">
                        {FIELDS.map(([name, type, attrs, unlimitedHint]) => (
                            <div key={name} className="ls-field">
                                <label className="ls-label" htmlFor={`p-${name}`}>{t(`plan.${name}`)}{attrs.required && <span className="ls-req">*</span>}</label>
                                <input id={`p-${name}`} type={type} name={name} {...attrs}
                                    className={cx('ls-input', form.errors[name] && 'is-invalid')}
                                    value={form.data[name]} onChange={(e) => change(name, e.target.value)} />
                                {unlimitedHint && <span className="ls-hint">{t('plan.unlimited_hint')}</span>}
                                {form.errors[name] && <span className="ls-error">{form.errors[name]}</span>}
                            </div>
                        ))}
                    </div>

                    <fieldset className="ls-adm-fieldset">
                        <legend className="ls-label">{t('plan.features')}</legend>
                        <p className="ls-hint" style={{ margin: '0 0 var(--space-3)' }}>{t('plan.features_hint')}</p>
                        <div className="ls-adm-checks">
                            {features.map((f) => (
                                <label key={f.key} className="ls-adm-check ls-adm-check--box">
                                    <input type="checkbox" name="features[]" value={f.key} checked={form.data.features.includes(f.key)}
                                        onChange={(e) => toggleFeature(f.key, e.target.checked)} /> {f.name}
                                </label>
                            ))}
                        </div>
                        {(form.errors.features || Object.keys(form.errors).find((k) => k.startsWith('features.'))) && (
                            <span className="ls-error"><Icon name="alert" />{form.errors.features || form.errors[Object.keys(form.errors).find((k) => k.startsWith('features.'))]}</span>
                        )}
                    </fieldset>
                </div>
                <div className="ls-dialog-foot">
                    <Link href={back} className="ls-btn ls-btn--secondary">{t('common.cancel')}</Link>
                    <button type="submit" className={cx('ls-btn ls-btn--primary', form.processing && 'is-loading')} disabled={form.processing}>
                        {editing ? t('plan.update_plan') : t('btn.add_plan')}
                    </button>
                </div>
            </form>
        </div>
    );
}
