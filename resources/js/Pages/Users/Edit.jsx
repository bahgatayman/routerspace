import { useForm } from '@inertiajs/react';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

const INPUT = 'w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent';

function FieldError({ message }) {
    return message ? <p className="text-red-600 text-xs mt-1">{message}</p> : null;
}

export default function UsersEdit({ user }) {
    usePageTitle(t('user.edit_user'));
    const form = useForm({
        name: user.name || '',
        email: user.email || '',
        notes: user.notes || '',
        status: user.status || 'active',
    });
    const errors = Object.values(form.errors);

    const submit = (e) => {
        e.preventDefault();
        form.put(`/users/${user.id}`);
    };

    return (
        <>
            {errors.length > 0 && (
                <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
                    {errors.map((err, i) => <p key={i} className="text-sm">{err}</p>)}
                </div>
            )}

            <div className="max-w-lg mx-auto bg-white rounded-xl shadow-sm border border-gray-100 p-8">
                <h2 className="text-xl font-semibold text-gray-900 mb-6">{t('user.edit_user')}</h2>

                <form onSubmit={submit}>
                    <div className="mb-4">
                        <label htmlFor="name" className="block text-sm font-medium text-gray-700 mb-1">{t('user.name')}</label>
                        <input type="text" name="name" id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required className={INPUT} />
                        <FieldError message={form.errors.name} />
                    </div>

                    <div className="mb-4">
                        <label htmlFor="phone" className="block text-sm font-medium text-gray-700 mb-1">{t('user.phone')}</label>
                        <input type="text" id="phone" value={user.phone} disabled className="w-full border border-gray-200 bg-gray-50 rounded-lg px-3 py-2.5 text-gray-500" />
                        <p className="text-xs text-gray-400 mt-1">{t('user.phone_hint')}</p>
                    </div>

                    <div className="mb-4">
                        <label htmlFor="email" className="block text-sm font-medium text-gray-700 mb-1">{t('user.email')} (optional)</label>
                        <input type="email" name="email" id="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} className={INPUT} />
                        <FieldError message={form.errors.email} />
                    </div>

                    <div className="mb-4">
                        <label htmlFor="notes" className="block text-sm font-medium text-gray-700 mb-1">{t('user.notes')} (optional)</label>
                        <textarea name="notes" id="notes" rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} className={INPUT} />
                        <FieldError message={form.errors.notes} />
                    </div>

                    <div className="mb-6">
                        <label htmlFor="status" className="block text-sm font-medium text-gray-700 mb-1">{t('common.status')}</label>
                        <select name="status" id="status" required value={form.data.status} onChange={(e) => form.setData('status', e.target.value)} className={INPUT}>
                            <option value="active">{t('status.active')}</option>
                            <option value="inactive">{t('status.inactive')}</option>
                        </select>
                        <FieldError message={form.errors.status} />
                    </div>

                    <p className="text-xs text-gray-400 mb-4">{t('user.speed_change_hint')}</p>

                    <button type="submit" disabled={form.processing} className="w-full bg-blue-600 text-white py-2.5 rounded-lg hover:bg-blue-700 transition font-medium shadow-sm disabled:opacity-60">
                        {t('user.update_user')}
                    </button>
                </form>
            </div>
        </>
    );
}
