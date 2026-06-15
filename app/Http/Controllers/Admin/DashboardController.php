<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Room;
use App\Models\Subscription;
use App\Models\Workspace;

class DashboardController extends Controller
{
    public function index()
    {
        $now = now();

        $totalOwners    = Owner::count();
        $activeOwners   = Owner::where('is_active', true)
            ->where('subscription_expires_at', '>', $now)->count();
        $expiredOwners  = Owner::where('subscription_expires_at', '<', $now)->count();
        $expiringSoon   = Owner::where('is_active', true)
            ->where('subscription_expires_at', '>', $now)
            ->where('subscription_expires_at', '<', $now->addDays(7))->count();

        $totalUsers      = HotspotUser::count();
        $recentRenewals  = Subscription::with(['owner', 'admin'])
            ->latest()->take(5)->get();

        $totalWorkspaces = Workspace::count();
        $totalRooms      = Room::count();

        $totalBookings = Booking::count();
        $todayBookings = Booking::where('booking_date', today())->count();
        $monthRevenue  = Booking::where('status', 'completed')
            ->whereMonth('booking_date', now()->month)
            ->whereYear('booking_date', now()->year)
            ->sum('total_price');

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
