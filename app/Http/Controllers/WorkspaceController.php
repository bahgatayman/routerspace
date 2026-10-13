<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use App\Services\AvailabilityService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * The single entry point for /workspaces: Rooms are the primary content,
     * the workspace is just context. One workspace -> its rooms show
     * directly, no picker step. Zero -> the "create your workspace" empty
     * state becomes the whole page. Several -> a lightweight chip selector
     * (?workspace=) picks which one's rooms are shown; an unknown/foreign id
     * degrades to the owner's own first workspace rather than a 404.
     */
    public function index(Request $request, AvailabilityService $availability): View
    {
        $workspaces = Workspace::where('owner_id', TenantContext::id())
            ->withCount('rooms')
            ->orderBy('name')
            ->get();

        // View-only staff (workspaces.view without .manage) never see buttons that would just be refused.
        $staff = auth('staff')->user();
        $canManage = ! $staff || $staff->hasPermission('workspaces.manage');

        if ($workspaces->isEmpty()) {
            return view('workspaces.index', [
                'workspaces' => $workspaces,
                'activeWorkspace' => null,
                'rooms' => collect(),
                'roomStats' => null,
                'canManage' => $canManage,
            ]);
        }

        // firstWhere() searches within this already owner-scoped collection,
        // never a raw Workspace::find() — a foreign/stale id simply won't
        // match, so another tenant's workspace can never be selected this way.
        $activeWorkspace = $workspaces->count() === 1
            ? $workspaces->first()
            : $workspaces->firstWhere('id', (int) $request->query('workspace')) ?? $workspaces->first();

        [$rooms, $roomStats] = $this->roomsForWorkspace($activeWorkspace, $request, $availability);

        return view('workspaces.index', compact('workspaces', 'activeWorkspace', 'rooms', 'roomStats', 'canManage'));
    }

    /**
     * Room list + stat strip for one workspace, in a fixed 3 queries
     * regardless of room count: one for the rooms themselves (with Custom
     * Plans counts, shared-room live seat sums, and cover-photo images
     * eager-loaded), one for every exclusive room's live occupancy
     * (AvailabilityService::usedCapacityNowBulk — the batched counterpart
     * to usedCapacityNow()), one for all their gallery images (a plain
     * whereIn, not N+1). Search is deliberately not handled here — it's
     * 100% client-side (<x-ui.search>), matching every other list page.
     *
     * status/sort apply after occupancy is computed, since availability is a
     * derived value (Room::statusKey()), not a stored column — consistent
     * with how the existing `type` filter already shapes both the list and
     * the stat strip together (filtering is never faked with a hardcoded
     * status; it reads the same statusKey() every badge/stat already uses).
     *
     * @return array{0: Collection<int, Room>, 1: array{total: int, available: int, occupied: int, unavailable: int}}
     */
    private function roomsForWorkspace(Workspace $workspace, Request $request, AvailabilityService $availability): array
    {
        $type = $request->query('type');

        $rooms = Room::where('workspace_id', $workspace->id)
            ->where('owner_id', $workspace->owner_id)
            ->when($type, fn ($q) => $q->where('type', $type))
            ->withCount(['plans', 'activePricingProfiles as pricing_profiles_count'])
            ->withSum(['sharedSessions as occupied_seats' => fn ($q) => $q->where('status', 'open')], 'party_size')
            ->with('images')
            ->orderBy('name')
            ->get();

        $exclusiveIds = $rooms->reject(fn (Room $r) => $r->isShared())->pluck('id')->all();
        $exclusiveUsage = $availability->usedCapacityNowBulk($exclusiveIds, $workspace->owner_id);

        $rooms->each(function (Room $room) use ($exclusiveUsage) {
            $room->occupied_seats = $room->isShared()
                ? (int) ($room->occupied_seats ?? 0)
                : (int) ($exclusiveUsage[$room->id] ?? 0);
        });

        // Stats reflect the type filter (as before) but never the status filter
        // below — same "counts stay put while you switch tabs" behaviour as the
        // stock filter on the Products page, so the strip stays a stable total.
        $roomStats = [
            'total' => $rooms->count(),
            'available' => $rooms->filter(fn (Room $r) => $r->statusKey($r->occupied_seats) === 'available')->count(),
            'occupied' => $rooms->filter(fn (Room $r) => $r->statusKey($r->occupied_seats) === 'occupied')->count(),
            'unavailable' => $rooms->filter(fn (Room $r) => $r->statusKey($r->occupied_seats) === 'unavailable')->count(),
        ];

        $status = $request->query('status');
        if (in_array($status, ['available', 'occupied', 'unavailable'], true)) {
            $rooms = $rooms->filter(fn (Room $r) => $r->statusKey($r->occupied_seats) === $status)->values();
        }

        $sort = $request->query('sort');
        $rooms = match ($sort) {
            'price' => $rooms->sortBy(fn (Room $r) => (float) $r->price_per_hour)->values(),
            'availability' => $rooms->sortBy(fn (Room $r) => match ($r->statusKey($r->occupied_seats)) {
                'available' => 0, 'occupied' => 1, default => 2,
            })->values(),
            default => $rooms->sortBy(fn (Room $r) => mb_strtolower($r->name))->values(),
        };

        return [$rooms, $roomStats];
    }

    public function create(): View
    {
        return view('workspaces.create');
    }

    public function store(Request $request): RedirectResponse
    {
        if (! TenantContext::user()->canAddMoreWorkspaces()) {
            return back()->withInput()->with('error', __('app.plan_limit.workspaces'));
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:20',
        ]);

        $workspace = Workspace::create(array_merge($data, [
            'owner_id' => TenantContext::id(),
        ]));

        $this->activityLogger->log('workspace.created', $workspace, "Created workspace {$workspace->name}");

        return redirect()->route('workspaces.index', ['workspace' => $workspace->id])
            ->with('success', 'Workspace created successfully.');
    }

    public function edit(int $id): View
    {
        $workspace = Workspace::where('id', $id)
            ->where('owner_id', TenantContext::id())
            ->firstOrFail();

        return view('workspaces.edit', compact('workspace'));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $workspace = Workspace::where('id', $id)
            ->where('owner_id', TenantContext::id())
            ->firstOrFail();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:20',
        ]);

        $workspace->update($data);

        $this->activityLogger->log('workspace.updated', $workspace, "Updated workspace {$workspace->name}");

        return redirect()->route('workspaces.index', ['workspace' => $workspace->id])
            ->with('success', 'Workspace updated successfully.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $workspace = Workspace::where('id', $id)
            ->where('owner_id', TenantContext::id())
            ->firstOrFail();

        if ($workspace->rooms()->count() > 0) {
            return back()->with('error', 'Cannot delete workspace with rooms. Delete all rooms first.');
        }

        $this->activityLogger->log('workspace.deleted', $workspace, "Deleted workspace {$workspace->name}");

        $workspace->delete();

        return redirect()->route('workspaces.index')
            ->with('success', 'Workspace deleted successfully.');
    }

    public function toggleActive(int $id): RedirectResponse
    {
        $workspace = Workspace::where('id', $id)
            ->where('owner_id', TenantContext::id())
            ->firstOrFail();

        $workspace->update(['is_active' => ! $workspace->is_active]);

        $this->activityLogger->log('workspace.toggled', $workspace, "Workspace '{$workspace->name}' ".($workspace->is_active ? 'activated' : 'deactivated'));

        return back()->with(
            'success',
            "Workspace '{$workspace->name}' ".($workspace->is_active ? 'activated' : 'deactivated').'.'
        );
    }
}
