import { router, useForm } from '@inertiajs/react';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

const inputCls = 'w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent';
const timeCls = 'working-hour-time border border-gray-300 rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent disabled:bg-gray-50 disabled:text-gray-400';

/** MikroTik router credentials. The stored password never reaches the page: blank keeps it. */
function RouterCard({ router: r }) {
    const form = useForm({
        mikrotik_host: r.mikrotik_host ?? '',
        mikrotik_port: r.mikrotik_port ?? 8728,
        mikrotik_username: r.mikrotik_username ?? '',
        mikrotik_password: '',
    });
    const set = (k) => (e) => form.setData(k, e.target.value);
    const submit = (e) => {
        e.preventDefault();
        form.post('/settings', { preserveScroll: true, onSuccess: () => form.setData('mikrotik_password', '') });
    };

    return (
        <div className="bg-white rounded-lg shadow p-6 mb-6 max-w-2xl">
            <h2 className="text-lg font-semibold text-gray-700 mb-1">{t('profile.mikrotik_connection')}</h2>
            <p className="text-sm text-gray-500 mb-5">{t('msg.check_mikrotik_settings')}</p>

            {form.errors.mikrotik_host && <p className="text-sm text-red-600 mb-3">{form.errors.mikrotik_host}</p>}

            <form onSubmit={submit} className="space-y-4">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label htmlFor="mikrotik_host" className="block text-sm font-medium text-gray-700 mb-1">{t('label.mikrotik_host')}</label>
                        <input id="mikrotik_host" type="text" name="mikrotik_host" value={form.data.mikrotik_host} onChange={set('mikrotik_host')} required placeholder={t('placeholder.router_ip')} className={inputCls} />
                    </div>
                    <div>
                        <label htmlFor="mikrotik_port" className="block text-sm font-medium text-gray-700 mb-1">{t('label.mikrotik_port')}</label>
                        <input id="mikrotik_port" type="number" name="mikrotik_port" value={form.data.mikrotik_port} onChange={set('mikrotik_port')} required className={inputCls} />
                    </div>
                    <div>
                        <label htmlFor="mikrotik_username" className="block text-sm font-medium text-gray-700 mb-1">{t('label.mikrotik_username')}</label>
                        <input id="mikrotik_username" type="text" name="mikrotik_username" value={form.data.mikrotik_username} onChange={set('mikrotik_username')} required className={inputCls} />
                    </div>
                    <div>
                        <label htmlFor="mikrotik_password" className="block text-sm font-medium text-gray-700 mb-1">{t('label.mikrotik_password')}</label>
                        <input id="mikrotik_password" type="password" name="mikrotik_password" value={form.data.mikrotik_password} onChange={set('mikrotik_password')} placeholder="••••••••" autoComplete="new-password" className={inputCls} />
                        <p className="text-xs text-gray-400 mt-1">{t('settings.password_keep_hint')}</p>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-3 pt-2">
                    <button type="submit" disabled={form.processing} className="bg-indigo-600 text-white px-6 py-2 rounded hover:bg-indigo-700 transition text-sm font-medium disabled:opacity-60">
                        {t('common.save')}
                    </button>
                </div>
            </form>

            {r.configured && (
                <div className="mt-3 pt-4 border-t border-gray-100">
                    <button type="button" onClick={() => router.post('/settings/test-connection', {}, { preserveScroll: true })}
                        className="bg-white border border-gray-300 text-gray-700 px-6 py-2 rounded hover:bg-gray-50 transition text-sm font-medium">
                        {t('btn.test_connection')}
                    </button>
                </div>
            )}
        </div>
    );
}

