<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Room;
use App\Models\Sale;
use App\Models\Subscription;
use App\Models\Workspace;

class DashboardController extends Controller
{
    public function index()
    {
        $now = now();

        $totalOwners = Owner::count();
        $activeOwners = Owner::where('is_active', true)
            ->where('subscription_expires_at', '>', $now)->count();
        $expiredOwners = Owner::where('subscription_expires_at', '<', $now)->count();
        // copy(): addDays() mutates — without it both bounds became now+7 and this was always 0.
        $expiringSoon = Owner::where('is_active', true)
            ->where('subscription_expires_at', '>', $now)
            ->where('subscription_expires_at', '<', $now->copy()->addDays(7))->count();

        $totalUsers = HotspotUser::count();
        $recentRenewals = Subscription::with(['owner', 'admin'])
            ->latest()->take(5)->get();

        $totalWorkspaces = Workspace::count();
        $totalRooms = Room::count();

        $totalBookings = Booking::count();
        $todayBookings = Booking::whereDate('booking_date', today())->count();
        // Same "money earned" rule as the owners' own Financials
        // (RevenueAnalyticsService): what was actually paid on completed
        // bookings, plus completed product sales — across all businesses.
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();
        $monthRevenue = (float) Booking::where('status', 'completed')
            ->whereDate('booking_date', '>=', $monthStart->toDateString())
            ->whereDate('booking_date', '<=', $monthEnd->toDateString())
            ->sum('amount_paid')
            + (float) Sale::where('status', 'completed')->whereBetween('sold_at', [$monthStart, $monthEnd])->sum('total');

        return view('admin.dashboard.index', compact(
            'totalOwners',
            'activeOwners',
            'expiredOwners',
            'expiringSoon',
            'totalUsers',
            'recentRenewals',
            'totalWorkspaces',
            'totalRooms',
            'totalBookings',
            'todayBookings',
            'monthRevenue',
        ));
    }
}
