import { useForm } from '@inertiajs/react';
import GuestLayout from '../../Layouts/GuestLayout';
import { t } from '../../lib/i18n';
import { BackToLogin, ErrorList, PasswordInput, SubmitButton } from './parts';

export default function ResetPassword({ token, email }) {
    const form = useForm({ token, email: email || '', password: '', password_confirmation: '' });
    const field = (key) => ({ value: form.data[key], onChange: (e) => form.setData(key, e.target.value) });
    const submit = (e) => {
        e.preventDefault();
        form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <GuestLayout title={t('auth.reset_password')} heading={t('auth.reset_password')} subheading={t('auth.reset_password_hint')}>
            <ErrorList errors={form.errors} />
            <form onSubmit={submit} className="space-y-5">
                <div>
                    <label htmlFor="email" className="lbl">{t('auth.email')}</label>
                    <input type="email" name="email" id="email" required autoFocus className="field" {...field('email')} />
                </div>
                <div>
                    <label htmlFor="password" className="lbl">{t('auth.new_password')}</label>
                    <PasswordInput id="password" minLength={8} autoComplete="new-password" {...field('password')} />
                </div>
                <div>
                    <label htmlFor="password_confirmation" className="lbl">{t('auth.confirm_password')}</label>
                    <PasswordInput id="password_confirmation" autoComplete="new-password" {...field('password_confirmation')} />
                </div>
                <SubmitButton processing={form.processing} icon="M5 13l4 4L19 7">{t('auth.reset_password')}</SubmitButton>
            </form>
            <BackToLogin />
        </GuestLayout>
    );
}

ResetPassword.layout = (page) => page;
