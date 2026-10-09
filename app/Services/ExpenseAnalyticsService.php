<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Owner;

/**
 * Sibling to RevenueAnalyticsService, computing the additive "Expenses" side
 * of Net = Revenue - Expenses. Deliberately independent of
 * RevenueAnalyticsService (Net itself is computed by the caller) so that
 * service's existing revenue calculation is never touched by this feature.
 *
 * Every query filters by expenses.expense_date (the date the cost was
 * incurred), never created_at (when the row was recorded) — the two can
 * differ whenever an expense is logged after the fact.
 */
class ExpenseAnalyticsService
{
    public function totalExpenses(Owner $owner, AnalyticsPeriod $period): float
    {
        return (float) Expense::where('owner_id', $owner->id)
            ->whereDateBetween('expense_date', $period->startDate(), $period->endDate())
            ->sum('amount');
    }

    /**
     * Falls back to an "Uncategorized" bucket for expenses whose category
     * was later deleted (expense_category_id is nullOnDelete) or was never
     * set.
     *
     * @return array<int, array{category_id: ?int, name: string, amount: float}>
     */
    public function expensesByCategory(Owner $owner, AnalyticsPeriod $period): array
    {
        return Expense::query()
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->where('expenses.owner_id', $owner->id)
            ->whereDateBetween('expenses.expense_date', $period->startDate(), $period->endDate())
            ->selectRaw('expenses.expense_category_id as category_id, expense_categories.name as name, SUM(expenses.amount) as amount')
            ->groupBy('expenses.expense_category_id', 'expense_categories.name')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($row) => [
                'category_id' => $row->category_id !== null ? (int) $row->category_id : null,
                'name' => $row->name ?? __('app.expenses.uncategorized'),
                'amount' => round((float) $row->amount, 2),
            ])
            ->all();
    }
}
