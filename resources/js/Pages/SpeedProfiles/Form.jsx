import { useForm } from '@inertiajs/react';
import ErrorSummary from '../../Components/ErrorSummary';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

const inputCls = 'w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent';

/** Create + edit speed profile (speed-profiles/create + speed-profiles/edit). */
export default function SpeedProfileForm({ profile, speedOptions }) {
    const editing = !!profile;
    const title = t(editing ? 'speed.edit_profile' : 'speed.create_profile');
    usePageTitle(title);

    const form = useForm({
        name: profile?.name ?? '',
        // A plain <select> without a matching value shows (and posts) the first option.
        speed_download: profile?.speed_download ?? speedOptions[0],
        speed_upload: profile?.speed_upload ?? speedOptions[0],
        is_default: profile?.is_default ? 1 : 0,
    });

    const submit = (e) => {
        e.preventDefault();
        form.transform((d) => {
            const out = { ...d };
            if (!out.is_default) delete out.is_default; // unchecked checkbox is not posted
            return out;
        });
        if (editing) form.put(`/speed-profiles/${profile.id}`);
        else form.post('/speed-profiles');
    };

    return (
        <>
            <ErrorSummary errors={form.errors} />

            <div className="max-w-lg mx-auto bg-white rounded-xl shadow-sm border border-gray-100 p-8">
                <h2 className="text-xl font-semibold text-gray-900 mb-6">{title}</h2>

                <form onSubmit={submit}>
                    <div className="mb-4">
                        <label htmlFor="name" className="block text-sm font-medium text-gray-700 mb-1">{t('speed.name')}</label>
                        <input type="text" name="name" id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required className={inputCls} />
                        {form.errors.name && <p className="text-red-600 text-xs mt-1">{form.errors.name}</p>}
                    </div>

                    <div className="grid grid-cols-2 gap-4 mb-4">
                        {[['speed_download', 'label.speed_download'], ['speed_upload', 'label.speed_upload']].map(([field, label]) => (
                            <div key={field}>
                                <label htmlFor={field} className="block text-sm font-medium text-gray-700 mb-1">{t(label)}</label>
                                <select name={field} id={field} required value={form.data[field]} onChange={(e) => form.setData(field, e.target.value)} className={inputCls}>
                                    {speedOptions.map((s) => <option key={s} value={s}>{s}</option>)}
                                </select>
                            </div>
                        ))}
                    </div>

                    <div className="mb-6">
                        <label className="inline-flex items-center">
                            <input type="checkbox" name="is_default" value="1" checked={!!form.data.is_default} onChange={(e) => form.setData('is_default', e.target.checked ? 1 : 0)}
                                className="rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                            <span className="ml-2 text-sm text-gray-700">{t('speed.set_as_default')}</span>
                        </label>
                    </div>

                    <button type="submit" disabled={form.processing} className="w-full bg-blue-600 text-white py-2.5 rounded-lg hover:bg-blue-700 transition font-medium shadow-sm disabled:opacity-60">
                        {t(editing ? 'speed.update_profile' : 'speed.create_profile')}
                    </button>
                </form>
            </div>
        </>
    );
}
