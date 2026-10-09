/*
 * React ports of resources/views/components/ui/* — same ls-* markup and
 * classes (public/css/panel.css), so React and Blade pages look identical.
 */
import { Link, router } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import { t } from '../../lib/i18n';
import { crc32, money } from '../../lib/format';
import ICONS from './icons';
import ILLUSTRATIONS from './illustrations';

const cx = (...c) => c.filter(Boolean).join(' ');

export function Icon({ name, className, ...rest }) {
    return (
        <svg className={cx('ls-icon', className)} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8"
            strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" {...rest}
            dangerouslySetInnerHTML={{ __html: ICONS[name] || '' }} />
    );
}

export function Illustration({ name = 'quiet', className }) {
    return (
        <div className={cx('ls-illo', className)}>
            <svg viewBox="0 0 120 80" aria-hidden="true" dangerouslySetInnerHTML={{ __html: ILLUSTRATIONS[name] || ILLUSTRATIONS.done }} />
        </div>
    );
}

/** <Button href> renders an Inertia <Link> (client-side); pass `native` for a full page load (Blade pages). */
export function Button({ variant = 'secondary', size, icon, href, type = 'button', block, arrow, iconOnly, native, method, data, children, className, processing, disabled, ...rest }) {
    const classes = cx('ls-btn', `ls-btn--${variant}`, size === 'sm' && 'ls-btn--sm', block && 'ls-btn--block', iconOnly && 'ls-btn--icon', processing && 'is-loading', className);
    const inner = (
        <>
            {icon && <Icon name={icon} />}
            {!iconOnly && <span>{children}</span>}
            {arrow && <Icon name="arrow-right" className="ls-arrow" />}
        </>
    );
    if (href && native) return <a href={href} className={classes} {...rest}>{inner}</a>;
    if (href) return <Link href={href} method={method} data={data} as={method && method !== 'get' ? 'button' : 'a'} className={classes} {...rest}>{inner}</Link>;
    return <button type={type} className={classes} disabled={disabled || processing} aria-busy={processing || undefined} {...rest}>{inner}</button>;
}

export function Badge({ tone = 'neutral', dot = true, pulse, children, className }) {
    return (
        <span className={cx('ls-badge', `ls-badge--${tone}`, className)}>
            {dot && <span className={cx('ls-dot', pulse && 'is-pulse')} />}
            {children}
        </span>
    );
}

export function Banner({ tone = 'info', title, children, className }) {
    return (
        <div className={cx('ls-banner', `ls-banner--${tone}`, className)} role={tone === 'danger' ? 'alert' : 'status'}>
            <Icon name={tone === 'ok' ? 'check-circle' : 'alert'} />
            <div>{title && <><b>{title}</b> </>}{children}</div>
        </div>
    );
}

export function Card({ title, actions, flush, children, className, bodyClassName }) {
    return (
        <section className={cx('ls-card', flush && 'ls-card--flush', className)}>
            {(title || actions) && (
                <div className="ls-card-head">
                    {title && <h2 className="ls-card-title">{title}</h2>}
                    {actions}
                </div>
            )}
            <div className={cx('ls-card-body', bodyClassName)}>{children}</div>
        </section>
    );
}

export function EmptyState({ illustration = 'quiet', title, text, children, className }) {
    return (
        <div className={cx('ls-empty', className)}>
            <Illustration name={illustration} />
            <h4>{title}</h4>
            {text && <p>{text}</p>}
            {children && <div className="ls-actions">{children}</div>}
        </div>
    );
}

export function Money({ amount, className }) {
    return <span className={cx('ls-num', className)}>{money(amount)}</span>;
}

export function PageHeader({ title, subtitle, eyebrow, count, actions, className }) {
    return (
        <header className={cx('ls-page-head', className)}>
            <div>
                {eyebrow && <div className="ls-eyebrow">{eyebrow}</div>}
                <h1 className="ls-title">{title}{count != null && <span className="ls-count">{count}</span>}</h1>
                {subtitle && <p className="ls-subtitle">{subtitle}</p>}
            </div>
            {actions && <div className="ls-actions">{actions}</div>}
        </header>
    );
}

export function Avatar({ name = '', size, className }) {
    const words = String(name).trim().split(/\s+/u).filter(Boolean);
    const initials = words.slice(0, 2).map((w) => Array.from(w)[0]).join('').toUpperCase();
    const tone = (crc32(String(name).trim().toLowerCase()) % 3) + 1;
    return (
        <span className={cx('ls-avatar', tone > 1 && `ls-avatar--t${tone}`, size && `ls-avatar--${size}`, className)} aria-hidden="true">
            {initials || '?'}
        </span>
    );
}