/** Weekly schedule: one row per day (Saturday first); the toggle enables that row's time selects. */
function WorkingHoursCard({ days, slots, configured }) {
    const first = slots[0]?.value ?? '00:00';
    const form = useForm({
        hours: Object.fromEntries(days.map((d) => [d.day_of_week, {
            is_open: !!d.is_open,
            open_time: d.open_time ?? first,
            close_time: d.close_time ?? first,
        }])),
    });
    const setDay = (dow, patch) => form.setData('hours', { ...form.data.hours, [dow]: { ...form.data.hours[dow], ...patch } });

    const submit = (e) => {
        e.preventDefault();
        // Closed days post only is_open (their selects were disabled in the Blade form, so not submitted).
        form.transform((d) => ({
            hours: Object.fromEntries(Object.entries(d.hours).map(([dow, h]) => [dow, h.is_open
                ? { is_open: 1, open_time: h.open_time, close_time: h.close_time }
                : { is_open: 0 }])),
        }));
        form.post('/settings/working-hours', { preserveScroll: true });
    };

    return (
        <div className="bg-white rounded-lg shadow p-6 mb-6 max-w-2xl">
            <h2 className="text-lg font-semibold text-gray-700 mb-1">{t('settings.working_hours.title')}</h2>
            <p className="text-sm text-gray-500 mb-1">{t('settings.working_hours.subtitle')}</p>

            {!configured && <p className="text-xs text-amber-600 mt-2 mb-1">{t('settings.working_hours.unrestricted_hint')}</p>}

            <form onSubmit={submit} className="mt-4">
                <div className="divide-y divide-gray-100">
                    {days.map((day) => {
                        const dow = day.day_of_week;
                        const h = form.data.hours[dow];
                        return (
                            <div key={dow} className="flex flex-wrap items-center gap-3 py-3" data-working-hour-row>
                                <div className="flex items-center gap-2.5 w-32 shrink-0">
                                    <label className="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name={`hours[${dow}][is_open]`} value="1" className="working-hour-toggle sr-only peer"
                                            checked={h.is_open} onChange={(e) => setDay(dow, { is_open: e.target.checked })} aria-label={day.day_name} />
                                        <div className="w-10 h-6 bg-gray-200 peer-checked:bg-indigo-600 rounded-full transition-colors duration-200 peer-focus-visible:ring-2 peer-focus-visible:ring-indigo-300 peer-focus-visible:ring-offset-1" />
                                        <span className="absolute left-0.5 top-0.5 bg-white w-5 h-5 rounded-full shadow-sm transition-transform duration-200 peer-checked:translate-x-4" />
                                    </label>
                                    <span className="text-sm font-medium text-gray-700">{day.day_name}</span>
                                </div>

                                <select name={`hours[${dow}][open_time]`} className={timeCls} disabled={!h.is_open} value={h.open_time} onChange={(e) => setDay(dow, { open_time: e.target.value })}>
                                    {slots.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                                </select>

                                <span className="text-gray-400 text-sm">&rarr;</span>

                                <select name={`hours[${dow}][close_time]`} className={timeCls} disabled={!h.is_open} value={h.close_time} onChange={(e) => setDay(dow, { close_time: e.target.value })}>
                                    {slots.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                                </select>

                                <span className={`working-hour-status text-xs ${h.is_open ? 'text-green-600' : 'text-gray-400'}`}>
                                    {h.is_open ? t('settings.working_hours.open') : t('settings.working_hours.closed')}
                                </span>
                            </div>
                        );
                    })}
                </div>

                <div className="flex flex-wrap items-center gap-3 pt-4">
                    <button type="submit" disabled={form.processing} className="bg-indigo-600 text-white px-6 py-2 rounded hover:bg-indigo-700 transition text-sm font-medium disabled:opacity-60">
                        {t('settings.working_hours.save')}
                    </button>
                </div>
            </form>
        </div>
    );
}

export default function SettingsIndex({ features, router: routerSettings, workingHours, workingHoursTimeSlots, hasConfiguredWorkingHours }) {
    usePageTitle(t('section.settings'));

    return (
        <>
            <h1 className="text-2xl font-bold text-gray-800 mb-6">{t('section.settings')}</h1>

            {features.hotspot && <RouterCard router={routerSettings} />}

            {(features.workspace || features.booking) && (
                <WorkingHoursCard days={workingHours} slots={workingHoursTimeSlots} configured={hasConfiguredWorkingHours} />
            )}

            {!features.hotspot && !features.workspace && !features.booking && (
                <div className="bg-white rounded-lg shadow p-8 max-w-2xl text-center">
                    <div className="w-12 h-12 mx-auto mb-3 rounded-full bg-gray-100 flex items-center justify-center">
                        <svg className="w-6 h-6 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /></svg>
                    </div>
                    <p className="text-sm text-gray-500">{t('settings.no_settings')}</p>
                </div>
            )}
        </>
    );
}
