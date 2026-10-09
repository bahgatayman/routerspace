import { Head, Link, router, usePage } from '@inertiajs/react';
import { Fragment, useEffect, useState } from 'react';
import { Avatar, Banner, Button, Icon } from '../Components/ui';
import useFlash from '../hooks/useFlash';
import { t } from '../lib/i18n';
import { useLayoutTitle } from '../lib/pageTitle';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
const toneFor = (c) => ({ red: 'danger', rose: 'danger', yellow: 'warning', amber: 'warning', orange: 'warning', green: 'success', emerald: 'success' }[c] || 'info');

/** Live clock in the business timezone (minute precision), like the Blade layout. */
function Clock({ timezone }) {
    const fmt = () => new Date().toLocaleTimeString(document.documentElement.lang === 'ar' ? 'ar-EG-u-nu-latn' : 'en-US', { hour: 'numeric', minute: '2-digit', timeZone: timezone });
    const [now, setNow] = useState(fmt);
    useEffect(() => { const i = setInterval(() => setNow(fmt()), 30000); return () => clearInterval(i); }, []);
    return <span className="ls-live-pill" aria-hidden="true"><Icon name="clock" /><span>{now}</span></span>;
}

/** Plain POST form (full page load) — used for language switch and logout, which must reload the document. */
function PostForm({ action, children, className }) {
    return (
        <form method="POST" action={action} className={className}>
            <input type="hidden" name="_token" value={csrf()} />
            {children}
        </form>
    );
}

/** Sidebar link: client-side for Inertia pages, normal navigation for pages still on Blade. */
function NavLink({ item }) {
    const props = {
        className: `ls-nav-item ${item.active ? 'is-active' : ''}`,
        title: item.label,
        'aria-current': item.active ? 'page' : undefined,
    };
    const inner = (
        <>
            <Icon name={item.icon} />
            <span className="ls-trunc">{item.label}</span>
            {item.count > 0 && <b className="ls-nav-count">{item.count}</b>}
        </>
    );
    return item.spa ? <Link href={item.href} {...props}>{inner}</Link> : <a href={item.href} {...props}>{inner}</a>;
}

