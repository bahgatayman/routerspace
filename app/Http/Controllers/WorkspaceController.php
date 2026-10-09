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
use Inertia\Inertia;
use Inertia\Response;

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
    public function index(Request $request, AvailabilityService $availability): Response
    {
        $workspaces = Workspace::where('owner_id', TenantContext::id())
            ->withCount('rooms')
            ->orderBy('name')
            ->get();

        if ($workspaces->isEmpty()) {
            return $this->renderIndex($request, $workspaces, null, collect(), null);
        }

        // firstWhere() searches within this already owner-scoped collection,
        // never a raw Workspace::find() — a foreign/stale id simply won't
        // match, so another tenant's workspace can never be selected this way.
        $activeWorkspace = $workspaces->count() === 1
            ? $workspaces->first()
            : $workspaces->firstWhere('id', (int) $request->query('workspace')) ?? $workspaces->first();

        [$rooms, $roomStats] = $this->roomsForWorkspace($activeWorkspace, $request, $availability);

        return $this->renderIndex($request, $workspaces, $activeWorkspace, $rooms, $roomStats);
    }

    /**
     * Workspaces/Index props: explicit arrays only. Status, labels, pricing
     * summary and every URL are decided here (Room helpers / route()), the
     * page only renders them.
     *
     * @param  Collection<int, Workspace>  $workspaces
     * @param  Collection<int, Room>  $rooms
     */
    private function renderIndex(Request $request, Collection $workspaces, ?Workspace $active, Collection $rooms, ?array $roomStats): Response
    {
        return Inertia::render('Workspaces/Index', [
            'workspaces' => $workspaces->map(fn (Workspace $ws) => [
                'id' => $ws->id,
                'name' => $ws->name,
                'rooms_count' => (int) $ws->rooms_count,
                'url' => route('workspaces.index', ['workspace' => $ws->id]),
            ])->values(),
            'activeWorkspace' => $active ? [
                'id' => $active->id,
                'name' => $active->name,
                'create_room_url' => route('rooms.create', $active),
            ] : null,
            'rooms' => $rooms->map(function (Room $room) use ($active) {
                $statusKey = $room->statusKey($room->occupied_seats);
                $editUrl = route('rooms.edit', [$active, $room]);

                return [
                    'id' => $room->id,
                    'name' => $room->name,
                    'type_label' => $room->typeLabel(),
                    'capacity' => $room->capacity,
                    'description' => $room->description,
                    'is_available' => (bool) $room->is_available,
                    'status_key' => $statusKey,
                    'status_label' => $room->statusLabel($room->occupied_seats),
                    'pricing_summary' => $room->pricingSummary(),
                    'plans_count' => (int) $room->plans_count,
                    'plans_label' => $room->plans_count ? trans_choice('app.plans.count', $room->plans_count, ['count' => $room->plans_count]) : null,
                    'profiles_count' => (int) ($room->pricing_profiles_count ?? 0),
                    'profiles_label' => ($room->pricing_profiles_count ?? 0)
                        ? trans_choice('app.pricing_profiles.count', $room->pricing_profiles_count, ['count' => $room->pricing_profiles_count])
                        : null,
                    'edit_url' => $editUrl,
                    'toggle_url' => route('rooms.toggle', [$active, $room]),
                    'destroy_url' => route('rooms.destroy', [$active, $room]),
                ];
            })->values(),
            'roomStats' => $roomStats,
            'type' => $request->query('type') ?: null,
            'createWorkspaceUrl' => route('workspaces.create'),
        ]);
    }

    /**
     * Room list + stat strip for one workspace, in a fixed 2 queries
     * regardless of room count: one for the rooms themselves (with Custom
     * Plans counts and shared-room live seat sums folded in via
     * withCount/withSum), one for every exclusive room's live occupancy
     * (AvailabilityService::usedCapacityNowBulk — the batched counterpart
     * to usedCapacityNow()). Search is deliberately not handled here — it's
     * 100% client-side (<x-ui.search>), matching every other list page.
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
            ->orderBy('name')
            ->get();

        $exclusiveIds = $rooms->reject(fn (Room $r) => $r->isShared())->pluck('id')->all();
        $exclusiveUsage = $availability->usedCapacityNowBulk($exclusiveIds, $workspace->owner_id);

        $rooms->each(function (Room $room) use ($exclusiveUsage) {
            $room->occupied_seats = $room->isShared()
                ? (int) ($room->occupied_seats ?? 0)
                : (int) ($exclusiveUsage[$room->id] ?? 0);
        });

        $roomStats = [
            'total' => $rooms->count(),
            'available' => $rooms->filter(fn (Room $r) => $r->statusKey($r->occupied_seats) === 'available')->count(),
            'occupied' => $rooms->filter(fn (Room $r) => $r->statusKey($r->occupied_seats) === 'occupied')->count(),
            'unavailable' => $rooms->filter(fn (Room $r) => $r->statusKey($r->occupied_seats) === 'unavailable')->count(),
        ];

        return [$rooms, $roomStats];
    }

    public function create(): Response
    {
        return Inertia::render('Workspaces/Create');
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

    public function edit(int $id): Response
    {
        $workspace = Workspace::where('id', $id)
            ->where('owner_id', TenantContext::id())
            ->firstOrFail();

        return Inertia::render('Workspaces/Edit', [
            'workspace' => $workspace->only(['id', 'name', 'description', 'address', 'city', 'phone']),
        ]);
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
