<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\Owner;
use App\Models\Subscription;
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
            'name'              => 'required|string|max:255',
            'email'             => 'required|email|unique:owners,email',
            'password'          => 'required|string|min:8',
            'business_name'     => 'required|string|max:255',
            'mikrotik_host'     => 'required|string',
            'mikrotik_port'     => 'required|integer',
            'mikrotik_username' => 'required|string',
            'mikrotik_password' => 'required|string',
            'months'            => 'required|integer|min:1|max:24',
        ]);

        $owner = Owner::create([
            'name'              => $validated['name'],
            'email'             => $validated['email'],
            'password'          => bcrypt($validated['password']),
            'business_name'     => $validated['business_name'],
            'mikrotik_host'     => $validated['mikrotik_host'],
            'mikrotik_port'     => $validated['mikrotik_port'],
            'mikrotik_username' => $validated['mikrotik_username'],
            'mikrotik_password' => $validated['mikrotik_password'],
            'subscription_starts_at'  => now(),
            'subscription_expires_at' => now()->addMonths((int) $validated['months']),
            'is_active'               => true,
        ]);

        Subscription::create([
            'owner_id'   => $owner->id,
            'admin_id'   => auth('admin')->id(),
            'months'     => (int) $validated['months'],
            'starts_at'  => now(),
            'expires_at' => now()->addMonths((int) $validated['months']),
            'notes'      => 'Initial subscription on registration',
        ]);

        return redirect("/admin/owners/{$owner->id}")
            ->with('success', 'Owner created successfully with ' . $validated['months'] . '-month subscription.');
    }

    public function show($id)
    {
        $owner         = Owner::with('features')->findOrFail($id);
        $subscriptions = Subscription::with('admin')
            ->where('owner_id', $id)
            ->latest()
            ->get();
        $usersCount    = $owner->hotspotUsers()->count();
        $features      = Feature::all();

        return view('admin.owners.show', compact('owner', 'subscriptions', 'usersCount', 'features'));
    }

    public function toggleActive($id)
    {
        $owner = Owner::findOrFail($id);
        $owner->update(['is_active' => !$owner->is_active]);

        $status = $owner->is_active ? 'activated' : 'deactivated';
        return back()->with('success', "Owner {$status} successfully.");
    }

    public function users($id)
    {
        $owner = Owner::findOrFail($id);
        $users = $owner->hotspotUsers()->latest()->paginate(15);

        return view('admin.owners.users', compact('owner', 'users'));
    }
}
