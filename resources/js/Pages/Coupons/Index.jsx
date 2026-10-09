import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { AppliesToFields, BasicFields, ErrorList, RestrictionFields, couponFormDefaults } from '../../Components/Coupons/CouponFields';
import { Badge, ConfirmButton, Icon, Modal, Pagination } from '../../Components/ui';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

// Full literal class strings so Tailwind always finds them.
const ACCENT = {
    ok: { stripe: 'bg-green-400', chip: 'bg-green-50', icon: 'text-green-600', value: 'text-green-600' },
    info: { stripe: 'bg-blue-400', chip: 'bg-blue-50', icon: 'text-blue-600', value: 'text-blue-600' },
    warn: { stripe: 'bg-amber-400', chip: 'bg-amber-50', icon: 'text-amber-600', value: 'text-amber-600' },
    danger: { stripe: 'bg-red-400', chip: 'bg-red-50', icon: 'text-red-600', value: 'text-red-600' },
    neutral: { stripe: 'bg-gray-300', chip: 'bg-gray-100', icon: 'text-gray-500', value: 'text-gray-700' },
};
const SCOPE_ICON = { rooms: 'building', products: 'box' };
const STATUSES = ['active', 'inactive', 'scheduled', 'expired', 'limit_reached'];

export default function CouponsIndex({ coupons, search, status, canCreate, canEdit, canDelete, newCoupon, roomGroups, productGroups }) {
    usePageTitle(t('coupons.title'));
    const [createOpen, setCreateOpen] = useState(false);
    const [q, setQ] = useState(search || '');
    const [st, setSt] = useState(status || '');
    const items = coupons.data;

    const applyFilters = (e) => {
        e.preventDefault();
        router.get('/coupons', { search: q, status: st }, { preserveScroll: true });
    };

    return (
        <>
            <div className="flex flex-wrap items-center justify-between gap-3 mb-6">
                <h1 className="text-2xl font-bold text-gray-900">{t('coupons.title')}</h1>
                {canCreate && (
                    <button type="button" onClick={() => setCreateOpen(true)} className="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
                        <Icon name="plus" className="w-4 h-4" />
                        {t('coupons.add')}
                    </button>
                )}
            </div>

            <form onSubmit={applyFilters} className="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-6 flex flex-wrap items-end gap-3">
                <div className="flex-1 min-w-[180px]">
                    <label className="block text-xs text-gray-500 mb-1">{t('common.search')}</label>
                    <input type="text" name="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('coupons.search_placeholder')}
                        className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" dir="ltr" />
                </div>
                <div>
                    <label className="block text-xs text-gray-500 mb-1">{t('common.status')}</label>
                    <select name="status" value={st} onChange={(e) => setSt(e.target.value)} className="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">{t('coupons.filter_all')}</option>
                        {STATUSES.map((key) => <option key={key} value={key}>{t(`coupons.status_${key}`)}</option>)}
                    </select>
                </div>
                <button type="submit" className="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition text-sm font-medium">{t('common.search')}</button>
            </form>

            {items.length === 0 ? (
                <div className="text-center py-16 bg-white rounded-xl border border-gray-100">
                    <p className="text-gray-500 text-sm">{search || status ? t('coupons.no_match') : t('coupons.empty')}</p>
                </div>
            ) : (
                <>
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        {items.map((c) => <CouponCard key={c.id} coupon={c} canEdit={canEdit} canDelete={canDelete} />)}
                    </div>
                    <div className="mt-6"><Pagination paginator={coupons} /></div>
                </>
            )}

            {canCreate && newCoupon && (
                <QuickCreateModal open={createOpen} setOpen={setCreateOpen} coupon={newCoupon} roomGroups={roomGroups} productGroups={productGroups} />
            )}
        </>
    );
}

