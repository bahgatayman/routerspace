import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import useFlash from '../hooks/useFlash';
import { t } from '../lib/i18n';
import { useLayoutTitle } from '../lib/pageTitle';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

/* Same Heroicons outline paths as the Blade admin sidebar. */
const ICON = {
    dashboard: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
    workspaces: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4',
    notifications: 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9',
    features: 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z',
    locations: ['M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z', 'M15 11a3 3 0 11-6 0 3 3 0 016 0z'],
    rooms: 'M6 21V4a1 1 0 011-1h10a1 1 0 011 1v17M3 21h18M14 12h.01',
    bookings: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
    plans: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
    renewals: 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z',
    financial: 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
};

function NavIcon({ d }) {
    return (
        <svg className="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            {[].concat(d).map((p) => <path key={p} strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={p} />)}
        </svg>
    );
}

export default function AdminLayout({ children }) {
    const { props, url } = usePage();
    const { auth, dir, pendingRenewals = 0 } = props;
    const flash = useFlash({ toast: false }); // admin keeps its inline green/red boxes
    const { title } = useLayoutTitle();
    const [open, setOpen] = useState(false);
    const isRtl = dir === 'rtl';
    const path = url.split('?')[0];
    const is = (...prefixes) => prefixes.some((p) => path === p || path.startsWith(p + '/'));

    useEffect(() => router.on('navigate', () => setOpen(false)), []);

    const items = [
        ['/admin/dashboard', 'dashboard', t('nav.dashboard'), is('/admin/dashboard')],
        ['/admin/workspaces', 'workspaces', t('admin_platform.nav.workspaces'), is('/admin/workspaces', '/admin/owners')],
        ['/admin/notifications', 'notifications', t('nav.notifications'), is('/admin/notifications')],
        ['/admin/features', 'features', t('nav.features'), is('/admin/features')],
        ['/admin/locations', 'locations', t('admin_platform.nav.locations'), is('/admin/locations')],
        ['/admin/rooms', 'rooms', t('admin_platform.nav.rooms'), is('/admin/rooms')],
        ['/admin/bookings', 'bookings', t('nav.bookings'), is('/admin/bookings')],
        ['/admin/plans', 'plans', t('nav.plans'), is('/admin/plans')],
        ['/admin/subscription-requests', 'renewals', t('subscription.admin_requests'), is('/admin/subscription-requests'), pendingRenewals],
        ['/admin/financial', 'financial', t('nav.financial'), is('/admin/financial')],
    ];
    const hidden = isRtl ? 'translate-x-full' : '-translate-x-full';

    return (
        <>
            <Head title={`Link Space Panel Admin - ${title || t('nav.dashboard')}`} />
            <div className="app-shell flex flex-col lg:flex-row">
                <div className="lg:hidden shrink-0 flex items-center justify-between bg-brand-900 px-4 py-3">
                    <div className="flex items-center gap-2">
                        <img src="/logo.webp" alt="Link Space Panel" className="h-7 w-auto brightness-0 invert" />
                        <span className="text-[10px] bg-red-600 text-white px-1.5 py-0.5 rounded">Admin</span>
                    </div>
                    <button id="menu-toggle" type="button" className="text-white p-2 focus:outline-none" aria-label={t('ui.open_menu')} onClick={() => setOpen((o) => !o)}>
                        <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 6h16M4 12h16M4 18h16" /></svg>
                    </button>
                </div>

                <div id="sidebar-overlay" className={`lg:hidden fixed inset-0 bg-black/50 z-10 ${open ? '' : 'hidden'}`} onClick={() => setOpen(false)} />

                <aside id="sidebar" className={`fixed lg:static inset-y-0 ${isRtl ? 'right-0' : 'left-0'} z-20 w-[260px] bg-gradient-to-b from-brand-900 to-brand-800 text-white flex flex-col shrink-0 transition-transform duration-300 ${open ? '' : hidden} lg:translate-x-0`}>
                    <div className="shrink-0 flex flex-col items-center px-6 py-6 border-b border-white/10">
                        <img src="/logo.webp" alt="Link Space Panel" className="h-8 w-auto brightness-0 invert" />
                        <span className="text-[10px] bg-red-600 text-white px-1.5 py-0.5 rounded mt-2">Admin</span>
                    </div>
                    <nav className="nav-scroll flex-1 min-h-0 overflow-y-auto px-3 py-4 space-y-1">
                        {items.map(([href, icon, label, active, count]) => (
                            <Link key={href} href={href} className={`flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-white/10 transition ${active ? 'bg-white/10 border-l-4 border-red-500' : ''}`} aria-current={active ? 'page' : undefined}>
                                <NavIcon d={ICON[icon]} />
                                <span className="flex-1">{label}</span>
                                {count > 0 && <span className="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 text-[11px] font-bold text-white bg-red-500 rounded-full">{count}</span>}
                            </Link>
                        ))}
                    </nav>
                    <div className="px-4 py-4 border-t border-white/10">
                        <p className="text-sm text-gray-400 truncate">{auth?.name}</p>
                        <form method="POST" action="/admin/logout">
                            <input type="hidden" name="_token" value={csrf()} />
                            <button type="submit" className="text-xs text-red-400 hover:text-red-300 mt-1">{t('nav.logout')}</button>
                        </form>
                    </div>
                </aside>

                <div className="flex-1 flex flex-col min-w-0 min-h-0">
                    <header className="shrink-0 bg-white shadow-sm px-4 lg:px-6 py-4 flex items-center justify-between">
                        <h1 className="text-lg font-semibold text-gray-800 truncate">{title || t('nav.dashboard')}</h1>
                        <div className="flex items-center gap-3">
                            <form method="POST" action={`/language/${isRtl ? 'en' : 'ar'}`} className="flex items-center gap-1.5">
                                <input type="hidden" name="_token" value={csrf()} />
                                <button type="submit" className={`relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 ease-in-out focus:outline-none ${isRtl ? 'bg-indigo-600' : 'bg-gray-300'}`} role="switch" aria-checked={isRtl}>
                                    <span className={`inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-sm transition duration-200 ease-in-out ${isRtl ? 'translate-x-[18px]' : 'translate-x-[3px]'}`} />
                                </button>
                                <span className={`text-xs font-medium ${isRtl ? 'text-indigo-600' : 'text-gray-500'}`}>{isRtl ? 'AR' : 'EN'}</span>
                            </form>
                            <span className="text-sm text-gray-500 truncate">{t('admin.admin_panel')}</span>
                        </div>
                    </header>

                    <main className="flex-1 min-h-0 overflow-y-auto p-4 lg:p-6" scroll-region="">
                        {(flash.success || flash.status) && <div className="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-6" role="status">{flash.success || flash.status}</div>}
                        {flash.error && <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6" role="alert">{flash.error}</div>}
                        {flash.warning && <div className="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-lg mb-6" role="status">{flash.warning}</div>}
                        {children}
                    </main>
                </div>
            </div>
        </>
    );
}
