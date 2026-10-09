import { useForm } from '@inertiajs/react';
import GuestLayout from '../../Layouts/GuestLayout';
import { t } from '../../lib/i18n';
import { BackToLogin, ErrorList, StatusBox, SubmitButton } from './parts';

export default function ForgotPassword() {
    const form = useForm({ email: '' });
    const submit = (e) => { e.preventDefault(); form.post('/forgot-password'); };

    return (
        <GuestLayout title={t('auth.forgot_password')} heading={t('auth.forgot_password')} subheading={t('auth.forgot_password_hint')}>
            <StatusBox />
            <ErrorList errors={form.errors} />
            <form onSubmit={submit} className="space-y-5">
                <div>
                    <label htmlFor="email" className="lbl">{t('auth.email')}</label>
                    <input type="email" name="email" id="email" required autoFocus placeholder="you@example.com" className="field"
                        value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                </div>
                <SubmitButton processing={form.processing} icon="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">{t('auth.send_reset_link')}</SubmitButton>
            </form>
            <BackToLogin />
        </GuestLayout>
    );
}

ForgotPassword.layout = (page) => page;
