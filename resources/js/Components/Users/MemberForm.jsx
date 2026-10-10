import { useForm } from '@inertiajs/react';
import { t } from '../../lib/i18n';

const INPUT = 'w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent';

function FieldError({ message }) {
    return message ? <p className="text-red-600 text-xs mt-1">{message}</p> : null;
}

/** Plan usage bar (members used of the plan's limit). */
export function PlanUsage({ plan, className = '' }) {
    if (!plan) return null;
    const pct = plan.percent || 0;
    return (
        <div className={`bg-white rounded-xl border p-4 ${className}`}>
            <div className="flex justify-between items-center mb-2">
                <span className="text-sm font-medium text-gray-700">{t('common.members')}: {plan.members} / {plan.max_members}</span>
                <span className="text-xs px-2 py-0.5 rounded-full bg-blue-100 text-blue-700">{plan.name} {t('common.plan')}</span>
            </div>
            <div className="w-full bg-gray-100 rounded-full h-2">
                <div className={`h-2 rounded-full ${pct >= 100 ? 'bg-red-500' : pct >= 80 ? 'bg-yellow-400' : 'bg-green-400'}`} style={{ width: `${Math.min(100, pct)}%` }} />
            </div>
            {plan.remaining <= 5 && plan.remaining > 0 && <p className="text-xs text-yellow-600 mt-2">⚠ {t('msg.slots_remaining', { count: plan.remaining })}</p>}
            {plan.remaining === 0 && <p className="text-xs text-red-600 mt-2">✗ {t('msg.plan_limit_reached_contact')}</p>}
        </div>
    );
}

/**
 * Add-member form, posting to the unchanged POST /users (server validation,
 * plan limit and MikroTik provisioning all stay on the server). Used by the
 * Users page pop-up and by the /users/create page.
 *
 * onSaved() runs only when the member was really created: the server
 * answers a failure (plan limit, router error) with a redirect back + an
 * `error` flash, which we show inline and keep the typed values.
 */
export default function MemberForm({ hasHotspot, idPrefix = 'member', onSaved, autoFocus = false }) {
    const form = useForm({ name: '', phone: '', email: '', notes: '' });
    const id = (f) => `${idPrefix}-${f}`;

    const submit = (e) => {
        e.preventDefault();
        form.clearErrors();
        form.post('/users', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const failed = page.props.flash && page.props.flash.error;
                if (failed) {
                    form.setError('form', failed);
                    return;
                }
                form.reset();
                onSaved && onSaved();
            },
        });
    };

    return (
        <form onSubmit={submit} noValidate={false}>
            {form.errors.form && (
                <div className="bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded-lg mb-4 text-sm" role="alert">{form.errors.form}</div>
            )}

            <div className="mb-4">
                <label htmlFor={id('name')} className="block text-sm font-medium text-gray-700 mb-1">{t('user.name')}</label>
                <input type="text" name="name" id={id('name')} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required autoFocus={autoFocus} className={INPUT} />
                <FieldError message={form.errors.name} />
            </div>

            <div className="mb-4">
                <label htmlFor={id('phone')} className="block text-sm font-medium text-gray-700 mb-1">{t('user.phone')}</label>
                <input type="text" name="phone" id={id('phone')} value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} required inputMode="numeric" dir="ltr" className={INPUT} />
                {hasHotspot && <p className="text-xs text-gray-400 mt-2">This will be the MikroTik login username. Password will be set to the phone number automatically.</p>}
                <FieldError message={form.errors.phone} />
            </div>

            <div className="mb-4">
                <label htmlFor={id('email')} className="block text-sm font-medium text-gray-700 mb-1">{t('user.email')} (optional)</label>
                <input type="email" name="email" id={id('email')} value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} className={INPUT} />
                <FieldError message={form.errors.email} />
            </div>

            <div className="mb-6">
                <label htmlFor={id('notes')} className="block text-sm font-medium text-gray-700 mb-1">{t('user.notes')} (optional)</label>
                <textarea name="notes" id={id('notes')} rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} className={INPUT} />
                <FieldError message={form.errors.notes} />
            </div>

            <button type="submit" disabled={form.processing} className="w-full bg-blue-600 text-white py-2.5 rounded-lg hover:bg-blue-700 transition font-medium shadow-sm disabled:opacity-60">
                {t('btn.add_user')}{hasHotspot && <> &amp; {t('user.update_speed_on_mikrotik')}</>}
            </button>
        </form>
    );
}
