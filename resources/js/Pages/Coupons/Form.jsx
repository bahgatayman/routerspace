import { Link, useForm } from '@inertiajs/react';
import { AppliesToFields, BasicFields, ErrorList, RestrictionFields, couponFormDefaults } from '../../Components/Coupons/CouponFields';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

const SECTION = 'bg-white rounded-xl shadow-sm border border-gray-100 p-6';

/** /coupons/create and /coupons/{id}/edit. */
export default function CouponForm({ coupon, roomGroups, productGroups }) {
    const isEdit = !!coupon.id;
    const title = isEdit ? t('coupons.edit') : t('coupons.add');
    usePageTitle(title);
    const form = useForm(couponFormDefaults(coupon));

    const submit = (e) => {
        e.preventDefault();
        if (isEdit) form.put(`/coupons/${coupon.id}`); else form.post('/coupons');
    };

    return (
        <>
            <div className="flex items-center justify-between mb-6">
                <h1 className="text-2xl font-bold text-gray-900">{title}</h1>
                <Link href="/coupons" className="text-sm text-gray-500 hover:text-gray-700">&larr; {t('common.back')}</Link>
            </div>

            <ErrorList errors={form.errors} className="mb-4" />

            <form onSubmit={submit} className="max-w-3xl">
                <div className={`${SECTION} space-y-6`}>
                    <h3 className="font-semibold text-gray-900">{t('coupons.section_basic')}</h3>
                    <BasicFields form={form} />
                </div>

                <div className={`${SECTION} space-y-4 mt-6`}>
                    <h3 className="font-semibold text-gray-900">{t('coupons.section_applies_to')}</h3>
                    <AppliesToFields form={form} roomGroups={roomGroups} productGroups={productGroups} />
                </div>

                <div className={`${SECTION} space-y-4 mt-6`}>
                    <h3 className="font-semibold text-gray-900">{t('coupons.section_restrictions')}</h3>
                    <RestrictionFields form={form} />
                </div>

                <div className={`${SECTION} mt-6`}>
                    <h3 className="font-semibold text-gray-900 mb-3">{t('coupons.section_status')}</h3>
                    <label className="inline-flex items-center gap-2 text-sm">
                        <input type="checkbox" name="is_active" value="1" checked={!!form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                        {t('coupons.is_active')}
                    </label>
                </div>

                <div className="flex gap-3 mt-6">
                    <button type="submit" disabled={form.processing} className="bg-blue-600 text-white px-5 py-2.5 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">{t('common.save')}</button>
                    <Link href="/coupons" className="px-5 py-2.5 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-100 transition">{t('common.cancel')}</Link>
                </div>
            </form>
        </>
    );
}
