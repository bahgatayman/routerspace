import { Link } from '@inertiajs/react';
import { Badge, Icon, cx } from '../../../Components/ui';
import { money } from '../../../lib/format';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';
import BusinessHeader from './Header';

export default function BusinessOverview({ business, workspace, maxMembers, summary, health, activity, locations }) {
    usePageTitle(business.name);
    const s = summary;
    const cards = [
        { label: t('admin_biz.cards.locations'), value: s.locations, scope: 'business' },
        { label: t('admin_biz.cards.rooms'), value: s.rooms, scope: 'location' },
        { label: t('admin_biz.cards.members'), value: s.members + (maxMembers ? ` / ${maxMembers}` : ''), scope: 'business' },
        { label: t('admin_biz.cards.staff'), value: s.staff, scope: 'business' },
        { label: t('admin_biz.cards.active_sessions'), value: s.active_sessions, scope: 'location', tone: s.active_sessions ? 'brand' : null },
        { label: t('admin_biz.cards.bookings_today'), value: s.bookings_today, scope: 'location' },
        { label: t('admin_biz.cards.revenue_today'), value: money(s.revenue_today), scope: 'location', tone: 'revenue' },
        { label: t('admin_biz.cards.revenue_month'), value: money(s.revenue_month), scope: 'location', tone: 'revenue' },
        { label: t('admin_biz.cards.expenses_month'), value: money(s.expenses_month), scope: 'business' },
        { label: t('admin_biz.cards.outstanding'), value: money(s.outstanding), scope: 'location', tone: s.outstanding > 0 ? 'warn' : null },
    ];

    return (
        <div className="ls-biz-page">
            <BusinessHeader business={business} active="overview" workspaceId={workspace?.id ?? null} locationAware />

            {workspace && <p className="ls-biz-note">{t('admin_biz.location_note', { name: workspace.name })}</p>}

            <section className="ls-biz-cards" aria-label={t('admin_biz.tabs.overview')}>
                {cards.map((card) => (
                    <div key={card.label} className="ls-biz-card">
                        <span className="ls-biz-card-label">
                            {card.label}
                            {workspace && card.scope === 'business' && <small> · {t('admin_biz.business_wide')}</small>}
                        </span>
                        <b className={cx('ls-biz-card-value', card.tone && `is-${card.tone}`)}>{card.value}</b>
                    </div>
                ))}
            </section>

            <div className="ls-biz-cols">
                <section className="ls-card ls-biz-panel" aria-labelledby="health-title">
                    <div className="ls-card-head"><h2 className="ls-card-title" id="health-title">{t('admin_biz.health_title')}</h2></div>
                    <div className="ls-card-body">
                        {health.length === 0 ? (
                            <p className="ls-biz-healthy"><Icon name="check-circle" /> {t('admin_biz.healthy')}</p>
                        ) : (
                            <ul className="ls-biz-health">
                                {health.map((item) => (
                                    <li key={item.key} className={`is-${item.level}`} data-health={item.key}>
                                        <i className="ls-biz-dot" aria-hidden="true" />
                                        <span>{item.text}</span>
                                        {item.url && <Link href={item.url} className="ls-link">{t('admin_biz.open')}</Link>}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </section>

                <section className="ls-card ls-biz-panel" aria-labelledby="activity-title">
                    <div className="ls-card-head">
                        <h2 className="ls-card-title" id="activity-title">{t('admin_biz.recent_activity')}</h2>
                        <Link href={`/admin/owners/${business.id}/audit`} className="ls-link">{t('admin_biz.admin_actions')}</Link>
                    </div>
                    <div className="ls-card-body">
                        {activity.length === 0 ? (
                            <p className="ls-faint">{t('admin_biz.no_activity')}</p>
                        ) : (
                            <ol className="ls-biz-feed">
                                {activity.map((a, i) => (
                                    <li key={i} className={`is-${a.kind}`}>
                                        <time dateTime={a.at_iso || undefined}>{a.at}</time>
                                        <span><b>{a.actor}</b> — {a.text}</span>
                                        {a.url && <Link href={a.url} className="ls-link">{t('admin_biz.open')}</Link>}
                                    </li>
                                ))}
                            </ol>
                        )}
                    </div>
                </section>
            </div>

            {locations.length > 0 && (
                <section className="ls-card ls-biz-panel" aria-labelledby="locations-title">
                    <div className="ls-card-head"><h2 className="ls-card-title" id="locations-title">{t('admin_biz.locations_title')}</h2></div>
                    <div className="ls-card-body">
                        <ul className="ls-biz-locs">
                            {locations.map((ws) => (
                                <li key={ws.id}>
                                    <Link href={`/admin/locations/${ws.id}`} className="ls-link">{ws.name}</Link>
                                    <span className="ls-faint">{ws.details}</span>
                                    {!ws.is_active && <Badge tone="neutral" dot={false}>{t('status.inactive')}</Badge>}
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>
            )}
        </div>
    );
}
