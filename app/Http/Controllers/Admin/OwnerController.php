<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\BusinessHeaderProps;
use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\AdminAuditLogger;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OwnerController extends Controller
{
    use BusinessHeaderProps;

    // The owners list moved to WorkspaceDirectoryController (/admin/workspaces).

    public function create(): Response
    {
        return Inertia::render('Admin/Owners/Create', [
            'plans' => $this->planOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:owners,email',
            'password' => 'required|string|min:8',
            'business_name' => 'required|string|max:255',
            'mikrotik_host' => 'nullable|string',
            'mikrotik_port' => 'nullable|integer',
            'mikrotik_username' => 'nullable|string',
            'mikrotik_password' => 'nullable|string',
            'plan_id' => 'required|exists:plans,id',
            'months' => 'required|integer|min:1|max:24',
        ]);

        $plan = Plan::findOrFail($validated['plan_id']);

        $owner = Owner::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => bcrypt($validated['password']),
            'business_name' => $validated['business_name'],
            'mikrotik_host' => $validated['mikrotik_host'] ?? null,
            'mikrotik_port' => $validated['mikrotik_port'] ?? 8728,
            'mikrotik_username' => $validated['mikrotik_username'] ?? null,
            'mikrotik_password' => $validated['mikrotik_password'] ?? null,
            'plan_id' => $plan->id,
            'subscription_starts_at' => now(),
            'subscription_expires_at' => now()->addMonths((int) $validated['months']),
            'is_active' => true,
        ]);

        // Turn on the plan's default features for this owner.
        $owner->applyPlanFeatures();

        Subscription::create([
            'owner_id' => $owner->id,
            'admin_id' => auth('admin')->id(),
            'plan_id' => $plan->id,
            'months' => (int) $validated['months'],
            'amount_paid' => $plan->price_per_month * (int) $validated['months'],
            'starts_at' => now(),
            'expires_at' => now()->addMonths((int) $validated['months']),
            'notes' => 'Initial subscription on registration',
        ]);

        app(AdminAuditLogger::class)->log('owner.created', $owner,
            "Created business '{$owner->business_name}' on {$plan->name} for {$validated['months']} month(s)",
            ['plan_id' => $plan->id, 'months' => (int) $validated['months']]);

        return redirect("/admin/owners/{$owner->id}")
            ->with('success', 'Owner created successfully with '.$validated['months'].'-month subscription.');
    }

    public function show($id): Response
    {
        $owner = Owner::with(['features', 'plan', 'workspaces'])->findOrFail($id);
        $subscriptions = Subscription::with('admin')
            ->where('owner_id', $id)
            ->latest()
            ->get();
        $usersCount = $owner->hotspotUsers()->count();
        $features = Feature::all();
        $renewBase = $owner->subscription_expires_at?->isFuture() ? $owner->subscription_expires_at : now();

        return Inertia::render('Admin/Owners/Show', [
            'business' => $this->businessHeader($owner),
            'owner' => [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
                'business_name' => $owner->business_name,
                'plan_id' => $owner->plan_id,
                'has_hotspot' => $owner->hasFeature('hotspot'),
                'mikrotik_host' => $owner->mikrotik_host ? $owner->mikrotik_host.':'.$owner->mikrotik_port : null,
                'mikrotik_username' => $owner->mikrotik_username,
                'status' => $owner->subscriptionStatus(),
                'days_left' => $owner->daysUntilExpiry(),
                'expires_at' => $owner->subscription_expires_at?->format('Y-m-d'),
                'default_until' => $owner->subscription_expires_at?->copy()->addMonths(1)->format('Y-m-d'),
                'renew_base' => $renewBase->format('Y-m-d'),
            ],
            'usersCount' => $usersCount,
            'plans' => $this->planOptions(),
            'subscriptions' => $subscriptions->map(fn (Subscription $sub) => [
                'id' => $sub->id,
                'months' => $sub->months,
                'starts_at' => $sub->starts_at->format('Y-m-d'),
                'expires_at' => $sub->expires_at->format('Y-m-d'),
                'notes' => $sub->notes,
                'admin' => $sub->admin?->name,
                'date' => $sub->created_at->format('Y-m-d'),
            ])->all(),
            'features' => $features->map(fn (Feature $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'description' => $f->description,
                'icon' => $f->icon,
                'is_active' => (bool) $f->is_active,
                'enabled' => $owner->features->contains('id', $f->id),
            ])->all(),
        ]);
    }

    /** Suspend or re-activate a business. The optional reason is kept in the admin audit log. */
    public function toggleActive(Request $request, $id)
    {
        $validated = $request->validate(['reason' => 'nullable|string|max:500']);

        $owner = Owner::findOrFail($id);
        $owner->update(['is_active' => ! $owner->is_active]);

        $status = $owner->is_active ? 'activated' : 'deactivated';
        app(AdminAuditLogger::class)->log(
            $owner->is_active ? 'owner.activated' : 'owner.suspended',
            $owner,
            ($owner->is_active ? 'Activated' : 'Suspended')." business '{$owner->business_name}'",
            [],
            $validated['reason'] ?? null,
        );

        return back()->with('success', "Owner {$status} successfully.");
    }

    public function users($id): Response
    {
        $owner = Owner::with(['plan', 'workspaces'])->findOrFail($id);
        $users = $owner->hotspotUsers()->latest()->paginate(15);

        return Inertia::render('Admin/Owners/Users', [
            'business' => $this->businessHeader($owner),
            'users' => $users->through(fn (HotspotUser $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'phone' => $user->phone,
                'speed_download' => $user->speed_download,
                'speed_upload' => $user->speed_upload,
                'active' => $user->status === 'active',
                'created' => $user->created_at->format('Y-m-d'),
            ]),
        ]);
    }

    /** Plan cards for the create / renew forms (price_per_month only drives the on-page total preview). */
    private function planOptions(): array
    {
        return Plan::orderBy('sort_order')->get()->map(fn (Plan $plan) => [
            'id' => $plan->id,
            'name' => $plan->name,
            'max_members' => $plan->max_members,
            'price_label' => $plan->formattedPrice(),
            'price_per_month' => (float) $plan->price_per_month,
        ])->all();
    }
}