/** Labelled field wrapper with the server-side validation message. */
export function Field({ label, htmlFor, required, optional, hint, error, children, className }) {
    return (
        <div className={cx('ls-field', className)}>
            {label && (
                <label className="ls-label" htmlFor={htmlFor}>
                    {label}{required && <span className="ls-req">*</span>}{optional && <span className="ls-opt"> ({t('admin_platform.optional')})</span>}
                </label>
            )}
            {children}
            {hint && <span className="ls-hint">{hint}</span>}
            {error && <span className="ls-error"><Icon name="alert" />{error}</span>}
        </div>
    );
}

export function Input({ label, name, error, hint, required, optional, className, fieldClassName, id, ...rest }) {
    const auto = useId();
    const fid = id || `f-${name || auto}`;
    return (
        <Field label={label} htmlFor={fid} required={required} optional={optional} hint={hint} error={error} className={fieldClassName}>
            <input id={fid} name={name} required={required} className={cx('ls-input', error && 'is-invalid', className)} {...rest} />
        </Field>
    );
}

export function Select({ label, name, error, hint, required, optional, children, className, fieldClassName, id, ...rest }) {
    const auto = useId();
    const fid = id || `f-${name || auto}`;
    return (
        <Field label={label} htmlFor={fid} required={required} optional={optional} hint={hint} error={error} className={fieldClassName}>
            <select id={fid} name={name} required={required} className={cx('ls-select', error && 'is-invalid', className)} {...rest}>{children}</select>
        </Field>
    );
}

export function Textarea({ label, name, error, hint, required, optional, className, fieldClassName, id, ...rest }) {
    const auto = useId();
    const fid = id || `f-${name || auto}`;
    return (
        <Field label={label} htmlFor={fid} required={required} optional={optional} hint={hint} error={error} className={fieldClassName}>
            <textarea id={fid} name={name} required={required} className={cx('ls-textarea', error && 'is-invalid', className)} {...rest} />
        </Field>
    );
}

export function Password({ label, name, error, hint, required, id, className, ...rest }) {
    const [shown, setShown] = useState(false);
    const fid = id || `f-${name}`;
    return (
        <Field label={label} htmlFor={fid} required={required} hint={hint} error={error}>
            <div className="ls-password">
                <input id={fid} name={name} type={shown ? 'text' : 'password'} required={required} className={cx('ls-input', error && 'is-invalid', className)} {...rest} />
                <button type="button" className="ls-password-toggle" aria-pressed={shown} aria-label={t(shown ? 'password_field.hide' : 'password_field.show')} onClick={() => setShown((s) => !s)}>
                    <Icon name="eye" className="ls-password-eye" />
                    <Icon name="eye-off" className="ls-password-eye-off" />
                </button>
            </div>
        </Field>
    );
}

/** Client-side list filter — same as <x-ui.search>: hides [data-ls-item=group] rows not matching. */
export function SearchBox({ group, placeholder = '', width = '240px', value, onChange }) {
    return (
        <div className={cx('ls-search', value && 'has-value')} style={{ width, maxWidth: '100%' }}>
            <Icon name="search" />
            <input type="search" className="ls-input" placeholder={placeholder} data-ls-search={group} value={value} onChange={onChange} aria-label={placeholder || t('common.search')} />
        </div>
    );
}

/**
 * Modal/drawer with the exact ls-overlay markup; open/close go through
 * window.LS (focus trap, Esc, backdrop click, body scroll lock), and any
 * close initiated by panel.js is reported back through onClose.
 */
