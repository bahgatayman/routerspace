import { Link, router } from '@inertiajs/react';
import { useEffect } from 'react';
import { Badge, Button, ConfirmButton, EmptyState, Icon, PageHeader, SearchBox, withQuery } from '../../Components/ui';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

const KEY = 'ls.lastWorkspaceId';
const TYPES = ['meeting', 'training', 'shared', 'office', 'studio'];

const remember = (id) => { try { localStorage.setItem(KEY, String(id)); } catch (e) { /* storage unavailable */ } };

/** Port of workspaces/rooms/_card. Room edit pages stay Blade → plain <a>. */
function RoomCard({ room }) {
    return (
        <article className={`ls-product ${room.is_available ? '' : 'is-inactive'}`} aria-labelledby={`room-${room.id}-name`}
            data-ls-item="rooms" data-ls-text={`${room.name} ${room.type_label}`}>
            <div className="ls-product-top">
                <span className="ls-product-icon is-product" aria-hidden="true"><Icon name="building" /></span>
                {room.status_key === 'available' && <span className="ls-status"><span className="ls-dot" />{t('workspace.available')}</span>}
                {room.status_key === 'occupied' && <Badge tone="info">{room.status_label}</Badge>}
                {room.status_key === 'unavailable' && <Badge tone="neutral" dot={false}>{t('workspace.unavailable')}</Badge>}
            </div>

            <div className="ls-product-body">
                <a href={room.edit_url} className="ls-product-name ls-trunc" id={`room-${room.id}-name`} title={room.name}>{room.name}</a>
                <div className="ls-product-meta">
                    <span>{room.type_label}</span>
                    <span aria-hidden="true">·</span>
                    <span>{room.capacity} {t('workspace.seats')}</span>
                </div>
            </div>

            <div className="ls-product-price">
                {room.pricing_summary}
                {room.plans_label && <a href={`${room.edit_url}#plans-title`} className="ls-plan-tag">{room.plans_label}</a>}
                {room.profiles_label && <a href={`${room.edit_url}#profiles-title`} className="ls-plan-tag">{room.profiles_label}</a>}
            </div>

            {room.description && <p className="ls-faint ls-trunc" style={{ fontSize: 13 }}>{room.description}</p>}

            <div className="ls-product-foot">
                <Button size="sm" href={room.edit_url} native>{t('common.edit')}</Button>
                <Button variant="ghost" size="sm" onClick={() => router.post(room.toggle_url, {}, { preserveScroll: true })}>
                    {room.is_available ? t('workspace.mark_unavailable') : t('workspace.mark_available')}
                </Button>
                <div className="ls-product-delete">
                    <ConfirmButton href={room.destroy_url} method="delete" message={t('workspace.delete_room_confirm')} confirmLabel={t('common.delete')}
                        variant="danger-quiet" icon="trash" iconOnly ariaLabel={`${t('common.delete')}: ${room.name}`} />
                </div>
            </div>
        </article>
    );
}

