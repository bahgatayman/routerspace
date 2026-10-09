import { useForm, usePage } from '@inertiajs/react';
import { Button, Icon, Input, PageHeader } from '../../Components/ui';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

/** Hour package template form (create + edit). Hours accept decimals; stored as minutes server-side. */
export default function PackageForm({ template, roomGroups }) {
    const editing = !!template.id;
    const title = t(editing ? 'packages.edit_title' : 'packages.create_title');
    usePageTitle(title);
    const currency = usePage().props.locale === 'ar' ? 'ج.م' : 'EGP';

    const form = useForm({
        name: template.name,
        hours: template.hours,
        price: template.price,
        validity_days: template.validity_days,
        room_scope: template.room_scope,
        room_ids: template.room_ids,
        is_active: template.is_active ? 1 : 0,
    });
    const set = (k) => (e) => form.setData(k, e.target.value);

    const toggleRoom = (id, on) => form.setData('room_ids', on ? [...form.data.room_ids, id] : form.data.room_ids.filter((r) => r !== id));

    const submit = (e) => {
        e.preventDefault();
        if (editing) form.put(`/packages/${template.id}`);
        else form.post('/packages');
    };

    return (
        <>
            <PageHeader title={title} eyebrow={t('packages.title')} subtitle={editing ? t('packages.edit_note') : null} />

            <form onSubmit={submit} className="ls-card ls-pkg-card-form">
                <div className="ls-card-body">
                    <div className="ls-pkg-form">
                        <Input name="name" label={t('packages.name')} value={form.data.name} onChange={set('name')} placeholder={t('packages.name_ph')} maxLength={80} required error={form.errors.name} />

                        <div className="ls-pkg-form-row">
                            <Input name="hours" type="number" label={t('packages.hours')} value={form.data.hours} onChange={set('hours')} step="0.25" min="0.25" inputMode="decimal"
                                hint={t('packages.hours_hint')} required error={form.errors.hours} />
                            <div className="ls-field">
                                <label className="ls-label" htmlFor="f-price">{t('packages.price')} <span className="ls-req" aria-hidden="true">*</span></label>
                                <div className="ls-input-affix"><span aria-hidden="true">{currency}</span>
                                    <input type="number" step="0.01" min="0" id="f-price" name="price" className={`ls-input ${form.errors.price ? 'is-invalid' : ''}`} inputMode="decimal" required
                                        value={form.data.price} onChange={set('price')} />
                                </div>
                                {form.errors.price && <span className="ls-error"><Icon name="alert" />{form.errors.price}</span>}
                            </div>
                            <Input name="validity_days" type="number" label={t('packages.validity_days')} value={form.data.validity_days} onChange={set('validity_days')} min="1" max="3650" required error={form.errors.validity_days} />
                        </div>

                        <fieldset className="ls-inv-group">
                            <legend className="ls-inv-legend">{t('packages.rooms')}</legend>
                            <div className="ls-inv-seg" role="radiogroup">
                                {[['all', 'packages.all_rooms'], ['specific', 'packages.specific_rooms']].map(([v, label]) => (
                                    <label key={v}><input type="radio" name="room_scope" value={v} checked={form.data.room_scope === v} onChange={() => form.setData('room_scope', v)} /><span>{t(label)}</span></label>
                                ))}
                            </div>
                            <div className="ls-pkg-rooms" hidden={form.data.room_scope !== 'specific'}>
                                {roomGroups.map((g) => (
                                    <div key={g.label} className="ls-pkg-room-group">
                                        <div className="ls-pkg-room-head">{g.label}</div>
                                        {g.rooms.map((room) => (
                                            <label key={room.id} className="ls-pkg-room">
                                                <input type="checkbox" name="room_ids[]" value={room.id} checked={form.data.room_ids.includes(room.id)} onChange={(e) => toggleRoom(room.id, e.target.checked)} />
                                                <span>{room.name}</span>
                                            </label>
                                        ))}
                                    </div>
                                ))}
                            </div>
                            {form.errors.room_ids && <span className="ls-error"><Icon name="alert" />{form.errors.room_ids}</span>}
                        </fieldset>

                        <label className="ls-inv-switch">
                            <input type="checkbox" name="is_active" value="1" checked={!!form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked ? 1 : 0)} />
                            <span><b>{t('packages.active')}</b><small>{t('packages.active_hint')}</small></span>
                        </label>
                    </div>
                </div>
                <div className="ls-pkg-form-foot">
                    <Button href="/packages" variant="ghost">{t('packages.cancel')}</Button>
                    <Button type="submit" variant="primary" processing={form.processing}>{t('packages.save')}</Button>
                </div>
            </form>
        </>
    );
}
