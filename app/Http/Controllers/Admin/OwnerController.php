<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\AdminAuditLogger;
use Illuminate\Http\Request;

class OwnerController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->get('search');

        $owners = Owner::withCount('hotspotUsers')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('business_name', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.owners.index', compact('owners', 'search'));
    }

    public function create()
    {
        return view('admin.owners.create');
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

    public function show($id)
    {
        $owner = Owner::with(['features', 'plan', 'workspaces'])->findOrFail($id);
        $subscriptions = Subscription::with('admin')
            ->where('owner_id', $id)
            ->latest()
            ->get();
        $usersCount = $owner->hotspotUsers()->count();
        $features = Feature::all();
        $plans = Plan::orderBy('sort_order')->get();

        return view('admin.owners.show', compact('owner', 'subscriptions', 'usersCount', 'features', 'plans'));
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

    public function users($id)
    {
        $owner = Owner::with(['plan', 'workspaces'])->findOrFail($id);
        $users = $owner->hotspotUsers()->latest()->paginate(15);

        return view('admin.owners.users', compact('owner', 'users'));
    }
}
