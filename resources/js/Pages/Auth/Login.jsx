import { Link, useForm } from '@inertiajs/react';
import GuestLayout from '../../Layouts/GuestLayout';
import { t } from '../../lib/i18n';
import { ErrorList, PasswordInput, StatusBox, SubmitButton } from './parts';

export default function Login() {
    const form = useForm({ email: '', password: '', remember: false });
    const submit = (e) => {
        e.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    return (
        <GuestLayout title={t('auth.login')} heading={t('auth.welcome_back')} subheading={t('auth.login_subtitle')}>
            <StatusBox />
            <ErrorList errors={form.errors} />
            <form onSubmit={submit} className="space-y-5" noValidate={false}>
                <div>
                    <label htmlFor="email" className="lbl">{t('auth.email')}</label>
                    <input type="email" name="email" id="email" required autoFocus autoComplete="username" placeholder="you@example.com" className="field"
                        value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                </div>
                <div>
                    <div className="flex items-center justify-between mb-2">
                        <label htmlFor="password" className="lbl mb-0">{t('auth.password')}</label>
                        <Link href="/forgot-password" className="text-xs font-semibold text-indigo-600 hover:text-indigo-700">{t('auth.forgot_password')}</Link>
                    </div>
                    <PasswordInput id="password" autoComplete="current-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                </div>
                <label className="flex items-center gap-2 text-sm text-surface-600 select-none cursor-pointer">
                    <input type="checkbox" name="remember" className="rounded border-surface-300 text-indigo-600 focus:ring-indigo-500/40"
                        checked={form.data.remember} onChange={(e) => form.setData('remember', e.target.checked)} />
                    {t('auth.remember_me')}
                </label>
                <SubmitButton processing={form.processing}>{t('auth.sign_in')}</SubmitButton>
            </form>
            <p className="text-center text-sm text-surface-500 mt-7">
                {t('auth.dont_have_account')}{' '}
                <Link href="/register" className="text-indigo-600 font-semibold hover:text-indigo-700">{t('auth.register')}</Link>
            </p>
        </GuestLayout>
    );
}

Login.layout = (page) => page;