export function Modal({ open, onClose, title, subtitle, size, drawer, note, footer, children, className, id }) {
    const ref = useRef(null);
    const autoId = useId().replace(/:/g, '');
    const mid = id || `m-${autoId}`;
    useEffect(() => {
        const el = ref.current;
        if (!el || !window.LS) return;
        if (open) window.LS.open(el); else window.LS.close(el);
    }, [open]);
    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        const handler = () => onClose && onClose();
        el.addEventListener('ls:close', handler);
        return () => el.removeEventListener('ls:close', handler);
    }, [onClose]);
    useEffect(() => () => { if (ref.current && window.LS) window.LS.close(ref.current); }, []);
    return (
        <div ref={ref} id={mid} className={cx('ls-overlay', drawer && 'ls-overlay--drawer')} aria-hidden="true">
            <div className={cx('ls-dialog', size && `ls-dialog--${size}`, className)} role="dialog" aria-modal="true" aria-labelledby={`${mid}-title`}>
                <div className="ls-dialog-head">
                    <div>
                        <h3 className="ls-dialog-title" id={`${mid}-title`}>{title}</h3>
                        {subtitle && <div className="ls-dialog-sub">{subtitle}</div>}
                    </div>
                    <button type="button" className="ls-btn ls-btn--ghost ls-btn--sm ls-btn--icon" data-ls-close aria-label={t('common.close')}><Icon name="x" /></button>
                </div>
                <div className="ls-dialog-body">{children}</div>
                {note && <div className="ls-dialog-note"><Icon name="check-circle" /><span>{note}</span></div>}
                {footer && <div className="ls-dialog-foot">{footer}</div>}
            </div>
        </div>
    );
}

/**
 * Confirm-then-submit for destructive or important actions (replaces
 * confirm() / data-confirm). Sends an Inertia visit to `href` with `method`.
 */
export function ConfirmButton({ href, method = 'delete', data, title, message, confirmLabel, tone = 'danger', withReason, children, variant = 'danger-quiet', size = 'sm', className, preserveScroll = true, onSuccess, icon, iconOnly, ariaLabel }) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [reason, setReason] = useState('');
    const submit = () => {
        router.visit(href, {
            method, data: withReason ? { ...(data || {}), reason } : data, preserveScroll,
            onStart: () => setBusy(true),
            onFinish: () => { setBusy(false); setOpen(false); },
            onSuccess,
        });
    };
    return (
        <>
            <Button variant={variant} size={size} className={className} icon={icon} iconOnly={iconOnly} aria-label={ariaLabel} onClick={() => setOpen(true)}>{children}</Button>
            <Modal open={open} onClose={() => setOpen(false)} title={title || t('admin_platform.confirm_title')} size="narrow"
                footer={<>
                    <Button variant="secondary" onClick={() => setOpen(false)}>{t('common.cancel')}</Button>
                    <Button variant={tone === 'primary' ? 'primary' : 'danger'} processing={busy} onClick={submit}>{confirmLabel || t('common.confirm')}</Button>
                </>}>
                {message && <p className="ls-muted" style={{ margin: 0 }}>{message}</p>}
                {withReason && (
                    <Textarea label={t('admin_platform.reason')} optional name="reason" rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />
                )}
            </Modal>
        </>
    );
}

/** Laravel paginator links ({links:[{url,label,active}]}) → design-system pager, client-side. */
export function Pagination({ paginator, className }) {
    if (!paginator || !paginator.links || paginator.last_page <= 1) return null;
    return (
        <nav className={cx('ls-pager', className)} role="navigation" aria-label={t('admin_platform.pagination')}>
            {paginator.links.map((l, i) => {
                const label = i === 0 ? t('admin_platform.prev') : i === paginator.links.length - 1 ? t('admin_platform.next') : l.label;
                if (!l.url) return <span key={i} className={cx('ls-pager-btn', 'is-disabled', l.label === '...' && 'ls-pager-gap')} aria-disabled="true">{label}</span>;
                if (l.active) return <span key={i} className="ls-pager-btn is-current" aria-current="page">{label}</span>;
                return <Link key={i} href={l.url} preserveScroll className="ls-pager-btn">{label}</Link>;
            })}
        </nav>
    );
}

/** Sortable column header (keeps every other query param). */
export function SortHeader({ field, label, sort, dir, defaultDir = 'desc', className, url }) {
    const on = sort === field;
    const next = on ? (dir === 'asc' ? 'desc' : 'asc') : defaultDir;
    const href = withQuery(url || window.location.href, { sort: field, dir: next, page: null });
    return (
        <th scope="col" className={className} aria-sort={on ? (dir === 'asc' ? 'ascending' : 'descending') : undefined}>
            <Link href={href} preserveScroll preserveState className={cx('ls-sort', on && 'is-on')}>
                {label}<span aria-hidden="true">{on ? (dir === 'asc' ? '↑' : '↓') : '↕'}</span>
            </Link>
        </th>
    );
}

/** Current URL with some query params changed (null removes). */
export function withQuery(base, changes) {
    const u = new URL(base, window.location.origin);
    Object.entries(changes).forEach(([k, v]) => (v === null || v === undefined || v === '' ? u.searchParams.delete(k) : u.searchParams.set(k, v)));
    return u.pathname + (u.search || '');
}

export { cx };