export default function OwnerLayout({ children }) {
    const { props } = usePage();
    const { auth, tenant, nav = [], notifications, dir } = props;
    const flash = useFlash();
    const { title, parent } = useLayoutTitle();
    const [denied, setDenied] = useState(null);
    const isRtl = dir === 'rtl';
    const who = auth?.name || tenant?.business_name;
    const unread = notifications?.unread || 0;

    useEffect(() => { if (flash.permission_denied) setDenied(flash.permission_denied); }, [flash]);
    // panel.js boots on DOMContentLoaded, which can fire before React's first paint: sync the theme menu once mounted.
    useEffect(() => { window.LS?.theme?.apply?.(); }, []);
    // Close the mobile drawer after a client-side navigation.
    // (through panel.js's own close control so its scroll-lock state stays in sync).
    useEffect(() => router.on('navigate', () => {
        if (document.getElementById('sidebar')?.classList.contains('is-open')) document.querySelector('[data-ls-side-close]')?.click();
    }), []);

    const themeOptions = [['light', 'sun', t('ui.theme_light')], ['dark', 'moon', t('ui.theme_dark')], ['system', 'monitor', t('ui.theme_system')]];

    return (
        <>
            <Head title={title ? `${title} · ${tenant?.business_name || ''}` : tenant?.business_name} />
            <a href="#main" className="ls-skip">{t('ui.skip_to_content')}</a>
            <div className="app-shell ls-shell">
                <div id="sidebar-overlay" className="ls-side-scrim" />

                <aside id="sidebar" className="ls-side" aria-label={t('ui.main_navigation')}>
                    <div className="ls-brand">
                        <Link href="/dashboard" className="ls-brand-logo" aria-label={`Link Space — ${t('nav.dashboard')}`}>
                            <img src="/logo.webp" alt="Link Space Panel" />
                        </Link>
                        <button type="button" className="ls-iconbtn ls-collapse" data-ls-nav-toggle aria-controls="sidebar" aria-expanded="true" aria-label={t('ui.collapse_sidebar')} title={t('ui.collapse_sidebar')}><Icon name="sidebar" /></button>
                        <button type="button" className="ls-iconbtn ls-side-close" data-ls-side-close aria-label={t('ui.close_menu')}><Icon name="x" /></button>
                    </div>
                    <nav className="nav-scroll ls-nav">
                        {nav.map((group) => (
                            <Fragment key={group.label}>
                                <div className="ls-nav-label">{group.label}</div>
                                {group.items.map((item) => <NavLink key={item.href} item={item} />)}
                            </Fragment>
                        ))}
                    </nav>
                    <div className="ls-side-foot">
                        <Avatar name={who} size="sm" />
                        <Link href="/profile" className="ls-who" title={t('nav.my_profile')}>
                            <b className="ls-trunc">{who}</b>
                            <span className="ls-trunc">{tenant?.business_name}</span>
                        </Link>
                        <PostForm action="/logout">
                            <button type="submit" className="ls-iconbtn" title={t('nav.logout')} aria-label={t('nav.logout')}><Icon name="logout" /></button>
                        </PostForm>
                    </div>
                </aside>

                <div className="ls-sheet">
                    <header className="ls-topbar">
                        <button id="menu-toggle" type="button" className="ls-iconbtn ls-menu-btn" data-ls-side-open aria-controls="sidebar" aria-expanded="false" aria-label={t('ui.open_menu')}><Icon name="menu" /></button>
                        <nav className="ls-crumbs" aria-label={t('ui.breadcrumb')}>
                            <span className="ls-crumb-home ls-trunc" style={{ maxWidth: 220 }}>{tenant?.business_name}</span>
                            {parent && <><span className="ls-crumb-sep" aria-hidden="true">/</span><span className="ls-crumb-home ls-trunc">{parent}</span></>}
                            <span className="ls-crumb-sep" aria-hidden="true">/</span>
                            <span className="ls-crumb-now ls-trunc" aria-current="page">{title || t('nav.dashboard')}</span>
                        </nav>
                        <div className="ls-topbar-spacer" />
                        <Clock timezone={props.timezone} />

                        <div className="ls-menu-wrap" id="notif-wrap">
                            <button type="button" className="ls-iconbtn" data-ls-menu="notif-dropdown" data-ls-menu-focus="none" aria-haspopup="true" aria-expanded="false"
                                aria-label={t('notif.title') + (unread > 0 ? ' — ' + t('ui.unread_count', { count: unread }) : '')}>
                                <Icon name="bell" />
                                {unread > 0 && <span className="ls-ndot" aria-hidden="true">{unread > 9 ? '9+' : unread}</span>}
                            </button>
                            <div id="notif-dropdown" className="ls-pop ls-pop--notif" hidden>
                                <div className="ls-pop-head">
                                    <span>{t('notif.title')}</span>
                                    {unread > 0 && (
                                        <button type="button" className="ls-link" style={{ fontSize: 12.5, background: 'none', border: 0, cursor: 'pointer' }}
                                            onClick={() => router.post('/notifications/read-all', {}, { preserveScroll: true })}>{t('notif.mark_all_read')}</button>
                                    )}
                                </div>
                                <div style={{ maxHeight: '24rem', overflowY: 'auto' }}>
                                    {(notifications?.recent || []).length === 0 && (
                                        <div style={{ padding: '32px 16px', textAlign: 'center', fontSize: 14, color: 'var(--color-text-muted)' }}>{t('notif.empty')}</div>
                                    )}
                                    {(notifications?.recent || []).map((n) => (
                                        <a key={n.id} href={n.url} className={`ls-notif ${n.read ? '' : 'is-unread'}`}>
                                            <span className={`ls-notif-icon ls-notif-icon--${toneFor(n.level)}`}>
                                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d={n.icon_path} /></svg>
                                            </span>
                                            <span className="ls-notif-body">
                                                <b>{n.title}</b>
                                                {n.body && <span>{n.body}</span>}
                                                <small>{n.ago}</small>
                                            </span>
                                            {!n.read && <span className="ls-notif-dot" role="img" aria-label={t('ui.unread')} />}
                                        </a>
                                    ))}
                                </div>
                                <Link href="/notifications" className="ls-pop-foot ls-link">{t('notif.view_all')}</Link>
                            </div>
                        </div>

                        <div className="ls-menu-wrap">
                            <button type="button" className="ls-iconbtn" data-ls-menu="theme-menu" aria-haspopup="true" aria-expanded="false" aria-label={t('ui.theme')} title={t('ui.theme')}>
                                <Icon name="sun" data-ls-theme-icon="light" />
                                <Icon name="moon" data-ls-theme-icon="dark" hidden />
                            </button>
                            <div id="theme-menu" className="ls-pop" role="menu" aria-label={t('ui.theme')} hidden>
                                <div className="ls-menu">
                                    <div className="ls-menu-label" aria-hidden="true">{t('ui.theme')}</div>
                                    {themeOptions.map(([key, icon, label]) => (
                                        <button key={key} type="button" className="ls-menu-item" role="menuitemradio" aria-checked="false" data-ls-theme-option={key}>
                                            <Icon name={icon} /><span>{label}</span><Icon name="check" className="ls-menu-check" />
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>

                        <div className="ls-lang" role="group" aria-label={t('ui.language')}>
                            <PostForm action="/language/en"><button type="submit" className={isRtl ? '' : 'is-on'} lang="en" aria-pressed={!isRtl}>EN</button></PostForm>
                            <PostForm action="/language/ar"><button type="submit" className={isRtl ? 'is-on' : ''} lang="ar" aria-pressed={isRtl}>عربي</button></PostForm>
                        </div>
                    </header>

                    <main id="main" className="ls-main flex-1 min-h-0 overflow-y-auto" tabIndex={-1} scroll-region="">
                        {tenant?.subscription_status === 'expiring_soon' && (
                            <Banner tone="warn">{t('msg.subscription_expires_in', { days: tenant.days_until_expiry })}</Banner>
                        )}
                        {flash.error && <Banner tone="danger">{flash.error}</Banner>}
                        {flash.warning && <Banner tone="warn">{flash.warning}</Banner>}
                        {children}
                    </main>
                </div>
            </div>

            {denied && (
                <div className="ls-overlay is-open" onClick={(e) => e.target === e.currentTarget && setDenied(null)}>
                    <div className="ls-dialog ls-dialog--narrow" role="alertdialog" aria-modal="true" aria-labelledby="permission-title">
                        <div className="ls-dialog-body" style={{ paddingTop: 26, justifyItems: 'center', textAlign: 'center' }}>
                            <span className="ls-icon-circle ls-icon-circle--danger"><Icon name="lock" /></span>
                            <h3 className="ls-dialog-title" id="permission-title">{t('error.403_heading')}</h3>
                            <p className="ls-muted" style={{ margin: 0 }}>{denied}</p>
                        </div>
                        <div className="ls-dialog-foot">
                            <Button variant="primary" block autoFocus onClick={() => setDenied(null)}>{t('common.close')}</Button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
