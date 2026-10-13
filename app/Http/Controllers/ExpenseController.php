<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\AnalyticsPeriod;
use App\Services\ExpenseAnalyticsService;
use App\Services\RevenueAnalyticsService;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class ExpenseController extends Controller
{
    public function __construct(
        private ExpenseAnalyticsService $expenseAnalytics,
        private RevenueAnalyticsService $revenueAnalytics,
    ) {}

    public function index(Request $request): View
    {
        $owner = TenantContext::user();
        [$period, $periodKey, $customStart, $customEnd] = $this->resolvePeriod($request);

        $categories = ExpenseCategory::where('owner_id', $owner->id)
            ->withCount('expenses')
            ->orderBy('name')
            ->get();

        // ?category=<id> or ?category=none (uncategorized). An id is only honoured
        // when it is one of this owner's categories — never another tenant's.
        $categoryParam = (string) $request->query('category', '');
        $activeCategory = $categoryParam === 'none'
            ? 'none'
            : $categories->firstWhere('id', ctype_digit($categoryParam) ? (int) $categoryParam : 0)?->id;

        $inPeriod = fn ($q) => $q->where('owner_id', $owner->id)
            ->whereDate('expense_date', '>=', $period->startDate())
            ->whereDate('expense_date', '<=', $period->endDate());

        $expenses = Expense::query()->tap($inPeriod)
            ->with('category')
            ->when($activeCategory === 'none', fn ($q) => $q->whereNull('expense_category_id'))
            ->when(is_int($activeCategory), fn ($q) => $q->where('expense_category_id', $activeCategory))
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $totalExpenses = $this->expenseAnalytics->totalExpenses($owner, $period);
        $totalRevenue = $this->revenueAnalytics->totalRevenue($owner, $period);

        $staff = auth('staff')->user();

        return view('expenses.index', [
            'canCreate' => ! $staff || $staff->hasPermission('expenses.create'),
            'canEdit' => ! $staff || $staff->hasPermission('expenses.edit'),
            'canDelete' => ! $staff || $staff->hasPermission('expenses.delete'),
            'canManageCategories' => ! $staff || $staff->hasPermission('expenses.manage_categories'),
            'expenses' => $expenses,
            'categories' => $categories,
            'period' => $period,
            'periodKey' => $periodKey,
            'customStart' => $customStart,
            'customEnd' => $customEnd,
            'totalExpenses' => $totalExpenses,
            'totalRevenue' => $totalRevenue,
            'netTotal' => $totalRevenue - $totalExpenses,
            'byCategory' => $this->expenseAnalytics->expensesByCategory($owner, $period),
            'activeCategory' => $activeCategory,
            'entryCount' => Expense::query()->tap($inPeriod)->count(),
            'periodLabel' => $this->periodLabel($period),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $owner = TenantContext::user();

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:999999.99',
            'expense_category_id' => 'nullable|integer|exists:expense_categories,id,owner_id,'.$owner->id,
            'expense_date' => 'required|date',
            'note' => 'nullable|string|max:500',
        ]);

        Expense::create($validated + ['owner_id' => $owner->id]);

        return redirect()->back()->with('success', __('app.expenses.created'));
    }

    public function edit(int $id): View
    {
        $expense = Expense::where('owner_id', TenantContext::id())->findOrFail($id);

        $categories = ExpenseCategory::where('owner_id', TenantContext::id())
            ->orderBy('name')
            ->get();

        return view('expenses.edit', [
            'expense' => $expense,
            'categories' => $categories,
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $owner = TenantContext::user();
        $expense = Expense::where('owner_id', $owner->id)->findOrFail($id);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:999999.99',
            'expense_category_id' => 'nullable|integer|exists:expense_categories,id,owner_id,'.$owner->id,
            'expense_date' => 'required|date',
            'note' => 'nullable|string|max:500',
        ]);

        $expense->update($validated);

        return redirect()->route('expenses.index')->with('success', __('app.expenses.updated'));
    }

    public function destroy(int $id): RedirectResponse
    {
        $expense = Expense::where('owner_id', TenantContext::id())->findOrFail($id);
        $expense->delete();

        return redirect()->back()->with('success', __('app.expenses.deleted'));
    }

    /** @return array{0: AnalyticsPeriod, 1: string, 2: ?string, 3: ?string} */
    private function resolvePeriod(Request $request): array
    {
        $key = in_array($request->get('period'), ['today', 'this_week', 'this_month', 'custom'], true)
            ? $request->get('period')
            : 'this_month';

        $customStart = $request->get('start');
        $customEnd = $request->get('end');

        if ($key === 'custom') {
            $start = $this->parseDate($customStart) ?? now()->startOfMonth();
            $end = $this->parseDate($customEnd) ?? now();
            if ($end->lt($start)) {
                [$start, $end] = [$end, $start];
            }

            return [AnalyticsPeriod::custom($start, $end), $key, $start->toDateString(), $end->toDateString()];
        }

        $period = match ($key) {
            'today' => AnalyticsPeriod::today(),
            'this_week' => AnalyticsPeriod::thisWeek(),
            default => AnalyticsPeriod::thisMonth(),
        };

        return [$period, $key, $customStart, $customEnd];
    }

    /** "Oct 1 – Oct 14, 2026", or a single day; localized month names. */
    private function periodLabel(AnalyticsPeriod $period): string
    {
        $start = Carbon::parse($period->startDate());
        $end = Carbon::parse($period->endDate());

        if ($start->isSameDay($end)) {
            return $start->translatedFormat('j M Y');
        }

        return $start->translatedFormat($start->year === $end->year ? 'j M' : 'j M Y').' – '.$end->translatedFormat('j M Y');
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
