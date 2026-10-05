<?php

use App\Http\Controllers\ActiveSessionController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\Admin\BusinessController as AdminBusinessController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\FeatureController;
use App\Http\Controllers\Admin\FinancialController;
use App\Http\Controllers\Admin\NotificationController as AdminNotificationController;
use App\Http\Controllers\Admin\OwnerController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\SubscriptionRequestController;
use App\Http\Controllers\Admin\WorkspaceController as AdminWorkspaceController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoRequestController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FinancialController as OwnerFinancialController;
use App\Http\Controllers\HotspotUserController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\MemberPackageController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PackageTemplateController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SharedSessionController;
use App\Http\Controllers\SpeedProfileController;
use App\Http\Controllers\StaffActivityController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\SubscriptionController as OwnerSubscriptionController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('landing');
});

Route::post('/demo-request', [DemoRequestController::class, 'send'])->name('demo.request');

Route::post('/language/{locale}', [LanguageController::class, 'switch'])->name('language.switch');

// ===================== Owner Auth =====================
Route::middleware('guest:owner')->group(function () {
    Route::get('/login', [LoginController::class, 'showLogin'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
    Route::get('/register', [RegisterController::class, 'showRegister']);
    Route::post('/register', [RegisterController::class, 'register']);

    Route::get('/forgot-password', [ForgotPasswordController::class, 'showForgot'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink'])->name('password.email');
    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'showReset'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'reset'])->name('password.update');
});

Route::post('/logout', [LoginController::class, 'logout'])->name('owner.logout');

// ===================== Subscription (reachable while expired) =====================
// Deliberately outside `subscription.active`: an owner whose subscription lapsed
// must still be able to see the plans and ask to renew.
Route::middleware('auth:owner')->group(function () {
    Route::get('/subscription/expired', [OwnerSubscriptionController::class, 'expired'])->name('subscription.expired');
    Route::get('/subscription/plans', [OwnerSubscriptionController::class, 'plans'])->name('subscription.plans');
    Route::post('/subscription/request', [OwnerSubscriptionController::class, 'requestRenewal'])->name('subscription.request');
    Route::delete('/subscription/request/{id}', [OwnerSubscriptionController::class, 'cancelRequest'])->name('subscription.request.cancel');
});

// ===================== Owner Routes (authenticated + subscription check) =====================
// auth:owner,staff — a staff session is an alternate identity for the same
// tenant. subscription.active only recognizes the owner guard, so
// staff.active re-verifies staff/owner active-state + subscription on every
// request for staff sessions specifically (see CheckStaffActive).
Route::middleware(['auth:owner,staff', 'subscription.active', 'staff.active'])->group(function () {
    // Never permission-gated: this is the mandatory post-login landing page
    // and CheckPermission's own denial fallback target, so gating it risks
    // an infinite redirect loop for any staff member who lacks the permission.
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/users/search', [HotspotUserController::class, 'search'])->middleware('permission:members.view');

    // Member/customer registry — HotspotUser is the shared customer entity that
    // BOOKING owners also need. Available with either feature; router sync is
    // applied inside the controller only for hotspot owners.
    Route::middleware('feature:hotspot,booking')->group(function () {
        Route::get('/users', [HotspotUserController::class, 'index'])->middleware('permission:members.view');
        Route::get('/users/create', [HotspotUserController::class, 'create'])->middleware('permission:members.create');
        Route::post('/users', [HotspotUserController::class, 'store'])->middleware('permission:members.create');
        // Inline "add member" used by the booking + shared-session pickers.
        Route::post('/users/quick', [HotspotUserController::class, 'quickStore'])->middleware('permission:members.create');
        Route::get('/users/{id}', [HotspotUserController::class, 'show'])->middleware('permission:members.view');
        Route::get('/users/{id}/edit', [HotspotUserController::class, 'edit'])->middleware('permission:members.edit');
        Route::put('/users/{id}', [HotspotUserController::class, 'update'])->middleware('permission:members.edit');
        Route::delete('/users/{id}', [HotspotUserController::class, 'destroy'])->middleware('permission:members.delete');
        Route::post('/users/{id}/toggle-status', [HotspotUserController::class, 'toggleStatus'])->middleware('permission:members.manage_status');
    });

    // Hotspot-only: router-backed actions.
    Route::middleware('feature:hotspot')->group(function () {
        Route::post('/users/{id}/speed', [HotspotUserController::class, 'updateSpeed'])->middleware('permission:hotspot.manage_speed');
        Route::middleware('permission:hotspot.manage_speed')->group(function () {
            Route::get('/speed-profiles', [SpeedProfileController::class, 'index']);
            Route::get('/speed-profiles/create', [SpeedProfileController::class, 'create']);
            Route::post('/speed-profiles', [SpeedProfileController::class, 'store']);
            Route::get('/speed-profiles/{id}/edit', [SpeedProfileController::class, 'edit']);
            Route::put('/speed-profiles/{id}', [SpeedProfileController::class, 'update']);
            Route::delete('/speed-profiles/{id}', [SpeedProfileController::class, 'destroy']);
            Route::post('/speed-profiles/{id}/set-default', [SpeedProfileController::class, 'setDefault']);
        });
        Route::get('/sessions', [SessionController::class, 'index'])->middleware('permission:hotspot.view_sessions');
    });

    // Workspace feature routes
    Route::middleware('feature:workspace')->group(function () {
        Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index')->middleware('permission:workspaces.view');

        Route::middleware('permission:workspaces.manage')->group(function () {
            Route::get('/workspaces/create', [WorkspaceController::class, 'create'])->name('workspaces.create');
            Route::post('/workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
        });

        Route::middleware('permission:workspaces.manage')->group(function () {
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
    });

    // Booking feature routes
    Route::middleware('feature:booking')->group(function () {
        // Active Sessions — the live-ops dashboard (was "Shared Sessions", now
        // also covers in-progress exclusive-room bookings). Either permission
        // grants visibility; each card's own action buttons are gated by
        // whichever permission already protects that underlying mutation.
        Route::get('/active-sessions', [ActiveSessionController::class, 'index'])
            ->name('active-sessions.index')
            ->middleware('permission:shared_sessions.view,bookings.view');

        // Old bookmarks/links must never 404 — the walk-in-session create form's
        // logic is unchanged and stays shared-room-specific (SharedSessionController),
        // just reachable at a new URL alongside the new dashboard.
        Route::get('/shared-sessions', fn () => redirect()->route('active-sessions.index'))->name('shared-sessions.index');
        Route::get('/shared-sessions/create', fn () => redirect()->route('active-sessions.create'))->name('shared-sessions.create');

        Route::get('/active-sessions/create', [SharedSessionController::class, 'create'])
            ->name('active-sessions.create')
            ->middleware('permission:shared_sessions.manage');

        Route::middleware('permission:shared_sessions.manage')->group(function () {
            Route::post('/shared-sessions', [SharedSessionController::class, 'store'])->name('shared-sessions.store');
            Route::get('/shared-sessions/{session}/close-preview', [SharedSessionController::class, 'closePreview'])->name('shared-sessions.close-preview');
            Route::post('/shared-sessions/{session}/close', [SharedSessionController::class, 'close'])->name('shared-sessions.close');
        });

        // Running-tab items on an open session need BOTH booking and sales features.
        Route::middleware(['feature:sales', 'permission:shared_sessions.manage'])->group(function () {
            Route::post('/shared-sessions/{session}/items', [SharedSessionController::class, 'addItem'])->name('shared-sessions.items.add');
            Route::patch('/shared-sessions/{session}/items/{item}', [SharedSessionController::class, 'updateItemQuantity'])->name('shared-sessions.items.update');
            Route::delete('/shared-sessions/{session}/items/{item}', [SharedSessionController::class, 'removeItem'])->name('shared-sessions.items.remove');
        });

        Route::middleware('permission:bookings.view')->group(function () {
            Route::get('/bookings/calendar', [BookingController::class, 'calendar']);
            Route::get('/bookings/availability', [BookingController::class, 'availabilityLookup']);
            Route::get('/bookings/check-availability', [BookingController::class, 'checkAvailability']);
            Route::get('/bookings/availability-range', [BookingController::class, 'availabilityRange']);
            Route::get('/bookings/room-options', [BookingController::class, 'roomOptions']);
            Route::get('/bookings', [BookingController::class, 'index']);
        });

        // Hour Packages: the member's usable packages for the booking form,
        // quick popup and start-session modal (before the {booking} wildcard).
        Route::get('/bookings/package-options', [BookingController::class, 'packageOptions'])
            ->middleware('permission:bookings.view,shared_sessions.manage');

        // Hour Package templates + assigning/cancelling a member's packages.
        Route::get('/packages', [PackageTemplateController::class, 'index'])->name('packages.index')->middleware('permission:packages.view');
        Route::middleware('permission:packages.manage')->group(function () {
            Route::get('/packages/create', [PackageTemplateController::class, 'create'])->name('packages.create');
            Route::post('/packages', [PackageTemplateController::class, 'store'])->name('packages.store');
            Route::get('/packages/{id}/edit', [PackageTemplateController::class, 'edit'])->name('packages.edit');
            Route::put('/packages/{id}', [PackageTemplateController::class, 'update'])->name('packages.update');
            Route::post('/packages/{id}/toggle', [PackageTemplateController::class, 'toggle'])->name('packages.toggle');
            Route::delete('/packages/{id}', [PackageTemplateController::class, 'destroy'])->name('packages.destroy');
        });
        Route::middleware('permission:packages.assign')->group(function () {
            Route::post('/users/{id}/packages', [MemberPackageController::class, 'store'])->name('member-packages.store');
            Route::post('/member-packages/{id}/cancel', [MemberPackageController::class, 'cancel'])->name('member-packages.cancel');
        });
        // /bookings/create must be registered before the /bookings/{booking}
        // wildcard below, or "create" gets swallowed as a booking ID.
        Route::middleware('permission:bookings.create')->group(function () {
            Route::get('/bookings/create', [BookingController::class, 'create']);
            Route::post('/bookings', [BookingController::class, 'store']);
        });
        Route::get('/bookings/{booking}', [BookingController::class, 'show'])->middleware('permission:bookings.view');
        Route::middleware('permission:bookings.edit')->group(function () {
            Route::get('/bookings/{booking}/edit', [BookingController::class, 'edit']);
            Route::put('/bookings/{booking}', [BookingController::class, 'update']);
            Route::post('/bookings/{booking}/check-in', [BookingController::class, 'checkIn']);
            Route::post('/bookings/{booking}/payment', [BookingController::class, 'recordPayment']);
            Route::get('/bookings/{booking}/close-preview', [BookingController::class, 'closePreview'])->name('bookings.close-preview');
            Route::post('/bookings/{booking}/close', [BookingController::class, 'close'])->name('bookings.close');
        });
        // Both routes carry the full booking status-machine; the controller
        // enforces the finer edit-vs-cancel distinction per the target status.
        Route::middleware('permission:bookings.edit,bookings.cancel')->group(function () {
            Route::post('/bookings/{booking}/status', [BookingController::class, 'updateStatus']);
        });
        Route::delete('/bookings/{booking}', [BookingController::class, 'destroy'])->middleware('permission:bookings.delete');

        // Attaching products to a booking needs BOTH booking and sales features.
        Route::middleware(['feature:sales', 'permission:bookings.edit'])->group(function () {
            Route::post('/bookings/{booking}/items', [BookingController::class, 'addItem'])->name('bookings.items.add');
            Route::patch('/bookings/{booking}/items/{item}', [BookingController::class, 'updateItemQuantity'])->name('bookings.items.update');
            Route::delete('/bookings/{booking}/items/{item}', [BookingController::class, 'removeItem'])->name('bookings.items.remove');
        });

        // Attaching a coupon to a still-editable booking needs both grants.
        Route::middleware(['permission:bookings.edit', 'permission:coupons.apply'])->group(function () {
            Route::post('/bookings/{booking}/coupon', [BookingController::class, 'applyCoupon'])->name('bookings.coupon.apply');
            Route::delete('/bookings/{booking}/coupon', [BookingController::class, 'removeCoupon'])->name('bookings.coupon.remove');
        });
    });

    // Sales feature routes (product catalog + sales history)
    Route::middleware('feature:sales')->group(function () {
        Route::get('/products', [ProductController::class, 'index'])->name('products.index')->middleware('permission:products.view');

        Route::middleware('permission:products.manage')->group(function () {
            Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
            Route::post('/products', [ProductController::class, 'store'])->name('products.store');
            Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
            Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
            Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
            Route::post('/products/{product}/toggle', [ProductController::class, 'toggleActive'])->name('products.toggle');
            // Inventory: restock / reduce-with-reason (InventoryService).
            Route::post('/products/{product}/stock', [ProductController::class, 'adjustStock'])->name('products.stock');
        });

        // After /products/create so "create" is never read as a product id.
        Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show')->middleware('permission:products.view');
    });

    // Financials — replaces the old Sales section with a unified view of
    // booking + shared-session + product revenue. Feature gate is an OR:
    // a room-only tenant still needs it for booking revenue, a
    // product-only tenant still needs it for product revenue.
    Route::middleware('feature:booking,sales')->group(function () {
        Route::get('/financials', [OwnerFinancialController::class, 'index'])->name('financials.index')->middleware('permission:financials.view');
        Route::get('/financials/transactions', [OwnerFinancialController::class, 'transactions'])->name('financials.transactions')->middleware('permission:financials.view');
        Route::get('/financials/transactions/{booking}', [OwnerFinancialController::class, 'show'])->name('financials.transactions.show')->middleware('permission:financials.view');
        Route::get('/financials/export', [OwnerFinancialController::class, 'export'])->name('financials.export')->middleware('permission:financials.export');

        // Expenses — additive layer alongside Revenue; Net = Revenue - Expenses.
        Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index')->middleware('permission:expenses.view');
        Route::middleware('permission:expenses.create')->group(function () {
            Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        });
        Route::middleware('permission:expenses.edit')->group(function () {
            Route::get('/expenses/{id}/edit', [ExpenseController::class, 'edit'])->name('expenses.edit');
            Route::put('/expenses/{id}', [ExpenseController::class, 'update'])->name('expenses.update');
        });
        Route::delete('/expenses/{id}', [ExpenseController::class, 'destroy'])->name('expenses.destroy')->middleware('permission:expenses.delete');

        Route::middleware('permission:expenses.manage_categories')->group(function () {
            Route::post('/expense-categories', [ExpenseCategoryController::class, 'store'])->name('expense-categories.store');
            Route::put('/expense-categories/{id}', [ExpenseCategoryController::class, 'update'])->name('expense-categories.update');
            Route::delete('/expense-categories/{id}', [ExpenseCategoryController::class, 'destroy'])->name('expense-categories.destroy');
        });

        // Coupons — discount codes for rooms and/or products & services.
        Route::get('/coupons', [CouponController::class, 'index'])->name('coupons.index')->middleware('permission:coupons.view');
        Route::middleware('permission:coupons.create')->group(function () {
            Route::get('/coupons/create', [CouponController::class, 'create'])->name('coupons.create');
            Route::post('/coupons', [CouponController::class, 'store'])->name('coupons.store');
        });
        Route::middleware('permission:coupons.edit')->group(function () {
            Route::get('/coupons/{id}/edit', [CouponController::class, 'edit'])->name('coupons.edit');
            Route::put('/coupons/{id}', [CouponController::class, 'update'])->name('coupons.update');
            Route::post('/coupons/{id}/toggle', [CouponController::class, 'toggleActive'])->name('coupons.toggle');
        });
        Route::delete('/coupons/{id}', [CouponController::class, 'destroy'])->name('coupons.destroy')->middleware('permission:coupons.delete');
    });

    // Retired — permanent redirects into Financials so old /sales links
    // (e.g. past notification action_url values) never 404. OR'd with
    // sales.view so staff who already held that permission aren't locked
    // out of the redirect itself during the transition.
    Route::middleware('feature:sales')->group(function () {
        Route::get('/sales', [SaleController::class, 'index'])->name('sales.index')->middleware('permission:sales.view,financials.view');
        Route::get('/sales/{sale}', [SaleController::class, 'show'])->name('sales.show')->middleware('permission:sales.view,financials.view');
    });

    Route::get('/settings', [SettingsController::class, 'index'])->middleware('permission:settings.view');
    // Router configuration is only writable/testable when the hotspot feature is on.
    Route::middleware(['feature:hotspot', 'permission:settings.manage'])->group(function () {
        Route::post('/settings', [SettingsController::class, 'update']);
        Route::post('/settings/test-connection', [SettingsController::class, 'testConnection']);
    });
    // Working hours are relevant to workspace/booking owners, not hotspot-only ones.
    Route::middleware(['feature:workspace,booking', 'permission:settings.manage'])->group(function () {
        Route::post('/settings/working-hours', [SettingsController::class, 'updateWorkingHours'])->name('settings.working-hours.update');
    });

    Route::get('/profile', [ProfileController::class, 'index']);
    Route::post('/profile/logo', [ProfileController::class, 'updateLogo'])->name('profile.logo.update');
    Route::delete('/profile/logo', [ProfileController::class, 'destroyLogo'])->name('profile.logo.destroy');

    // Notifications (cross-cutting — available to every authenticated owner)
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::get('/notifications/{id}/open', [NotificationController::class, 'open'])->name('notifications.open');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy'])->name('notifications.destroy');

    // Staff Management — owner-only, never reachable via the staff guard at
    // all (see CheckPermission's owner-bypass: this is enforced by the
    // guard restriction below, not by a permission flag, so staff can never
    // reach this surface regardless of what staff_permissions might say).
});

Route::middleware(['auth:owner', 'subscription.active', 'permission:staff.view'])->group(function () {
    Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
    Route::get('/staff/{staff}/activity', [StaffActivityController::class, 'show'])->name('staff.activity');
});

Route::middleware(['auth:owner', 'subscription.active', 'permission:staff.manage'])->group(function () {
    Route::get('/staff/create', [StaffController::class, 'create'])->name('staff.create');
    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
    Route::get('/staff/{staff}/edit', [StaffController::class, 'edit'])->name('staff.edit');
    Route::put('/staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
    Route::post('/staff/{staff}/toggle-status', [StaffController::class, 'toggleStatus'])->name('staff.toggle-status');
    Route::post('/staff/{staff}/reset-permissions', [StaffController::class, 'resetPermissions'])->name('staff.reset-permissions');
    Route::delete('/staff/{staff}', [StaffController::class, 'destroy'])->name('staff.destroy');
});

// ===================== Admin Auth =====================
// Unified sign-in: admins use the same /login form as owners. This route only
// exists to redirect legacy /admin/login links (and guest redirects) there.
Route::get('/admin/login', fn () => redirect()->route('login'))->name('admin.login');

Route::post('/admin/logout', [AuthController::class, 'logout']);

// ===================== Admin Routes (authenticated) =====================
Route::middleware('auth:admin')->prefix('admin')->group(function () {
    Route::get('/dashboard', [AdminDashboardController::class, 'index']);

    Route::get('/owners', [OwnerController::class, 'index']);
    Route::get('/owners/create', [OwnerController::class, 'create']);
    Route::post('/owners', [OwnerController::class, 'store']);
    // Business 360°: Overview (default), then tabs. The former owner page is
    // the Subscription tab.
    Route::get('/owners/{owner}', [AdminBusinessController::class, 'show'])->whereNumber('owner')->name('admin.business.show');
    Route::get('/owners/{owner}/subscription', [OwnerController::class, 'show'])->whereNumber('owner')->name('admin.business.subscription');
    Route::get('/owners/{owner}/audit', [AdminBusinessController::class, 'audit'])->whereNumber('owner')->name('admin.business.audit');
    Route::put('/owners/{owner}/toggle-active', [OwnerController::class, 'toggleActive']);
    Route::get('/owners/{owner}/users', [OwnerController::class, 'users']);

    Route::post('/owners/{owner}/renew', [SubscriptionController::class, 'renew']);

    // Owner-initiated renewal requests
    Route::get('/subscription-requests', [SubscriptionRequestController::class, 'index'])->name('admin.subscription-requests.index');
    Route::post('/subscription-requests/{id}/approve', [SubscriptionRequestController::class, 'approve'])->name('admin.subscription-requests.approve');
    Route::post('/subscription-requests/{id}/reject', [SubscriptionRequestController::class, 'reject'])->name('admin.subscription-requests.reject');

    // Admin → Owner notifications (broadcast)
    Route::get('/notifications', [AdminNotificationController::class, 'index'])->name('admin.notifications.index');
    Route::post('/notifications', [AdminNotificationController::class, 'store'])->name('admin.notifications.store');

    // Admin Feature Management
    Route::get('/features', [FeatureController::class, 'index']);
    Route::post('/features/{feature}/toggle-global', [FeatureController::class, 'toggleGlobal']);
    Route::post('/owners/{owner}/features/{feature}/toggle', [FeatureController::class, 'toggleForOwner']);

    // Admin Workspace (read-only)
    Route::get('/workspaces', [AdminWorkspaceController::class, 'index'])->name('admin.workspaces.index');
    Route::get('/workspaces/{workspace}', [AdminWorkspaceController::class, 'show'])->name('admin.workspaces.show');

    // Admin Bookings (read-only)
    Route::get('/bookings', [AdminBookingController::class, 'index'])->name('admin.bookings.index');
    Route::get('/bookings/{booking}', [AdminBookingController::class, 'show'])->name('admin.bookings.show');

    // Admin Plans Management
    Route::get('/plans', [PlanController::class, 'index'])->name('admin.plans.index');
    Route::get('/plans/create', [PlanController::class, 'create'])->name('admin.plans.create');
    Route::post('/plans', [PlanController::class, 'store'])->name('admin.plans.store');
    Route::get('/plans/{plan}/edit', [PlanController::class, 'edit'])->name('admin.plans.edit');
    Route::put('/plans/{plan}', [PlanController::class, 'update'])->name('admin.plans.update');
    Route::post('/plans/{plan}/toggle', [PlanController::class, 'toggle'])->name('admin.plans.toggle');

    // Admin Financial Dashboard
    Route::get('/financial', [FinancialController::class, 'index'])->name('admin.financial.index');
});

// A truly unmatched path has no route to attach the 'web' middleware group
// to, so SetLocale (and the session) never runs and the 404 page falls back
// to the default locale regardless of the visitor's actual session. Routing
// it through a real (fallback) route fixes that — same 404 response, but
// now locale-aware.
Route::fallback(fn () => abort(404));
