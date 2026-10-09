import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PeriodFilter, { egp } from '../../Components/Financials/PeriodFilter';
import { ConfirmButton, Icon, Modal, Pagination } from '../../Components/ui';
import { t } from '../../lib/i18n';
import { usePageTitle } from '../../lib/pageTitle';

const INPUT = 'w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500';
const RECEIPT = 'M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6';

export default function ExpensesIndex(props) {
    const { canCreate, canEdit, canDelete, canManageCategories, expenses, categories, byCategory, totalExpenses } = props;
    usePageTitle(t('expenses.title'));
    const [addOpen, setAddOpen] = useState(false);
    const rows = expenses.data;
    const maxAmount = Math.max(1, ...(byCategory || []).map((r) => r.amount));

    return (
        <>
            <div className="flex flex-wrap items-center justify-between gap-3 mb-6">
                <h1 className="text-2xl font-bold text-gray-900">{t('expenses.title')}</h1>
                <div className="flex items-center gap-2">
                    <Link href="/financials" className="px-4 py-2 rounded-lg text-sm font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 transition">{t('financials.overview')}</Link>
                    {canCreate && (
                        <button type="button" onClick={() => setAddOpen(true)} className="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">
                            <Icon name="plus" className="w-4 h-4" />
                            {t('expenses.add_expense')}
                        </button>
                    )}
                </div>
            </div>

            {/* Total Expenses — Revenue/Net live on the Financials page, not here. */}
            <div className="max-w-xs mb-6">
                <div className="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6">
                    <div className="flex items-center justify-between">
                        <div className="min-w-0">
                            <p className="text-xs lg:text-sm font-medium text-gray-500">{t('expenses.total_expenses')}</p>
                            <p className="text-2xl lg:text-3xl font-bold text-red-600 mt-1 whitespace-nowrap">{egp(totalExpenses)}</p>
                        </div>
                        <div className="w-10 h-10 lg:w-12 lg:h-12 bg-red-50 rounded-xl flex items-center justify-center shrink-0">
                            <svg className="w-5 h-5 lg:w-6 lg:h-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d={RECEIPT} /></svg>
                        </div>
                    </div>
                </div>
            </div>

            <div className="mb-6">
                <PeriodFilter periodKey={props.periodKey} customStart={props.customStart} customEnd={props.customEnd} />
            </div>

            {canManageCategories && <CategoriesPanel categories={categories} />}

            {byCategory && byCategory.length > 0 && (
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm p-4 lg:p-6 mb-6">
                    <h3 className="font-semibold text-gray-900 mb-4">{t('expenses.by_category')}</h3>
                    <div className="space-y-3">
                        {byCategory.map((row, i) => (
                            <div key={i}>
                                <div className="flex items-center justify-between text-sm mb-1">
                                    <span className="text-gray-600 truncate">{row.name}</span>
                                    <span className="font-medium text-gray-900 shrink-0 ms-2">{egp(row.amount)}</span>
                                </div>
                                <div className="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                                    <div className="h-full bg-red-400 rounded-full" style={{ width: `${(row.amount / maxAmount) * 100}%` }} />
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {rows.length === 0 ? (
                <div className="text-center py-16 bg-white rounded-xl border border-gray-100">
                    <div className="w-12 h-12 mx-auto rounded-full bg-gray-50 flex items-center justify-center mb-3">
                        <svg className="w-6 h-6 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d={RECEIPT} /></svg>
                    </div>
                    <p className="text-gray-500 text-sm">{t('expenses.no_expenses')}</p>
                </div>
            ) : (
                <>
                    <div className="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left text-gray-500 border-b border-gray-100 bg-gray-50">
                                        <th className="px-6 py-3 font-medium">{t('expenses.date')}</th>
                                        <th className="px-6 py-3 font-medium">{t('expenses.category')}</th>
                                        <th className="px-6 py-3 font-medium">{t('expenses.note')}</th>
                                        <th className="px-6 py-3 font-medium text-right">{t('expenses.amount')}</th>
                                        <th className="px-6 py-3 font-medium">{t('common.actions')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {rows.map((e) => (
                                        <tr key={e.id} className="hover:bg-gray-50">
                                            <td className="px-6 py-4 text-gray-600 whitespace-nowrap">{e.date}</td>
                                            <td className="px-6 py-4">
                                                <span className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600">{e.category ?? t('expenses.uncategorized')}</span>
                                            </td>
                                            <td className="px-6 py-4 text-gray-500 max-w-[240px] truncate" title={e.note ?? ''}>{e.note ?? '—'}</td>
                                            <td className="px-6 py-4 text-right font-medium text-gray-900 whitespace-nowrap">{egp(e.amount)}</td>
                                            <td className="px-6 py-4">
                                                <div className="flex items-center gap-3">
                                                    {canEdit && <Link href={`/expenses/${e.id}/edit`} className="text-blue-600 hover:underline text-sm font-medium">{t('expenses.edit')}</Link>}
                                                    {canDelete && (
                                                        <ConfirmButton href={`/expenses/${e.id}`} method="delete" message={t('expenses.delete_confirm')} confirmLabel={t('expenses.delete')}>
                                                            {t('expenses.delete')}
                                                        </ConfirmButton>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div className="mt-6"><Pagination paginator={expenses} /></div>
                </>
            )}

            {canCreate && <AddExpenseModal open={addOpen} setOpen={setAddOpen} categories={categories} today={props.today} />}
        </>
    );
}

function CategoriesPanel({ categories }) {
    const add = useForm({ name: '' });
    const submit = (e) => {
        e.preventDefault();
        add.post('/expense-categories', { preserveScroll: true, onSuccess: () => add.reset() });
    };

    return (
        <div className="mb-6">
            <details className="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6 max-w-md">
                <summary className="flex items-center justify-between gap-2 font-semibold text-gray-900 cursor-pointer select-none marker:text-gray-400">
                    <span className="flex items-center gap-2">
                        <Icon name="gear" className="w-4 h-4 text-gray-500" />
                        {t('expenses.categories')}
                    </span>
                    <span className="text-xs font-normal text-gray-400">{categories.length}</span>
                </summary>

                <form onSubmit={submit} className="flex gap-2 mt-4 mb-3">
                    <input type="text" name="name" placeholder={t('expenses.category_name')} required maxLength={100} value={add.data.name} onChange={(e) => add.setData('name', e.target.value)}
                        className="min-w-0 flex-1 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
                    <button type="submit" disabled={add.processing} className="shrink-0 px-3 py-2 rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200 transition text-sm font-medium">{t('expenses.add_category')}</button>
                </form>
                {add.errors.name && <p className="text-xs text-red-600 mb-3">{add.errors.name}</p>}

                {categories.length === 0 ? (
                    <p className="text-sm text-gray-400">{t('expenses.no_categories')}</p>
                ) : (
                    <ul className="space-y-2 max-h-72 overflow-y-auto">
                        {categories.map((c) => <CategoryRow key={c.id} category={c} />)}
                    </ul>
                )}
            </details>
        </div>
    );
}

function CategoryRow({ category }) {
    const form = useForm({ name: category.name });
    const submit = (e) => {
        e.preventDefault();
        form.put(`/expense-categories/${category.id}`, { preserveScroll: true });
    };

    return (
        <li className="flex flex-wrap items-center gap-2 text-sm border-b border-gray-50 pb-2 last:border-0 last:pb-0">
            <form onSubmit={submit} className="flex items-center gap-2 min-w-0 flex-1 basis-40">
                <input type="text" name="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} maxLength={100}
                    className="min-w-0 flex-1 border border-gray-200 rounded-md px-2 py-1 text-sm" />
                <button type="submit" disabled={form.processing} className="text-blue-600 hover:underline text-xs font-medium shrink-0">{t('expenses.save')}</button>
            </form>
            <div className="flex items-center gap-2 shrink-0 ms-auto">
                <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-gray-100 text-gray-500">{category.expenses_count}</span>
                <ConfirmButton href={`/expense-categories/${category.id}`} method="delete" message={t('expenses.delete_confirm')} confirmLabel={t('expenses.delete')}>
                    {t('expenses.delete')}
                </ConfirmButton>
            </div>
            {form.errors.name && <p className="w-full text-xs text-red-600">{form.errors.name}</p>}
        </li>
    );
}

function AddExpenseModal({ open, setOpen, categories, today }) {
    const form = useForm({ amount: '', expense_category_id: '', expense_date: today, note: '' });
    const { data, setData } = form;
    const errors = Object.values(form.errors);

    const submit = (e) => {
        e.preventDefault();
        form.post('/expenses', {
            preserveScroll: true,
            onSuccess: () => { form.reset(); setOpen(false); },
        });
    };

    return (
        <Modal id="add-expense" open={open} onClose={() => setOpen(false)} title={t('expenses.add_expense')}
            footer={<>
                <button type="button" onClick={() => setOpen(false)} className="px-4 py-2 rounded-lg text-sm font-medium text-gray-600 hover:bg-gray-100 transition">{t('expenses.cancel')}</button>
                <button type="submit" form="add-expense-form" disabled={form.processing} className="bg-blue-600 text-white px-5 py-2.5 rounded-lg hover:bg-blue-700 transition text-sm font-medium shadow-sm">{t('expenses.save')}</button>
            </>}>
            <form id="add-expense-form" onSubmit={submit} className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">{t('expenses.amount')} <span className="text-gray-400 font-normal">(ج.م)</span></label>
                    <input type="number" step="0.01" min="0.01" name="amount" value={data.amount} onChange={(e) => setData('amount', e.target.value)} placeholder="0.00" required className={INPUT} />
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
            </form>

            {errors.length > 0 && (
                <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mt-4">
                    <ul className="list-disc list-inside text-sm">{errors.map((err, i) => <li key={i}>{err}</li>)}</ul>
                </div>
            )}
        </Modal>
    );
}
