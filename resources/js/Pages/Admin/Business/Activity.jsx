import { Link } from '@inertiajs/react';
import { EmptyState, Pagination, cx } from '../../../Components/ui';
import { t } from '../../../lib/i18n';
import { usePageTitle } from '../../../lib/pageTitle';
import BusinessHeader from './Header';

export default function BusinessActivity({ business, actor, actorChips, logs }) {
    usePageTitle(business.name, t('admin_biz.tabs.activity'));

    return (
        <div className="ls-biz-page">
            <BusinessHeader business={business} active="activity" />

            <nav className="ls-chips" aria-label={t('admin_platform.actor')}>
                {actorChips.map((c) => (
                    <Link key={c.key || 'all'} href={c.href} preserveScroll className={cx('ls-chip', actor === c.key && 'is-active')}>{c.label}</Link>
                ))}
            </nav>

            <section className="ls-card">
                <div className="ls-card-body">
                    {logs.data.length === 0 ? (
                        <EmptyState title={t('admin_biz.no_activity')} />
                    ) : (
                        <>
                            <ol className="ls-biz-feed">
                                {logs.data.map((l) => (
                                    <li key={l.id} className={`is-${l.kind}`}>
                                        <time dateTime={l.at_iso || undefined}>{l.at}</time>
                                        <span><b>{l.actor}</b> — {l.text}</span>
                                        {l.url ? <Link href={l.url} className="ls-link">{t('admin_biz.open')}</Link> : <span />}
                                    </li>
                                ))}
                            </ol>
                            <div className="ls-adm-pager" style={{ paddingInline: 0 }}><span /><Pagination paginator={logs} /></div>
                        </>
                    )}
                </div>
            </section>
        </div>
    );
}
