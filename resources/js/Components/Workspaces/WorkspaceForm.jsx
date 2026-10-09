import { Link, useForm } from '@inertiajs/react';
import { t } from '../../lib/i18n';

const INPUT = 'w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent';

/** Shared body of workspaces/create + workspaces/edit (same fields, same URLs as the Blade forms). */
export default function WorkspaceForm({ workspace, title, submitLabel, action, method, backUrl }) {
    const form = useForm({
        name: workspace?.name || '',
        description: workspace?.description || '',
        address: workspace?.address || '',
        city: workspace?.city || '',
        phone: workspace?.phone || '',
    });

    const submit = (e) => {
        e.preventDefault();
        form[method](action);
    };

    const err = (f) => form.errors[f] && <p className="text-xs text-red-500 mt-1">{form.errors[f]}</p>;
    const bind = (f) => ({ name: f, value: form.data[f], onChange: (e) => form.setData(f, e.target.value) });

    return (
        <div className="max-w-2xl mx-auto">
            <Link href={backUrl} className="text-sm text-blue-600 hover:text-blue-800 mb-4 inline-block">← {t('btn.back_to_workspaces')}</Link>

            <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h1 className="text-xl font-bold text-gray-900 mb-6">{title}</h1>

                <form onSubmit={submit}>
                    <div className="space-y-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">{t('workspace.workspace_name')} <span className="text-red-500">*</span></label>
                            <input type="text" {...bind('name')} required className={INPUT} />
                            {err('name')}
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">{t('workspace.description')}</label>
                            <textarea {...bind('description')} rows={3} className={INPUT} />
                            {err('description')}
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">{t('workspace.address')}</label>
                            <input type="text" {...bind('address')} className={INPUT} />
                            {err('address')}
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">{t('workspace.city')}</label>
                            <input type="text" {...bind('city')} className={INPUT} />
                            {err('city')}
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">{t('workspace.phone')}</label>
                            <input type="text" {...bind('phone')} className={INPUT} />
                            {err('phone')}
                        </div>
                    </div>

                    <div className="mt-6 flex items-center gap-3">
                        <button type="submit" disabled={form.processing}
                            className="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm disabled:opacity-60">
                            {submitLabel}
                        </button>
                        <Link href={backUrl} className="text-sm text-gray-600 hover:text-gray-800">{t('common.cancel')}</Link>
                    </div>
                </form>
            </div>
        </div>
    );
}
