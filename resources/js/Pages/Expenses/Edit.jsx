import { Link, useForm } from '@inertiajs/react';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

const INPUT = 'w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500';

export default function ExpenseEdit({ expense, categories }) {
    usePageTitle(t('expenses.edit_expense'));
    const form = useForm({
        amount: expense.amount ?? '',
        expense_category_id: expense.expense_category_id ?? '',
        expense_date: expense.expense_date,
        note: expense.note ?? '',
    });
    const { data, setData } = form;
    const errors = Object.values(form.errors);

    const submit = (e) => {
        e.preventDefault();
        form.put(`/expenses/${expense.id}`);
    };

    return (
        <>
            <div className="flex items-center justify-between mb-6">
                <h1 className="text-2xl font-bold text-gray-900">{t('expenses.edit_expense')}</h1>
                <Link href="/expenses" className="text-sm text-gray-500 hover:text-gray-700">&larr; {t('common.back')}</Link>
            </div>

            {errors.length > 0 && (
                <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-4">
                    <ul className="list-disc list-inside text-sm">{errors.map((err, i) => <li key={i}>{err}</li>)}</ul>
                </div>
            )}

            <form onSubmit={submit} className="bg-white rounded-xl shadow-sm border border-gray-100 p-6 max-w-2xl space-y-6">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">{t('expenses.amount')}</label>
                        <input type="number" step="0.01" min="0.01" name="amount" value={data.amount} onChange={(e) => setData('amount', e.target.value)} required className={INPUT} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">{t('expenses.category')}</label>
                        <select name="expense_category_id" value={data.expense_category_id} onChange={(e) => setData('expense_category_id', e.target.value)} className={INPUT}>
                            <option value="">{t('expenses.uncategorized')}</option>
                            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">{t('expenses.date')}</label>
                        <input type="date" name="expense_date" value={data.expense_date} onChange={(e) => setData('expense_date', e.target.value)} required className={INPUT} />
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">{t('expenses.note_optional')}</label>
                        <input type="text" name="note" value={data.note} onChange={(e) => setData('note', e.target.value)} maxLength={500} className={INPUT} />
                    </div>
                </div>

                <div className="flex gap-3">
                    <button type="submit" disabled={form.processing} className="bg-blue-600 text-white px-5 py-2.5 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">{t('expenses.save')}</button>
                    <Link href="/expenses" className="px-5 py-2.5 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-100 transition">{t('expenses.cancel')}</Link>
                </div>
            </form>
        </>
    );
}
