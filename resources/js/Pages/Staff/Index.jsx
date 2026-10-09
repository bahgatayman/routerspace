import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Avatar, Badge, Button, ConfirmButton, EmptyState, Icon, PageHeader, Pagination, withQuery } from '../../Components/ui';
import { t, tc } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

/** Disable asks first (signs the member out); enable posts straight away — same as the Blade form. */
function ToggleStatus({ member }) {
    const url = `/staff/${member.id}/toggle-status`;
    if (!member.is_active) {
        return <span className="ls-staff-toggle"><Button variant="tonal" size="sm" onClick={() => router.post(url, {}, { preserveScroll: true })}>{t('staff.enable')}</Button></span>;
    }
    return (
        <span className="ls-staff-toggle">
            <ConfirmButton href={url} method="post" message={t('staff.confirm_disable')} confirmLabel={t('staff.disable')}>{t('staff.disable')}</ConfirmButton>
        </span>
    );
}

export default function StaffIndex({ staff, search, status, counts }) {
    usePageTitle(t('section.staff'));
    const [q, setQ] = useState(search || '');
    const members = staff.data;

    const submitSearch = (e) => {
        e.preventDefault();
        router.get('/staff', { ...(status ? { status } : {}), search: q }, { preserveState: true, preserveScroll: true, replace: true });
    };

    return (
        <>
            <PageHeader title={t('section.staff')} count={counts.all} subtitle={t('staff.subtitle')}
                actions={<Button variant="primary" icon="plus" href="/staff/create">{t('staff.add_staff')}</Button>} />

            {counts.all > 0 && (
                <div className="ls-toolbar">
                    <nav className="ls-chips" aria-label={t('table.th.status')}>
                        {[['all', null], ['active', 'active'], ['disabled', 'disabled']].map(([key, value]) => {
                            const isOn = status === value;
                            return (
                                <Link key={key} href={withQuery(window.location.href, { status: value, page: null })} preserveScroll
                                    className={`ls-chip ${isOn ? 'is-active' : ''}`} aria-current={isOn ? 'page' : undefined}>
                                    {t('staff.filter_' + key)} <span className="ls-chip-count">{counts[key]}</span>
                                </Link>
                            );
                        })}
                    </nav>
                    <span className="ls-toolbar-spacer" />
                    <form onSubmit={submitSearch} className="ls-search ls-staff-search" role="search">
                        <Icon name="search" />
                        <input type="search" name="search" value={q} onChange={(e) => setQ(e.target.value)} className="ls-input"
                            placeholder={t('staff.search')} aria-label={t('staff.search')} autoComplete="off" />
                    </form>
                </div>
            )}

            {members.length > 0 ? (
                <>
                    <div className="ls-staff-grid">
                        {members.map((m) => (
                            <article key={m.id} className={`ls-staff ${m.is_active ? '' : 'is-disabled'}`} aria-labelledby={`staff-${m.id}-name`}>
                                <div className="ls-staff-head">
                                    <Avatar name={m.name} />
                                    <div className="ls-staff-who">
                                        <Link href={`/staff/${m.id}/edit`} className="ls-staff-name ls-trunc" id={`staff-${m.id}-name`}>{m.name}</Link>
                                        <bdi dir="ltr" className="ls-staff-email ls-trunc" title={m.email}>{m.email}</bdi>
                                    </div>
                                    {m.is_active
                                        ? <span className="ls-status"><span className="ls-dot" />{t('status.active')}</span>
                                        : <Badge tone="neutral" dot={false}>{t('staff.disabled')}</Badge>}
                                </div>

                                <dl className="ls-staff-facts">
                                    <div>
                                        <dt><Icon name="lock" /><span className="ls-sr">{t('staff.role')}</span></dt>
                                        <dd>
                                            <span className={`ls-staff-role ${m.has_role ? '' : 'is-custom'}`}>{m.role_label}</span>
                                            <span className="ls-faint"> · {tc('staff.permissions_count', m.permissions_count, { count: m.permissions_count })}</span>
                                        </dd>
                                    </div>
                                    <div>
                                        <dt><Icon name="clock" /><span className="ls-sr">{t('staff.last_login')}</span></dt>
                                        <dd className={m.last_login_ago ? '' : 'ls-faint'} title={m.last_login_title || undefined}>
                                            {m.last_login_ago ? t('staff.last_seen', { time: m.last_login_ago }) : t('staff.never_logged_in')}
                                        </dd>
                                    </div>
                                </dl>

                                <div className="ls-staff-foot">
                                    <Button size="sm" href={`/staff/${m.id}/edit`}>{t('common.edit')}</Button>
                                    <Button variant="ghost" size="sm" href={`/staff/${m.id}/activity`}>{t('staff.view_activity')}</Button>
                                    <ToggleStatus member={m} />
                                </div>
                            </article>
                        ))}
                    </div>
                    <div className="mt-6"><Pagination paginator={staff} /></div>
                </>
            ) : (search || status) ? (
                <div className="ls-card">
                    <EmptyState illustration="search" title={search ? t('staff.no_match', { q: search }) : t('staff.no_staff')} text={t('staff.no_match_hint')}>
                        <Button href="/staff">{t('staff.clear_search')}</Button>
                    </EmptyState>
                </div>
            ) : (
                <div className="ls-card">
                    <EmptyState illustration="people" title={t('staff.no_staff')} text={t('staff.empty_hint')}>
                        <Button variant="primary" icon="plus" href="/staff/create">{t('staff.add_staff')}</Button>
                    </EmptyState>
                </div>
            )}
        </>
    );
}
