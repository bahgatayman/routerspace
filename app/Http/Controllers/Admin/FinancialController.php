<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Owner;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Http\Request;

class FinancialController extends Controller
{
    public function index(Request $request)
    {
        $year = $request->get('year', now()->year);
        $month = $request->get('month');

        $totalRevenue = Subscription::where('amount_paid', '>', 0)->sum('amount_paid');

        $thisMonthRevenue = Subscription::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('amount_paid');

        $thisYearRevenue = Subscription::whereYear('created_at', $year)
            ->sum('amount_paid');

        $monthlyBreakdown = Subscription::selectRaw("
                strftime('%m', created_at) as month,
                SUM(amount_paid) as revenue,
                COUNT(*) as renewals
            ")
            ->whereYear('created_at', $year)
            ->where('amount_paid', '>', 0)
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            // strftime returns '01'..'12' — key by int so ->get(1..12) finds Jan–Sep too.
            ->keyBy(fn ($row) => (int) $row->month);

        $monthlyData = collect(range(1, 12))->map(function ($m) use ($monthlyBreakdown) {
            $data = $monthlyBreakdown->get($m);

            return [
                'month' => $m,
                'label' => Carbon::create()->month($m)->format('M'),
                'revenue' => $data?->revenue ?? 0,
                'renewals' => $data?->renewals ?? 0,
            ];
        });

        $revenueByPlan = Subscription::selectRaw('
                plan_id,
                SUM(amount_paid) as revenue,
                COUNT(*) as count
            ')
            ->with('plan')
            ->whereYear('created_at', $year)
            ->whereNotNull('plan_id')
            ->groupBy('plan_id')
            ->get();

        $activePerPlan = Owner::selectRaw('plan_id, COUNT(*) as count')
            ->where('is_active', true)
            ->where('subscription_expires_at', '>', now())
            ->whereNotNull('plan_id')
            ->groupBy('plan_id')
            ->with('plan')
            ->get();

        $recentTransactions = Subscription::with(['owner', 'plan', 'admin'])
            ->where('amount_paid', '>', 0)
            ->latest()
            ->take(20)
            ->get();

        $expiringSoon = Owner::where('subscription_expires_at', '>', now())
            ->where('subscription_expires_at', '<', now()->addDays(14))
            ->with('plan')
            ->orderBy('subscription_expires_at')
            ->get();

        return view('admin.financial.index', compact(
            'totalRevenue', 'thisMonthRevenue', 'thisYearRevenue',
            'monthlyData', 'revenueByPlan', 'activePerPlan',
            'recentTransactions', 'expiringSoon', 'year'
        ));
    }
}
