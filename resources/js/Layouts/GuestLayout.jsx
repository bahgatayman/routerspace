import { Head, usePage } from '@inertiajs/react';
import { t } from '../lib/i18n';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

/** Port of layouts/auth.blade.php — branded scene, language switch, centred card. */
export default function GuestLayout({ title, heading, subheading, width = 'max-w-md', children }) {
    const { dir } = usePage().props;
    const isRtl = dir === 'rtl';
    return (
        <>
            <Head title={`${title || t('auth.login')} · Link Space Panel`} />
            <div className="auth-scene" />
            <div className="auth-shell">
                <header className="auth-bar flex items-center justify-between gap-4 px-6 py-4 sm:px-10">
                    <img src="/logo.webp" alt="Link Space Panel" className="h-8 w-auto brightness-0 invert opacity-95" />
                    {/* Plain form: switching language reloads the whole document (new strings + direction). */}
                    <form method="POST" action={`/language/${isRtl ? 'en' : 'ar'}`} className="flex items-center gap-1.5">
                        <input type="hidden" name="_token" value={csrf()} />
                        <button type="submit" className={`relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 focus:outline-none ${isRtl ? 'bg-brand-400' : 'bg-white/25'}`} role="switch" aria-checked={isRtl}>
                            <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-sm transition duration-200 ${isRtl ? 'translate-x-[18px]' : 'translate-x-[3px]'}`} />
                        </button>
                        <span className="text-xs font-medium text-white/80">{isRtl ? 'AR' : 'EN'}</span>
                    </form>
                </header>

                <main className="auth-main px-4 py-6 sm:px-6">
                    <div className={`w-full ${width} auth-fade`}>
                        <div className="auth-card px-6 py-8 sm:px-9 sm:py-10">
                            {heading && (
                                <div className="mb-7 text-center">
                                    <h1 className="text-2xl sm:text-[1.7rem] font-bold tracking-tight text-surface-900">{heading}</h1>
                                    {subheading && <p className="text-sm text-surface-500 mt-1.5">{subheading}</p>}
                                </div>
                            )}
                            {children}
                        </div>
                    </div>
                </main>

                <footer className="auth-bar px-6 py-4 text-center text-xs text-white/50">&copy; {new Date().getFullYear()} Link Space Panel</footer>
            </div>
        </>
    );
}
