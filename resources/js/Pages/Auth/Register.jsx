import { Link, useForm } from '@inertiajs/react';
import GuestLayout from '../../Layouts/GuestLayout';
import { t } from '../../lib/i18n';
import { ErrorList, PasswordInput, SubmitButton } from './parts';

export default function Register() {
    const form = useForm({ name: '', business_name: '', email: '', password: '', password_confirmation: '' });
    const field = (key) => ({ value: form.data[key], onChange: (e) => form.setData(key, e.target.value) });
    const submit = (e) => {
        e.preventDefault();
        form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <GuestLayout title={t('auth.register')} heading={t('auth.create_your_account')} subheading={t('auth.coworking_management')} width="max-w-xl">
            <ErrorList errors={form.errors} />
            <form onSubmit={submit} className="space-y-6">
                <div className="space-y-4">
                    <p className="group-lbl">{t('auth.account_information')}</p>
                    <div className="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label htmlFor="name" className="lbl">{t('auth.name')}</label>
                            <input type="text" name="name" id="name" required className="field" autoComplete="name" {...field('name')} />
                        </div>
                        <div>
                            <label htmlFor="business_name" className="lbl">{t('auth.business_name')}</label>
                            <input type="text" name="business_name" id="business_name" required className="field" autoComplete="organization" {...field('business_name')} />
                        </div>
                    </div>
                    <div>
                        <label htmlFor="email" className="lbl">{t('auth.email')}</label>
                        <input type="email" name="email" id="email" required placeholder="you@example.com" className="field" autoComplete="email" {...field('email')} />
                    </div>
                    <div className="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label htmlFor="password" className="lbl">{t('auth.password')}</label>
                            <PasswordInput id="password" minLength={8} autoComplete="new-password" {...field('password')} />
                        </div>
                        <div>
                            <label htmlFor="password_confirmation" className="lbl">{t('auth.confirm_password')}</label>
                            <input type="password" name="password_confirmation" id="password_confirmation" required className="field" autoComplete="new-password" {...field('password_confirmation')} />
                        </div>
                    </div>
                </div>
                <SubmitButton processing={form.processing}>{t('auth.create_account')}</SubmitButton>
            </form>
            <p className="text-center text-sm text-surface-500 mt-7">
                {t('auth.already_have_account')}{' '}
                <Link href="/login" className="text-indigo-600 font-semibold hover:text-indigo-700">{t('auth.login')}</Link>
            </p>
        </GuestLayout>
    );
}

Register.layout = (page) => page;
