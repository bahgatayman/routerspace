import { Link, useForm } from '@inertiajs/react';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';

const INPUT = 'w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent';

function Err({ msg }) {
    return msg ? <p className="text-xs text-red-600 mt-1">{msg}</p> : null;
}

export default function OwnerCreate({ plans }) {
    usePageTitle(t('admin.add_owner'));
    const form = useForm({
        name: '', email: '', business_name: '', password: '',
        mikrotik_host: '', mikrotik_port: 8728, mikrotik_username: '', mikrotik_password: '',
        plan_id: plans[0]?.id ?? '', months: 1,
    });
    const { data, setData, errors } = form;
    const field = (name, label, type = 'text', extra = {}) => (
        <div>
            <label className="block text-sm font-medium text-gray-700 mb-1" htmlFor={`f-${name}`}>{label}</label>
            <input id={`f-${name}`} type={type} name={name} value={data[name]} onChange={(e) => setData(name, e.target.value)} className={INPUT} {...extra} />
            <Err msg={errors[name]} />
        </div>
    );
    const submit = (e) => {
        e.preventDefault();
        form.post('/admin/owners', { onError: () => form.reset('password', 'mikrotik_password') });
    };

    return (
        <div className="max-w-3xl mx-auto">
            <h1 className="text-2xl font-bold text-gray-900 mb-6">{t('admin.add_owner')}</h1>

            <form onSubmit={submit} className="bg-white rounded-xl shadow-sm border border-gray-100 p-6 lg:p-8 space-y-6">
                <div>
                    <h2 className="text-lg font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100">{t('label.owner_info')}</h2>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        {field('name', t('common.name'), 'text', { required: true })}
                        {field('email', t('common.email'), 'email', { required: true })}
                        {field('business_name', t('label.business_name'), 'text', { required: true })}
                        {field('password', t('auth.password'), 'password', { required: true })}
                    </div>
                </div>

                <div>
                    <h2 className="text-lg font-semibold text-gray-800 mb-1 pb-2 border-b border-gray-100">
                        {t('auth.mikrotik_connection')}{' '}
                        <span className="text-xs font-normal text-gray-400">— {t('settings.mikrotik_optional_hint')}</span>
                    </h2>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                        {field('mikrotik_host', t('label.mikrotik_host'), 'text', { placeholder: t('placeholder.router_ip') })}
                        {field('mikrotik_port', t('label.mikrotik_port'), 'number')}
                        {field('mikrotik_username', t('label.mikrotik_username'))}
                        {field('mikrotik_password', t('label.mikrotik_password'), 'password')}
                    </div>
                </div>

                <div>
                    <h2 className="text-lg font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-100">{t('label.subscription')}</h2>
                    <div className="mb-4">
                        <span className="block text-sm font-medium text-gray-700 mb-2">{t('common.plan')}</span>
                        <div className="grid grid-cols-2 gap-3">
                            {plans.map((plan) => (
                                <label key={plan.id} className="cursor-pointer">
                                    <input type="radio" name="plan_id" value={plan.id} checked={String(data.plan_id) === String(plan.id)}
                                        onChange={() => setData('plan_id', plan.id)} className="sr-only peer" />
                                    <div className="border-2 rounded-xl p-3 transition peer-checked:border-red-500 peer-checked:bg-red-50 hover:border-gray-300">
                                        <p className="font-semibold text-sm text-gray-900">{plan.name}</p>
                                        <p className="text-xs text-gray-500">{plan.max_members} {t('plan.members')}</p>
                                        <p className="text-sm font-bold text-red-600 mt-1">{plan.price_label}</p>
                                    </div>
                                </label>
                            ))}
                        </div>
                        <Err msg={errors.plan_id} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1" htmlFor="f-months">{t('table.th.months')}</label>
                        <input id="f-months" type="number" name="months" value={data.months} min="1" max="24" required
                            onChange={(e) => setData('months', e.target.value)}
                            className="w-full sm:w-48 border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent" />
                        <p className="text-xs text-gray-500 mt-1">Owner will have access for this many months starting today.</p>
                        <Err msg={errors.months} />
                    </div>
                </div>

                <div className="flex items-center gap-3 pt-4">
                    <button type="submit" disabled={form.processing} className="bg-red-600 text-white px-6 py-2.5 rounded-lg hover:bg-red-700 transition font-medium shadow-sm disabled:opacity-60">
                        {t('btn.create_owner')}
                    </button>
                    <Link href="/admin/workspaces" className="text-gray-600 hover:text-gray-800 text-sm font-medium">{t('common.cancel')}</Link>
                </div>
            </form>
        </div>
    );
}