export default function WorkspacesIndex({ workspaces, activeWorkspace, rooms, roomStats, type, createWorkspaceUrl }) {
    usePageTitle(t('workspace.rooms_title'));

    // Several locations: reopen the last one picked (remembered per browser) when no ?workspace= is given.
    useEffect(() => {
        if (workspaces.length < 2 || !activeWorkspace) return;
        const params = new URLSearchParams(window.location.search);
        if (params.has('workspace')) return;
        let saved = null;
        try { saved = parseInt(localStorage.getItem(KEY) || '', 10); } catch (e) { saved = null; }
        if (saved && saved !== activeWorkspace.id && workspaces.some((ws) => ws.id === saved)) {
            router.get(withQuery(window.location.href, { workspace: saved }), {}, { replace: true, preserveScroll: true });
        }
    }, []);

    return (
        <>
            <PageHeader title={t('workspace.rooms_title')}
                subtitle={activeWorkspace ? t('workspace.rooms_subtitle', { workspace: activeWorkspace.name }) : null}
                count={roomStats ? roomStats.total : null}
                actions={activeWorkspace && (
                    <Button variant="primary" icon="plus" href={activeWorkspace.create_room_url} native>{t('workspace.add_room')}</Button>
                )} />

            {workspaces.length > 1 && (
                <nav className="ls-chips is-scroll" style={{ marginBottom: 'var(--space-5)' }} aria-label={t('workspace.my_workspaces')}>
                    {workspaces.map((ws) => {
                        const on = activeWorkspace.id === ws.id;
                        return (
                            <Link key={ws.id} href={ws.url} className={`ls-chip ${on ? 'is-active' : ''}`} data-ls-remember-workspace={ws.id}
                                aria-current={on ? 'page' : undefined} onClick={() => remember(ws.id)}>
                                {ws.name} <span className="ls-chip-count">{ws.rooms_count}</span>
                            </Link>
                        );
                    })}
                </nav>
            )}

            {workspaces.length === 0 ? (
                // The only case where creating a workspace is the page's primary content.
                <div className="ls-card">
                    <EmptyState illustration="box" title={t('workspace.create_workspace')} text={t('empty.no_workspaces')}>
                        <Button variant="primary" icon="plus" href={createWorkspaceUrl}>{t('btn.create_workspace')}</Button>
                    </EmptyState>
                </div>
            ) : (
                <>
                    <div className="ls-strip">
                        <div>
                            <span className="ls-strip-label">{t('workspace.total_rooms')}</span>
                            <span className="ls-strip-value is-brand">{roomStats.total}</span>
                        </div>
                        <div>
                            <span className="ls-strip-label">{t('workspace.available')}</span>
                            <span className="ls-strip-value">{roomStats.available}</span>
                        </div>
                        <div>
                            <span className="ls-strip-label">{t('workspace.occupied')}</span>
                            <span className="ls-strip-value">{roomStats.occupied}</span>
                        </div>
                        {roomStats.unavailable > 0 && (
                            <div className="ls-hide-sm">
                                <span className="ls-strip-label">{t('workspace.unavailable')}</span>
                                <span className="ls-strip-value is-warn">{roomStats.unavailable}</span>
                            </div>
                        )}
                    </div>

                    <div className="ls-toolbar" style={{ marginBottom: 'var(--space-5)' }}>
                        <nav className="ls-chips is-scroll" aria-label={t('workspace.type')}>
                            <Link href={withQuery(window.location.href, { type: null })} preserveScroll className={`ls-chip ${!type ? 'is-active' : ''}`} aria-current={!type ? 'page' : undefined}>
                                {t('inventory.filter_all')}
                            </Link>
                            {TYPES.map((ty) => (
                                <Link key={ty} href={withQuery(window.location.href, { type: ty })} preserveScroll className={`ls-chip ${type === ty ? 'is-active' : ''}`} aria-current={type === ty ? 'page' : undefined}>
                                    {t(`room_type.${ty}`)}
                                </Link>
                            ))}
                        </nav>
                        <div className="ls-toolbar-spacer" />
                        <SearchBox key={`${activeWorkspace.id}-${type || ''}`} group="rooms" placeholder={t('ui.search_rooms')} />
                    </div>

                    {rooms.length === 0 ? (
                        <div className="ls-card">
                            <EmptyState illustration="box" title={t('empty.no_rooms')} text={t('workspace.no_rooms_hint')}>
                                <Button variant="primary" icon="plus" href={activeWorkspace.create_room_url} native>{t('workspace.add_room')}</Button>
                            </EmptyState>
                        </div>
                    ) : (
                        <>
                            <div className="ls-product-grid">
                                {rooms.map((room) => <RoomCard key={room.id} room={room} />)}
                            </div>
                            <div className="ls-card" data-ls-empty="rooms" hidden>
                                <EmptyState illustration="search" title={t('ui.no_rooms_match_title')} text={t('ui.no_rooms_match_text')} />
                            </div>
                        </>
                    )}
                </>
            )}
        </>
    );
}
