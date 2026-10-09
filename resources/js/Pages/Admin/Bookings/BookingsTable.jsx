/*
 * Admin bookings table (port of resources/views/admin/bookings/_table.blade.php):
 * platform bookings list, room detail, business bookings tab.
 * Rows come from Admin\BookingController::tableRow(). `hide` can contain
 * 'workspace' and/or 'room'. Columns are sortable when `sort` is given.
 */
import { Link } from '@inertiajs/react';
import rowLink from '../../../Components/Admin/rowLink';
import { Badge, EmptyState, SortHeader } from '../../../Components/ui';
import { money } from '../../../lib/format';
import { t } from '../../../lib/i18n';

export const STATUS_TONE = { completed: 'ok', confirmed: 'info', checked_in: 'info', open: 'info', pending: 'warn', cancelled: 'danger', no_show: 'neutral' };

export default function BookingsTable({ bookings, hide = [], sort, dir }) {
    const sortable = sort !== undefined && sort !== null;
    const head = (key, label, className) => (sortable
        ? <SortHeader field={key} label={label} sort={sort} dir={dir} className={className} />
        : <th scope="col" className={className}>{label}</th>);

    if (!bookings || bookings.length === 0) return <EmptyState title={t('admin_platform.no_bookings')} />;

    return (
        <div className="ls-table-wrap">
            <table className="ls-table ls-adm-table">
                <thead><tr>
                    {head('id', '#')}
                    {head('date', t('admin_platform.col.date'))}
                    <th scope="col">{t('admin_platform.col.customer')}</th>
                    {!hide.includes('workspace') && <th scope="col">{t('admin_platform.col.workspace')}</th>}
                    {!hide.includes('room') && <th scope="col">{t('admin_platform.col.room')}</th>}
                    <th scope="col">{t('common.status')}</th>
                    <th scope="col">{t('admin_platform.col.payment')}</th>
                    {head('amount', t('admin_platform.col.net'), 'is-num')}
                    {head('paid', t('admin_platform.col.paid'), 'is-num')}
                </tr></thead>
                <tbody>
                    {bookings.map((b) => (
                        <tr key={b.id} {...rowLink(`/admin/bookings/${b.id}`)}>
                            <td><Link href={`/admin/bookings/${b.id}`} className="ls-link ls-num">#{b.id}</Link></td>
                            <td className="ls-nowrap"><span className="ls-num">{b.date}</span><small className="ls-adm-sub">{b.time}</small></td>
                            <td>
                                {b.customer ?? t('admin_biz.deleted_member')}
                                {b.phone && <small className="ls-adm-sub"><bdi dir="ltr">{b.phone}</bdi></small>}
                            </td>
                            {!hide.includes('workspace') && <td><Link href={`/admin/owners/${b.owner_id}`} className="ls-link">{b.business ?? '—'}</Link></td>}
                            {!hide.includes('room') && (
                                <td>{b.room ?? '—'}{b.location && <small className="ls-adm-sub">{b.location}</small>}</td>
                            )}
                            <td>
                                <Badge tone={STATUS_TONE[b.status] || 'neutral'}>{t(`admin_platform.status.${b.status}`)}</Badge>
                                {b.is_session && <small className="ls-adm-sub">{t('admin_platform.types.session')}</small>}
                            </td>
                            <td><Badge tone={b.payment_tone} dot={false}>{b.payment_label}</Badge></td>
                            <td className="is-money">{money(b.net)}</td>
                            <td className="is-money">{money(b.paid)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
