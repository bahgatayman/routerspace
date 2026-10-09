/*
 * Coupon form field groups — React ports of coupons/_fields-basic,
 * _fields-applies-to (+ _item-picker) and _fields-restrictions. Shared by the
 * create/edit page and the quick-create modal on the index. Each takes the
 * page's useForm() object; the server validates everything.
 */
import { t } from '../../lib/i18n';

const INPUT = 'w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500';
const SEG = 'block px-3.5 py-1.5 rounded-md text-sm font-medium text-gray-600 transition peer-checked:bg-blue-600 peer-checked:text-white peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-blue-500 peer-focus-visible:ring-offset-1';

/** Initial useForm() data from the controller's couponFormData() (ids as numbers). */
export function couponFormDefaults(coupon) {
    return {
        code: coupon.code ?? '',
        discount_type: coupon.discount_type ?? 'percentage',
        discount_value: coupon.discount_value ?? '',
        applies_to: coupon.applies_to ?? 'both',
        room_scope: coupon.room_scope ?? 'all',
        product_scope: coupon.product_scope ?? 'all',
        room_ids: coupon.room_ids ?? [],
        product_ids: coupon.product_ids ?? [],
        starts_at: coupon.starts_at ?? '',
        expires_at: coupon.expires_at ?? '',
        usage_limit: coupon.usage_limit ?? '',
        per_customer_limit: coupon.per_customer_limit ?? '',
        minimum_spend: coupon.minimum_spend ?? '',
        is_active: coupon.is_active ?? true,
    };
}

export function BasicFields({ form }) {
    const { data, setData } = form;
    return (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">{t('coupons.code')}</label>
                <input type="text" name="code" value={data.code} onChange={(e) => setData('code', e.target.value)} required maxLength={32}
                    className={`${INPUT} uppercase`} dir="ltr" />
                <p className="text-xs text-gray-400 mt-1">{t('coupons.code_hint')}</p>
            </div>
            <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">{t('coupons.discount_type')}</label>
                <select name="discount_type" value={data.discount_type} onChange={(e) => setData('discount_type', e.target.value)} className={INPUT}>
                    <option value="percentage">{t('coupons.percentage')}</option>
                    <option value="fixed">{t('coupons.fixed')}</option>
                </select>
            </div>
            <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">{t('coupons.value')}</label>
                <div className="relative">
                    <input type="number" step="0.01" min="0.01" name="discount_value" value={data.discount_value} onChange={(e) => setData('discount_value', e.target.value)} required
                        className={`${INPUT} pe-12`} />
                    <span className="absolute inset-y-0 end-0 flex items-center pe-3 text-sm text-gray-400 pointer-events-none">{data.discount_type === 'fixed' ? 'ج.م' : '%'}</span>
                </div>
            </div>
        </div>
    );
}

function Segment({ name, value, checked, onChange, children }) {
    return (
        <label className="cursor-pointer">
            <input type="radio" name={name} value={value} checked={checked} onChange={onChange} className="peer sr-only" />
            <span className={SEG}>{children}</span>
        </label>
    );
}

function ItemPicker({ groups, selected, onToggle }) {
    return (
        <div className="border border-gray-200 rounded-lg divide-y divide-gray-100 max-h-64 overflow-y-auto">
            {groups.length === 0 && <p className="p-3 text-sm text-gray-400">{t('coupons.empty')}</p>}
            {groups.map((group) => (
                <div key={group.label} className="p-3">
                    <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">{group.label}</p>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                        {group.items.map((item) => (
                            <label key={item.id} className="inline-flex items-center gap-2 text-sm">
                                <input type="checkbox" value={item.id} checked={selected.includes(item.id)} onChange={() => onToggle(item.id)} />
                                {item.name}
                            </label>
                        ))}
                    </div>
                </div>
            ))}
        </div>
    );
}

function ScopeBlock({ form, type, groups }) {
    const { data, setData } = form;
    const scopeKey = type === 'rooms' ? 'room_scope' : 'product_scope';
    const idsKey = type === 'rooms' ? 'room_ids' : 'product_ids';
    const toggle = (id) => setData(idsKey, data[idsKey].includes(id) ? data[idsKey].filter((x) => x !== id) : [...data[idsKey], id]);
    const hidden = data.applies_to !== 'both' && data.applies_to !== type;

    return (
        <div className={`border-t border-gray-100 pt-4 mt-4 ${hidden ? 'hidden' : ''}`}>
            <p className="text-sm font-medium text-gray-700 mb-2">{t(`coupons.${type}`)}</p>
            <div className="inline-flex flex-wrap gap-1 rounded-lg border border-gray-200 bg-gray-50 p-1 mb-3">
                <Segment name={scopeKey} value="all" checked={data[scopeKey] === 'all'} onChange={() => setData(scopeKey, 'all')}>{t(`coupons.all_${type}`)}</Segment>
                <Segment name={scopeKey} value="specific" checked={data[scopeKey] === 'specific'} onChange={() => setData(scopeKey, 'specific')}>{t(`coupons.specific_${type}`)}</Segment>
            </div>
            <div className={data[scopeKey] === 'specific' ? '' : 'hidden'}>
                <ItemPicker groups={groups} selected={data[idsKey]} onToggle={toggle} />
            </div>
        </div>
    );
}

export function AppliesToFields({ form, roomGroups, productGroups }) {
    const { data, setData } = form;
    return (
        <>
            <div>
                <div className="inline-flex flex-wrap gap-1 rounded-lg border border-gray-200 bg-gray-50 p-1">
                    {['rooms', 'products', 'both'].map((value) => (
                        <Segment key={value} name="applies_to" value={value} checked={data.applies_to === value} onChange={() => setData('applies_to', value)}>
                            {t(`coupons.${value}`)}
                        </Segment>
                    ))}
                </div>
            </div>
            <ScopeBlock form={form} type="rooms" groups={roomGroups} />
            <ScopeBlock form={form} type="products" groups={productGroups} />
        </>
    );
}

export function RestrictionFields({ form }) {
    const { data, setData } = form;
    const field = (name, label, props = {}) => (
        <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">{label}</label>
            <input name={name} value={data[name]} onChange={(e) => setData(name, e.target.value)} className={INPUT} {...props} />
        </div>
    );
    return (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            {field('starts_at', t('coupons.starts_at'), { type: 'date' })}
            {field('expires_at', t('coupons.expires_at'), { type: 'date' })}
            {field('usage_limit', t('coupons.usage_limit'), { type: 'number', min: 0, placeholder: t('coupons.no_limit') })}
            {field('per_customer_limit', t('coupons.per_customer_limit'), { type: 'number', min: 0, placeholder: t('coupons.no_limit') })}
            {field('minimum_spend', t('coupons.minimum_spend'), { type: 'number', step: '0.01', min: 0, placeholder: 'ج.م 0.00' })}
        </div>
    );
}

export function ErrorList({ errors, className = 'mt-4' }) {
    const list = Object.values(errors || {});
    if (list.length === 0) return null;
    return (
        <div className={`bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg ${className}`}>
            <ul className="list-disc list-inside text-sm">{list.map((e, i) => <li key={i}>{e}</li>)}</ul>
        </div>
    );
}
