<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\OwnerController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HotspotUserController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SpeedProfileController;
use App\Http\Controllers\SubscriptionController as OwnerSubscriptionController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\Admin\WorkspaceController as AdminWorkspaceController;
use App\Http\Controllers\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\DemoRequestController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('landing');
});

Route::post('/demo-request', [DemoRequestController::class, 'send'])->name('demo.request');

// ===================== Owner Auth =====================
Route::middleware('guest:owner')->group(function () {
    Route::get('/login', [LoginController::class, 'showLogin'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
    Route::get('/register', [RegisterController::class, 'showRegister']);
    Route::post('/register', [RegisterController::class, 'register']);
});

Route::post('/logout', [LoginController::class, 'logout'])->name('owner.logout');

// ===================== Subscription Expired =====================
Route::get('/subscription/expired', [OwnerSubscriptionController::class, 'expired'])->name('subscription.expired');

// ===================== Owner Routes (authenticated + subscription check) =====================
Route::middleware(['auth:owner', 'subscription.active'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Hotspot feature routes
    Route::middleware('feature:hotspot')->group(function () {
        Route::get('/users', [HotspotUserController::class, 'index']);
        Route::get('/users/create', [HotspotUserController::class, 'create']);
        Route::post('/users', [HotspotUserController::class, 'store']);
        Route::get('/users/{id}', [HotspotUserController::class, 'show']);
        Route::get('/users/{id}/edit', [HotspotUserController::class, 'edit']);
        Route::put('/users/{id}', [HotspotUserController::class, 'update']);
        Route::delete('/users/{id}', [HotspotUserController::class, 'destroy']);
        Route::post('/users/{id}/toggle-status', [HotspotUserController::class, 'toggleStatus']);
        Route::post('/users/{id}/speed', [HotspotUserController::class, 'updateSpeed']);
        Route::get('/speed-profiles', [SpeedProfileController::class, 'index']);
        Route::get('/speed-profiles/create', [SpeedProfileController::class, 'create']);
        Route::post('/speed-profiles', [SpeedProfileController::class, 'store']);
        Route::get('/speed-profiles/{id}/edit', [SpeedProfileController::class, 'edit']);
        Route::put('/speed-profiles/{id}', [SpeedProfileController::class, 'update']);
        Route::delete('/speed-profiles/{id}', [SpeedProfileController::class, 'destroy']);
        Route::post('/speed-profiles/{id}/set-default', [SpeedProfileController::class, 'setDefault']);
        Route::get('/sessions', [SessionController::class, 'index']);
    });

    // Workspace feature routes
    Route::middleware('feature:workspace')->group(function () {
        Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
        Route::get('/workspaces/create', [WorkspaceController::class, 'create'])->name('workspaces.create');
        Route::post('/workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
        Route::get('/workspaces/{workspace}', [WorkspaceController::class, 'show'])->name('workspaces.show');
        Route::get('/workspaces/{workspace}/edit', [WorkspaceController::class, 'edit'])->name('workspaces.edit');
        Route::put('/workspaces/{workspace}', [WorkspaceController::class, 'update'])->name('workspaces.update');
        Route::delete('/workspaces/{workspace}', [WorkspaceController::class, 'destroy'])->name('workspaces.destroy');
        Route::post('/workspaces/{workspace}/toggle', [WorkspaceController::class, 'toggleActive'])->name('workspaces.toggle');

        // Nested room routes
        Route::get('/workspaces/{workspace}/rooms/create', [RoomController::class, 'create'])->name('rooms.create');
        Route::post('/workspaces/{workspace}/rooms', [RoomController::class, 'store'])->name('rooms.store');
        Route::get('/workspaces/{workspace}/rooms/{room}/edit', [RoomController::class, 'edit'])->name('rooms.edit');
        Route::put('/workspaces/{workspace}/rooms/{room}', [RoomController::class, 'update'])->name('rooms.update');
        Route::delete('/workspaces/{workspace}/rooms/{room}', [RoomController::class, 'destroy'])->name('rooms.destroy');
        Route::post('/workspaces/{workspace}/rooms/{room}/toggle', [RoomController::class, 'toggleAvailable'])->name('rooms.toggle');
    });

    // Booking feature routes
    Route::middleware('feature:booking')->group(function () {
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::get('/customers/create', [CustomerController::class, 'create']);
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit']);
        Route::put('/customers/{customer}', [CustomerController::class, 'update']);
        Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);

        Route::get('/bookings/calendar', [BookingController::class, 'calendar']);
        Route::get('/bookings/check-availability', [BookingController::class, 'checkAvailability']);
        Route::get('/bookings', [BookingController::class, 'index']);
        Route::get('/bookings/create', [BookingController::class, 'create']);
        Route::post('/bookings', [BookingController::class, 'store']);
        Route::get('/bookings/{booking}', [BookingController::class, 'show']);
        Route::get('/bookings/{booking}/edit', [BookingController::class, 'edit']);
        Route::put('/bookings/{booking}', [BookingController::class, 'update']);
        Route::delete('/bookings/{booking}', [BookingController::class, 'destroy']);
        Route::post('/bookings/{booking}/status', [BookingController::class, 'updateStatus']);
    });

    Route::get('/settings', [SettingsController::class, 'index']);
    Route::post('/settings/test-connection', [SettingsController::class, 'testConnection']);
});

// ===================== Admin Auth =====================
Route::middleware('guest:admin')->group(function () {
    Route::get('/admin/login', [AuthController::class, 'showLogin']);
    Route::post('/admin/login', [AuthController::class, 'login']);
});

Route::post('/admin/logout', [AuthController::class, 'logout']);

// ===================== Admin Routes (authenticated) =====================
Route::middleware('auth:admin')->prefix('admin')->group(function () {
    Route::get('/dashboard', [AdminDashboardController::class, 'index']);

    Route::get('/owners', [OwnerController::class, 'index']);
    Route::get('/owners/create', [OwnerController::class, 'create']);
    Route::post('/owners', [OwnerController::class, 'store']);
    Route::get('/owners/{owner}', [OwnerController::class, 'show']);
    Route::put('/owners/{owner}/toggle-active', [OwnerController::class, 'toggleActive']);
    Route::get('/owners/{owner}/users', [OwnerController::class, 'users']);

    Route::post('/owners/{owner}/renew', [SubscriptionController::class, 'renew']);

    // Admin Feature Management
    Route::get('/features', [\App\Http\Controllers\Admin\FeatureController::class, 'index']);
    Route::post('/features/{feature}/toggle-global', [\App\Http\Controllers\Admin\FeatureController::class, 'toggleGlobal']);
    Route::post('/owners/{owner}/features/{feature}/toggle', [\App\Http\Controllers\Admin\FeatureController::class, 'toggleForOwner']);

    // Admin Workspace (read-only)
    Route::get('/workspaces', [AdminWorkspaceController::class, 'index'])->name('admin.workspaces.index');
    Route::get('/workspaces/{workspace}', [AdminWorkspaceController::class, 'show'])->name('admin.workspaces.show');

    // Admin Bookings (read-only)
    Route::get('/bookings', [AdminBookingController::class, 'index'])->name('admin.bookings.index');
    Route::get('/bookings/{booking}', [AdminBookingController::class, 'show'])->name('admin.bookings.show');
});
