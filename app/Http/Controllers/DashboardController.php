<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Room;
use App\Models\SpeedProfile;
use App\Models\Workspace;
use App\Services\MikroTikService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $owner = Auth::guard('owner')->user();
        $ownerId = $owner->id;

        $totalUsers    = HotspotUser::where('owner_id', $ownerId)->count();
        $activeUsers   = HotspotUser::where('owner_id', $ownerId)->where('status', 'active')->count();
        $totalProfiles = SpeedProfile::where('owner_id', $ownerId)->count();

        $activeSessions = 0;
        $mikrotikError  = null;

        try {
            $mikrotik = new MikroTikService(
                $owner->mikrotik_host,
                $owner->mikrotik_port,
                $owner->mikrotik_username,
                $owner->mikrotik_password,
            );
            $mikrotik->connect();
            $activeSessions = count($mikrotik->getActiveUsers());
            $mikrotik->disconnect();
        } catch (Exception $e) {
            $mikrotikError = $e->getMessage();
        }

        $viewData = [
            'owner'          => $owner,
            'totalUsers'     => $totalUsers,
            'activeUsers'    => $activeUsers,
            'totalProfiles'  => $totalProfiles,
            'activeSessions' => $activeSessions,
            'mikrotikError'  => $mikrotikError,
            'todayBookings'  => 0,
            'pendingBookings'=> 0,
            'monthRevenue'   => 0,
        ];

        if ($owner->hasFeature('workspace')) {
            $viewData['totalWorkspaces'] = Workspace::where('owner_id', $ownerId)->count();
            $viewData['totalRooms']      = Room::where('owner_id', $ownerId)->count();
            $viewData['availableRooms']  = Room::where('owner_id', $ownerId)->where('is_available', true)->count();
        }

        if ($owner->hasFeature('booking')) {
            $viewData['todayBookings']   = Booking::where('owner_id', $ownerId)
                ->where('booking_date', today())
                ->where('status', '!=', 'cancelled')
                ->count();
            $viewData['pendingBookings'] = Booking::where('owner_id', $ownerId)
                ->where('status', 'pending')
                ->count();
            $viewData['monthRevenue']    = Booking::where('owner_id', $ownerId)
                ->where('status', 'completed')
                ->whereMonth('booking_date', now()->month)
                ->whereYear('booking_date', now()->year)
                ->sum('total_price');
        }

        return view('dashboard.index', $viewData);
    }
}
