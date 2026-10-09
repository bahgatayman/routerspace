import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { withQuery } from '../ui';
import { t } from '../../lib/i18n';

const PERIODS = [['today', 'financials.period_today'], ['this_week', 'financials.period_week'], ['this_month', 'financials.period_month']];

/** Period chips + custom date range (GET params) — shared by the Overview and Transactions pages. */
export default function PeriodFilter({ periodKey, customStart, customEnd }) {
    const [start, setStart] = useState(customStart || '');
    const [end, setEnd] = useState(customEnd || '');
    useEffect(() => { setStart(customStart || ''); setEnd(customEnd || ''); }, [customStart, customEnd]);

    const apply = (e) => {
        e.preventDefault();
        // Keep every other filter (status, source…); drop the page like the Blade form did.
        const params = Object.fromEntries(new URLSearchParams(window.location.search));
        ['period', 'start', 'end', 'page'].forEach((k) => delete params[k]);
        router.get(window.location.pathname, { period: 'custom', ...params, start, end }, { preserveScroll: true });
    };

    return (
        <div className="flex flex-wrap items-center gap-3">
            <div className="inline-flex items-center gap-1 bg-white border border-gray-100 rounded-lg p-1 shadow-sm">
                {PERIODS.map(([key, label]) => (
                    <Link key={key} href={withQuery(window.location.href, { period: key })} preserveScroll
                        className={`px-3 py-1.5 rounded-md text-sm font-medium transition ${periodKey === key ? 'bg-brand-600 text-white' : 'text-gray-500 hover:bg-gray-50'}`}>
                        {t(label)}
                    </Link>
                ))}
            </div>
            <form onSubmit={apply} className="flex items-center gap-2">
                <input type="date" name="start" value={start} onChange={(e) => setStart(e.target.value)} className="border border-gray-200 rounded-md text-sm px-2 py-1.5 text-gray-700" />
                <span className="text-gray-400 text-sm">&ndash;</span>
                <input type="date" name="end" value={end} onChange={(e) => setEnd(e.target.value)} className="border border-gray-200 rounded-md text-sm px-2 py-1.5 text-gray-700" />
                <button type="submit" className={`px-3 py-1.5 rounded-md text-sm font-medium transition ${periodKey === 'custom' ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'}`}>
                    {t('financials.apply')}
                </button>
            </form>
        </div>
    );
}

/** Same as the Blade pages' "ج.م 1,234.50" figures. */
export const egp = (v) => `ج.م ${Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
