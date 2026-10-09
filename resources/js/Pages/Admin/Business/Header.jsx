/*
 * Business 360° header — shared by every tab of one business (Owner = tenant).
 * `business` comes from Admin\Concerns\BusinessHeaderProps::businessHeader();
 * `workspaceId` is the (already owner-scoped) location filter, `locationAware`
 * says whether this tab honours it.
 */
import { Link, usePage } from '@inertiajs/react';
import { Avatar, Badge, Button, ConfirmButton, cx } from '../../../Components/ui';
import { t } from '../../../lib/i18n';

export default function BusinessHeader({ business: b, active, workspaceId = null, locationAware = false }) {
    const { url } = usePage();
    const path = url.split('?')[0];
    const base = `/admin/owners/${b.id}`;
    const tabs = [
        ['overview', base],
        ['products', `${base}/products`],
        ['rooms', `${base}/rooms`],
        ['bookings', `${base}/bookings`],
        ['financials', `${base}/financials`],
        ['members', `${base}/users`],
        ['subscription', `${base}/subscription`],
        ['activity', `${base}/activity`],
        ['audit', `${base}/audit`],
    ];

    return (
        <div className="ls-biz">
            <Link href="/admin/workspaces" className="ls-biz-back">&larr; {t('admin_biz.all_businesses')}</Link>

            <header className="ls-biz-head">
                <Avatar name={b.name} size="lg" />
                <div className="ls-biz-id">
                    <h1 className="ls-biz-name">
                        {b.name}{' '}
                        <Badge tone={b.status_tone}>{t(`admin_biz.status.${b.status}`)}</Badge>
                    </h1>
                    <p className="ls-biz-meta">
                        <span>{b.owner_name}</span> ·{' '}
                        <bdi dir="ltr">{b.email}</bdi> ·{' '}
                        <span>{b.plan || t('admin_biz.no_plan')}</span>
                        {b.expires && <> · <span>{t('admin_biz.expires', { date: b.expires })}</span></>}
                        {' '}· <span>{t('admin_biz.joined', { date: b.joined })}</span>
                    </p>
                </div>
                <div className="ls-biz-actions">
                    <Button size="sm" href={`${base}/subscription`}>{t('admin_biz.renew_plan')}</Button>
                    {/* Suspend / activate: confirmed, with an optional reason kept in the audit log. */}
                    <ConfirmButton href={`${base}/toggle-active`} method="put" withReason
                        variant={b.is_active ? 'danger-quiet' : 'primary'}
                        tone={b.is_active ? 'danger' : 'primary'}
                        message={b.is_active ? t('admin_biz.suspend_prompt') : t('admin_biz.activate_prompt')}
                        confirmLabel={b.is_active ? t('admin_biz.suspend') : t('admin_biz.activate')}>
                        {b.is_active ? t('admin_biz.suspend') : t('admin_biz.activate')}
                    </ConfirmButton>
                </div>
            </header>

            {locationAware && b.locations.length > 1 && (
                // Location filter: rooms / bookings / sessions / room revenue. Everything else is business-wide.
                <nav className="ls-chips ls-biz-locations" aria-label={t('admin_biz.location')}>
                    <Link href={path} preserveScroll className={cx('ls-chip', !workspaceId && 'is-active')}>{t('admin_biz.all_locations')}</Link>
                    {b.locations.map((ws) => (
                        <Link key={ws.id} href={`${path}?workspace=${ws.id}`} preserveScroll className={cx('ls-chip', workspaceId === ws.id && 'is-active')}>{ws.name}</Link>
                    ))}
                </nav>
            )}

            <nav className="ls-biz-tabs" aria-label={t('admin_biz.sections')}>
                {tabs.map(([key, href]) => (
                    <Link key={key} href={href} className={cx('ls-biz-tab', active === key && 'is-active')} aria-current={active === key ? 'page' : undefined}>
                        {t(`admin_biz.tabs.${key}`)}
                    </Link>
                ))}
            </nav>
        </div>
    );
}