function CouponCard({ coupon: c, canEdit, canDelete }) {
    const accent = ACCENT[c.status_tone] || ACCENT.neutral;
    const muted = ['inactive', 'expired', 'limit_reached'].includes(c.status_key);
    const pct = c.usage_percent;
    const bar = pct >= 100 ? 'bg-red-500' : pct >= 75 ? 'bg-amber-500' : 'bg-blue-500';

    return (
        <div className={`bg-white rounded-xl shadow-sm border border-gray-100 border-s-4 ${accent.stripe} overflow-hidden flex flex-col ${muted ? 'opacity-70' : ''}`}>
            <div className="p-5 flex flex-col gap-4 flex-1">
                <div className="flex items-start justify-between gap-2">
                    <div className="flex items-center gap-3 min-w-0">
                        <div className={`w-10 h-10 rounded-lg ${accent.chip} flex items-center justify-center shrink-0`}>
                            <Icon name="tag" className={`w-4 h-4 ${accent.icon}`} />
                        </div>
                        <div className="min-w-0">
                            <p className="font-mono font-semibold text-gray-900 truncate" dir="ltr" title={c.code}>{c.code}</p>
                            <p className={`text-lg font-bold ${accent.value} leading-tight`}>{c.discount_label}</p>
                        </div>
                    </div>
                    <Badge tone={c.status_tone}>{c.status_label}</Badge>
                </div>

                <dl className="text-sm text-gray-600 space-y-2.5 border-t border-gray-100 pt-3">
                    <div className="flex items-center gap-2">
                        <Icon name={SCOPE_ICON[c.applies_to] || 'tag'} className="w-4 h-4 text-gray-400 shrink-0" />
                        <dt className="sr-only">{t('coupons.col_applies_to')}</dt>
                        <dd className="truncate">{c.scope_label}</dd>
                    </div>
                    <div>
                        <div className="flex items-center gap-2">
                            <Icon name="users" className="w-4 h-4 text-gray-400 shrink-0" />
                            <dt className="sr-only">{t('coupons.col_usage')}</dt>
                            <dd>{c.usage_label}</dd>
                        </div>
                        {pct !== null && (
                            <div className="h-1.5 bg-gray-100 rounded-full overflow-hidden mt-1.5 ms-6">
                                <div className={`h-full rounded-full ${bar}`} style={{ width: `${pct}%` }} />
                            </div>
                        )}
                    </div>
                    <div className="flex items-center gap-2">
                        <Icon name="calendar" className="w-4 h-4 text-gray-400 shrink-0" />
                        <dt className="sr-only">{t('coupons.col_valid_until')}</dt>
                        <dd className="whitespace-nowrap">{c.expires ?? t('coupons.no_expiry')}</dd>
                    </div>
                </dl>

                <div className="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-3 mt-auto">
                    {canEdit && (
                        <>
                            <Link href={`/coupons/${c.id}/edit`} className="px-3 py-1.5 rounded-md text-xs font-medium text-blue-600 bg-blue-50 hover:bg-blue-100 transition">{t('common.edit')}</Link>
                            <button type="button" onClick={() => router.post(`/coupons/${c.id}/toggle`, {}, { preserveScroll: true })}
                                className="px-3 py-1.5 rounded-md text-xs font-medium text-gray-600 bg-gray-50 hover:bg-gray-100 transition">
                                {c.is_active ? t('coupons.deactivate') : t('coupons.activate')}
                            </button>
                        </>
                    )}
                    {canDelete && (
                        <ConfirmButton href={`/coupons/${c.id}`} method="delete" message={t('coupons.delete_confirm')} confirmLabel={t('common.delete')}>
                            {t('common.delete')}
                        </ConfirmButton>
                    )}
                </div>
            </div>
        </div>
    );
}

/** Quick create (coupons/_quick-create-modal): same fields + a display-only summary preview. */
function QuickCreateModal({ open, setOpen, coupon, roomGroups, productGroups }) {
    const form = useForm({ ...couponFormDefaults(coupon), is_active: true });
    const { data } = form;

    const submit = (e) => {
        e.preventDefault();
        form.post('/coupons', { preserveScroll: true, onSuccess: () => { form.reset(); setOpen(false); } });
    };

    const code = String(data.code || '').trim().toUpperCase();
    const value = parseFloat(data.discount_value || '0');
    const shown = Number.isNaN(value) ? '0' : value;
    const amount = data.discount_type === 'fixed' ? `ج.م ${shown}` : `${shown}%`;
    const scopeLabel = data.applies_to ? t(`coupons.${data.applies_to}`) : '';

    return (
        <Modal id="create-coupon" open={open} onClose={() => setOpen(false)} size="wide" title={t('coupons.add')} subtitle={t('coupons.subtitle')}
            footer={<>
                <button type="button" onClick={() => setOpen(false)} className="px-4 py-2 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-100 transition">{t('common.cancel')}</button>
                <button type="submit" form="create-coupon-form" disabled={form.processing} className="bg-blue-600 text-white px-5 py-2.5 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">{t('common.save')}</button>
            </>}>
            <div className="flex items-center gap-3 bg-blue-50 border border-blue-100 rounded-lg px-4 py-3">
                <div className="w-9 h-9 rounded-lg bg-blue-600 flex items-center justify-center shrink-0">
                    <Icon name="tag" className="w-4 h-4 text-white" />
                </div>
                <div className="min-w-0">
                    <p className="font-mono font-semibold text-gray-900 truncate" dir="ltr">{code || t('coupons.code')}</p>
                    <p className="text-xs text-gray-500">{amount} {t('coupons.checkout.discount')}{scopeLabel ? ` · ${scopeLabel}` : ''}</p>
                </div>
            </div>

            <form id="create-coupon-form" onSubmit={submit} className="space-y-5 mt-5">
                <div>
                    <p className="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">{t('coupons.section_basic')}</p>
                    <BasicFields form={form} />
                </div>
                <div>
                    <p className="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">{t('coupons.section_applies_to')}</p>
                    <AppliesToFields form={form} roomGroups={roomGroups} productGroups={productGroups} />
                </div>
                <details className="border-t border-gray-100 pt-4 group">
                    <summary className="flex items-center gap-1.5 cursor-pointer text-sm font-medium text-gray-700 select-none list-none">
                        <Icon name="chevron-right" className="w-4 h-4 text-gray-400 transition group-open:rotate-90" />
                        {t('coupons.advanced_options')}
                    </summary>
                    <div className="mt-4"><RestrictionFields form={form} /></div>
                </details>
            </form>

            <ErrorList errors={form.errors} />
        </Modal>
    );
}
