import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { t } from '../../lib/i18n';

/* Shared pieces of the auth pages — same markup/classes as the Blade auth views (styles live in inertia/guest.blade.php). */

export function StatusBox() {
    const { flash = {} } = usePage().props;
    if (!flash.status) return null;
    return (
        <div className="mb-5 rounded-xl bg-emerald-50 border border-emerald-100 px-4 py-3">
            <p className="text-sm text-emerald-700 flex items-center gap-2">
                <svg className="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                <span>{flash.status}</span>
            </p>
        </div>
    );
}

export function ErrorList({ errors }) {
    const list = Object.values(errors || {});
    if (!list.length) return null;
    return (
        <div className="mb-5 rounded-xl bg-red-50 border border-red-100 px-4 py-3 space-y-1" role="alert">
            {list.map((e) => (
                <p key={e} className="text-sm text-red-600 flex items-start gap-2">
                    <svg className="w-4 h-4 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <span>{e}</span>
                </p>
            ))}
        </div>
    );
}

export function PasswordInput({ id, value, onChange, minLength, autoComplete }) {
    const [shown, setShown] = useState(false);
    return (
        <div className="relative">
            <input type={shown ? 'text' : 'password'} name={id} id={id} required minLength={minLength} autoComplete={autoComplete} className="field pe-10" value={value} onChange={onChange} />
            <button type="button" className="absolute inset-y-0 end-0 flex items-center pe-3 text-surface-400 hover:text-surface-600" tabIndex={-1}
                aria-label={t(shown ? 'password_field.hide' : 'password_field.show')} aria-pressed={shown} onClick={() => setShown((s) => !s)}>
                {shown
                    ? <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.7" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" /></svg>
                    : <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.7" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.7" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>}
            </button>
        </div>
    );
}

export function SubmitButton({ processing, children, icon = 'M17 8l4 4m0 0l-4 4m4-4H3' }) {
    return (
        <button type="submit" className="btn-primary" disabled={processing} aria-busy={processing || undefined} style={processing ? { opacity: 0.7 } : undefined}>
            {children}
            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d={icon} /></svg>
        </button>
    );
}

export function BackToLogin() {
    return (
        <p className="text-center text-sm text-surface-500 mt-7">
            <a href="/login" className="text-indigo-600 font-semibold hover:text-indigo-700 inline-flex items-center gap-1.5">
                <svg className="w-4 h-4 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
                {t('auth.back_to_login')}
            </a>
        </p>
    );
}
