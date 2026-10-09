import { useForm } from '@inertiajs/react';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';

const LEVELS = ['info', 'success', 'warning', 'danger'];
const LEVEL_TONE = { success: 'bg-green-100 text-green-700', warning: 'bg-yellow-100 text-yellow-700', danger: 'bg-red-100 text-red-700', info: 'bg-blue-100 text-blue-700' };
const input = 'w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent';

export default function AdminNotificationsIndex({ owners, recent }) {
    usePageTitle(t('admin_notif.title'));
    const form = useForm({ title: '', body: '', level: 'info', action_url: '', target: 'active', owner_ids: [] });
    const { data, setData, errors } = form;
    const errorList = Object.values(errors);

    const toggleOwner = (id, on) => setData('owner_ids', on ? [...data.owner_ids, id] : data.owner_ids.filter((x) => x !== id));
    const submit = (e) => {
        e.preventDefault();
        form.post('/admin/notifications', {
            preserveScroll: true,
            // "No recipients" comes back as a flash error: keep what was typed, like withInput().
            onSuccess: (page) => { if (!page.props.flash?.error) form.reset(); },
        });
    };

    return (
        <div className="max-w-5xl mx-auto">
            <div className="mb-6">
                <h1 className="text-2xl font-bold text-gray-900">{t('admin_notif.title')}</h1>
                <p className="text-sm text-gray-500 mt-1">{t('admin_notif.subtitle')}</p>
            </div>

            {errorList.length > 0 && (
                <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6" role="alert">
                    {errorList.map((err, i) => <p key={i} className="text-sm">{err}</p>)}
                </div>
            )}

            <div className="grid lg:grid-cols-5 gap-6">
                <div className="lg:col-span-3 bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <form onSubmit={submit} className="space-y-5">
                        <div>
                            <label htmlFor="title" className="block text-sm font-medium text-gray-700 mb-1">{t('admin_notif.message_title')}</label>
                            <input type="text" id="title" required maxLength={120} className={input} value={data.title} onChange={(e) => setData('title', e.target.value)} />
                        </div>

                        <div>
                            <label htmlFor="body" className="block text-sm font-medium text-gray-700 mb-1">{t('admin_notif.message_body')}</label>
                            <textarea id="body" rows={4} maxLength={1000} className={input} value={data.body} onChange={(e) => setData('body', e.target.value)} />
                        </div>

                        <div className="grid sm:grid-cols-2 gap-4">
                            <div>
                                <label htmlFor="level" className="block text-sm font-medium text-gray-700 mb-1">{t('admin_notif.level')}</label>
                                <select id="level" className="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-brand-500"
                                    value={data.level} onChange={(e) => setData('level', e.target.value)}>
                                    {LEVELS.map((l) => <option key={l} value={l}>{t(`admin_notif.level_${l}`)}</option>)}
                                </select>
                            </div>
                            <div>
                                <label htmlFor="action_url" className="block text-sm font-medium text-gray-700 mb-1">
                                    {t('admin_notif.action_url')} <span className="text-gray-400 font-normal">({t('admin_notif.optional')})</span>
                                </label>
                                <input type="text" id="action_url" placeholder="/dashboard" className={input} value={data.action_url} onChange={(e) => setData('action_url', e.target.value)} />
                            </div>
                        </div>
                        <p className="-mt-3 text-xs text-gray-400">{t('admin_notif.action_url_hint')}</p>

                        <div>
                            <span className="block text-sm font-medium text-gray-700 mb-2">{t('admin_notif.target')}</span>
                            <div className="space-y-2">
                                {['all', 'active', 'selected'].map((v) => (
                                    <label key={v} className="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                                        <input type="radio" name="target" value={v} className="text-brand-600 focus:ring-brand-500"
                                            checked={data.target === v} onChange={() => setData('target', v)} />
                                        {t(`admin_notif.target_${v}`)}
                                    </label>
                                ))}
                            </div>

                            {data.target === 'selected' && (
                                <div id="owner-picker" className="mt-3">
                                    <div className="border border-gray-200 rounded-lg max-h-56 overflow-y-auto divide-y divide-gray-50">
                                        {owners.length === 0 ? (
                                            <p className="px-3 py-4 text-sm text-gray-400">{t('empty.no_owners')}</p>
                                        ) : owners.map((o) => (
                                            <label key={o.id} className="flex items-center gap-2 px-3 py-2 text-sm hover:bg-gray-50 cursor-pointer">
                                                <input type="checkbox" value={o.id} className="text-brand-600 focus:ring-brand-500"
                                                    checked={data.owner_ids.includes(o.id)} onChange={(e) => toggleOwner(o.id, e.target.checked)} />
                                                <span className="font-medium text-gray-800">{o.name}</span>
                                                {!o.is_active && <span className="text-[10px] bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded-full">{t('status.inactive')}</span>}
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>

                        <button type="submit" disabled={form.processing} className="w-full bg-brand-600 text-white py-2.5 rounded-lg hover:bg-brand-700 transition font-medium shadow-sm disabled:opacity-60">
                            {t('admin_notif.send')}
                        </button>
                    </form>
                </div>

                <div className="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h2 className="text-lg font-semibold text-gray-900 mb-4">{t('admin_notif.recent_sends')}</h2>
                    {recent.length === 0 ? (
                        <p className="text-sm text-gray-400">{t('admin_notif.no_sends')}</p>
                    ) : (
                        <ul className="space-y-3">
                            {recent.map((r) => (
                                <li key={r.key} className="border border-gray-100 rounded-lg p-3">
                                    <div className="flex items-start justify-between gap-2">
                                        <p className="text-sm font-semibold text-gray-800">{r.title}</p>
                                        <span className={`shrink-0 text-[10px] font-medium px-2 py-0.5 rounded-full ${LEVEL_TONE[r.level] || LEVEL_TONE.info}`}>{t(`admin_notif.level_${r.level}`)}</span>
                                    </div>
                                    {r.body && <p className="text-xs text-gray-500 mt-1 line-clamp-2">{r.body}</p>}
                                    <div className="flex items-center justify-between mt-2 text-[11px] text-gray-400">
                                        <span>{r.sent}</span>
                                        <span>{t('admin_notif.read_of', { read: r.read_count, total: r.recipients })}</span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </div>
    );
}
