<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Services\ActivityLogger;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function __construct(private ActivityLogger $activityLogger) {}

    public function index(Request $request): Response
    {
        $search = $request->get('search');
        $status = in_array($request->get('status'), ['active', 'disabled'], true) ? $request->get('status') : null;
        $ownerId = TenantContext::id();

        $staff = Staff::where('owner_id', $ownerId)
            ->with('role')
            ->withCount('permissions')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($status, fn ($q) => $q->where('is_active', $status === 'active'))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        // Filter chip counts (whole team, not just this page).
        $counts = [
            'all' => Staff::where('owner_id', $ownerId)->count(),
            'active' => Staff::where('owner_id', $ownerId)->where('is_active', true)->count(),
        ];
        $counts['disabled'] = $counts['all'] - $counts['active'];

        return Inertia::render('Staff/Index', [
            'staff' => $staff->through(fn (Staff $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'is_active' => (bool) $member->is_active,
                'has_role' => (bool) $member->role,
                'role_label' => $member->role ? __('app.role.'.$member->role->key) : __('app.staff.custom_permissions'),
                'permissions_count' => $member->permissions_count,
                'last_login_title' => $member->last_login_at?->format('M d, Y H:i'),
                'last_login_ago' => $member->last_login_at?->diffForHumans(),
            ]),
            'search' => $search,
            'status' => $status,
            'counts' => $counts,
        ]);
    }

    public function create(): Response
    {
        $roles = Role::whereNull('owner_id')->orderBy('name')->get();
        $permissions = Permission::where('is_active', true)->orderBy('group')->orderBy('name')->get()->groupBy('group');

        return Inertia::render('Staff/Form', [
            'staff' => null,
            'roles' => $this->roleOptions($roles),
            'permissionGroups' => $this->permissionGroups($permissions),
            'granted' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateStaff($request);

        $staff = Staff::create([
            'owner_id' => TenantContext::id(),
            'role_id' => $validated['role_id'] ?? null,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);

        $this->applyPermissions($staff, $request);

        $this->activityLogger->log('staff.created', $staff, "Created staff member {$staff->name}");

        return redirect('/staff')->with('success', 'Staff member added successfully.');
    }

    public function edit(Staff $staff): Response
    {
        $this->authorizeStaff($staff);

        $roles = Role::whereNull('owner_id')->orderBy('name')->get();
        $permissions = Permission::where('is_active', true)->orderBy('group')->orderBy('name')->get()->groupBy('group');
        $grantedPermissionIds = $staff->permissions()->pluck('permissions.id')->all();

        return Inertia::render('Staff/Form', [
            'staff' => [
                'id' => $staff->id,
                'name' => $staff->name,
                'email' => $staff->email,
                'role_id' => $staff->role_id,
                'is_active' => (bool) $staff->is_active,
            ],
            'roles' => $this->roleOptions($roles),
            'permissionGroups' => $this->permissionGroups($permissions),
            'granted' => array_values(array_map('intval', $grantedPermissionIds)),
        ]);
    }

    public function update(Request $request, Staff $staff): RedirectResponse
    {
        $this->authorizeStaff($staff);

        $validated = $this->validateStaff($request, $staff);

        $staff->fill([
            'role_id' => $validated['role_id'] ?? null,
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);

        if (filled($validated['password'] ?? null)) {
            $staff->password = Hash::make($validated['password']);
        }

        $staff->save();

        $this->applyPermissions($staff, $request);

        $this->activityLogger->log('staff.updated', $staff, "Updated staff member {$staff->name}");

        return redirect('/staff')->with('success', 'Staff member updated successfully.');
    }

    public function toggleStatus(Staff $staff): RedirectResponse
    {
        $this->authorizeStaff($staff);

        $staff->update(['is_active' => ! $staff->is_active]);

        $this->activityLogger->log(
            'staff.status_toggled',
            $staff,
            "{$staff->name} ".($staff->is_active ? 'enabled' : 'disabled')
        );

        $status = $staff->is_active ? 'enabled' : 'disabled';

        return back()->with('success', "Staff member {$status}.");
    }

    public function resetPermissions(Staff $staff): RedirectResponse
    {
        $this->authorizeStaff($staff);

        $staff->syncPermissionsFromRole();

        $this->activityLogger->log('staff.permissions_changed', $staff, "Reset {$staff->name}'s permissions to role defaults");

        return back()->with('success', "Permissions reset to {$staff->role?->name} defaults.");
    }

    public function destroy(Staff $staff): RedirectResponse
    {
        $this->authorizeStaff($staff);

        // Soft delete only — bookings/sales/audit rows created by this staff
        // member must keep a valid actor to point back to.
        $staff->update(['is_active' => false]);
        $staff->delete();

        $this->activityLogger->log('staff.deleted', $staff, "Removed staff member {$staff->name}");

        return redirect('/staff')->with('success', 'Staff member removed.');
    }

    /** Role <select> options, each with the permission keys it bulk-checks in the grid. */
    private function roleOptions($roles): array
    {
        return $roles->map(fn (Role $role) => [
            'id' => $role->id,
            'label' => __('app.role.'.$role->key),
            'permission_keys' => $role->permissions->pluck('key')->values()->all(),
        ])->values()->all();
    }

    /** Permission catalog grouped like the old _permission-grid partial (every group, no role filtering). */
    private function permissionGroups($permissions): array
    {
        return $permissions->map(fn ($items, $group) => [
            'key' => $group,
            'label' => __('app.permission_group.'.$group),
            'items' => $items->map(fn (Permission $p) => [
                'id' => $p->id,
                'key' => $p->key,
                'label' => __('app.permission.'.$p->key),
            ])->values()->all(),
        ])->values()->all();
    }

    /**
     * Defense in depth: even though /staff/* is only ever reachable via the
     * auth:owner guard (never auth:staff), re-verify the staff row actually
     * belongs to this tenant before it's read or mutated.
     */
    private function authorizeStaff(Staff $staff): void
    {
        abort_unless($staff->owner_id === TenantContext::id(), 404);
    }

    private function validateStaff(Request $request, ?Staff $staff = null): array
    {
        $emailRule = Rule::unique('staff', 'email')->ignore($staff?->id);

        return $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', $emailRule],
            'password' => $staff ? 'nullable|string|min:8' : 'required|string|min:8',
            'role_id' => ['nullable', Rule::exists('roles', 'id')->where(fn ($q) => $q->whereNull('owner_id'))],
        ]);
    }

    /**
     * A submitted role sets the starting bundle; explicitly checked/unchecked
     * permission boxes always win over the role default, matching the
     * "roles + customizable permissions" model — the pivot table, not the
     * role, is the source of truth for what staff can actually do.
     */
    private function applyPermissions(Staff $staff, Request $request): void
    {
        $submitted = collect($request->input('permissions', []))->map(fn ($id) => (int) $id);
        $validIds = Permission::where('is_active', true)->whereIn('id', $submitted)->pluck('id');

        $grants = $validIds->mapWithKeys(fn ($id) => [$id => [
            'granted_at' => now(),
            'granted_by_owner_id' => TenantContext::id(),
        ]])->all();

        $staff->permissions()->sync($grants);
    }
}
