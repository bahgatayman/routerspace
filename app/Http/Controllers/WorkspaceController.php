<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use App\Services\AvailabilityService;
use App\Services\OnboardingService;
use App\Support\PhoneNumber;
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

        // Every bookable room — another use of a physical room is its own card,
        // linked to the rooms it shares availability with (names only, for the label).
        $rooms = Room::where('workspace_id', $workspace->id)
            ->where('owner_id', $workspace->owner_id)
            ->when($type, fn ($q) => $q->where('type', $type))
            ->withCount(['plans', 'activePricingProfiles as pricing_profiles_count'])
            ->withSum(['sharedSessions as occupied_seats' => fn ($q) => $q->where('status', 'open')], 'party_size')
            ->with([
                'images',
                'physicalRoom' => fn ($q) => $q->where('owner_id', $workspace->owner_id)->select('id', 'name'),
                'uses' => fn ($q) => $q->where('owner_id', $workspace->owner_id)->select('id', 'name', 'physical_room_id'),
            ])
            ->orderBy('name')
            ->get();

        $exclusiveIds = $rooms->reject(fn (Room $r) => $r->isShared())->pluck('id')->all();
        $exclusiveUsage = $availability->usedCapacityNowBulk($exclusiveIds, $workspace->owner_id);
        // A room is occupied when any other use of the same physical room is in use right now.
        $heldByUse = $availability->heldByOtherUseNow($rooms);

        $rooms->each(function (Room $room) use ($exclusiveUsage, $heldByUse) {
            $room->occupied_seats = $room->isShared()
                ? (int) ($room->occupied_seats ?? 0)
                : (int) ($exclusiveUsage[$room->id] ?? 0);
            if ($heldByUse[$room->id] ?? false) {
                $room->occupied_seats = max($room->occupied_seats, $room->effectiveCapacity());
            }
        });

        // Stats reflect the type filter (as before) but never the status filter
        // below — same "counts stay put while you switch tabs" behaviour as the
        // stock filter on the Products page, so the strip stays a stable total.
        // 'total' counts bookable options (one card each, active or not — the
        // 'unavailable' tab holds the inactive ones); 'physical' counts the
        // distinct physical rooms behind them (what the plan's room limit counts).
        $roomStats = [
            'total' => $rooms->count(),
            'physical' => $rooms->map(fn (Room $r) => $r->inventoryRootId())->unique()->count(),
            'inactive' => $rooms->reject(fn (Room $r) => $r->is_available)->count(),
            'available' => $rooms->filter(fn (Room $r) => $r->statusKey($r->occupied_seats) === 'available')->count(),
            'occupied' => $rooms->filter(fn (Room $r) => $r->statusKey($r->occupied_seats) === 'occupied')->count(),
            'unavailable' => $rooms->filter(fn (Room $r) => $r->statusKey($r->occupied_seats) === 'unavailable')->count(),
        ];

        $status = $request->query('status');
        if (in_array($status, ['available', 'occupied', 'unavailable'], true)) {
            $rooms = $rooms->filter(fn (Room $r) => $r->statusKey($r->occupied_seats) === $status)->values();
        }

        // Uses of one physical room sit together: by name, a physical room is
        // followed by its uses; by price/availability, they only break ties.
        $group = fn (Room $r) => [
            mb_strtolower($r->isAdditionalUse() ? ($r->physicalRoom?->name ?? $r->name) : $r->name),
            $r->inventoryRootId(),
            $r->isAdditionalUse() ? 1 : 0,
            mb_strtolower($r->name),
        ];
        $sort = $request->query('sort');
        $key = match ($sort) {
            'price' => fn (Room $r) => [(float) $r->price_per_hour, ...$group($r)],
            'availability' => fn (Room $r) => [match ($r->statusKey($r->occupied_seats)) {
                'available' => 0, 'occupied' => 1, default => 2,
            }, ...$group($r)],
            default => $group,
        };
        $rooms = $rooms->sort(fn (Room $a, Room $b) => $key($a) <=> $key($b))->values();

        return [$rooms, $roomStats];
    }

    public function create(): View
    {
        $owner = TenantContext::user();
        $isFirst = ! Workspace::where('owner_id', $owner->id)->exists();

        // Only the FIRST workspace reuses the space name given at onboarding — and
        // only when it's a real name, not the sign-up default (the owner's own
        // name). Additional workspaces always start blank.
        $suggestedName = $isFirst && filled($owner->business_name) && $owner->business_name !== $owner->name
            ? $owner->business_name
            : null;

        return view('workspaces.create', [
            // null = unlimited on this plan; 0 = limit reached (store() refuses too).
            'remainingSlots' => $owner->remainingWorkspaceSlots(),
            'suggestedName' => $suggestedName,
            'mapsKey' => config('services.google_maps.key'),
            'mapsMapId' => config('services.google_maps.map_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! TenantContext::user()->canAddMoreWorkspaces()) {
            return back()->withInput()->with('error', __('app.plan_limit.workspaces'));
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'address' => 'required|string|max:500',
            'city' => 'required|string|max:100',
            'phone' => $this->mobileRules(),
            // From the map picker; optional, but coordinates come as a valid pair.
            'latitude' => 'nullable|numeric|between:-90,90|required_with:longitude',
            'longitude' => 'nullable|numeric|between:-180,180|required_with:latitude',
            'place_id' => 'nullable|string|max:255',
        ]);

        // A Google place id is only kept alongside real coordinates; it is a
        // reference for later lookups, never treated as a verified address.
        if (! isset($data['latitude'], $data['longitude'])) {
            $data['place_id'] = null;
        }

        $owner = TenantContext::user();
        $isFirst = ! Workspace::where('owner_id', $owner->id)->exists();

        $workspace = Workspace::create(array_merge($data, [
            'owner_id' => $owner->id,
        ]));

        $this->activityLogger->log('workspace.created', $workspace, "Created workspace {$workspace->name}");

        // Creating the first workspace means setup is under way: never pop the
        // first-visit Welcome Wizard up after this (the dashboard checklist stays).
        if ($isFirst && auth('owner')->check()) {
            app(OnboardingService::class)->markWelcomed(auth('owner')->user());
        }

        // First workspace: go straight to adding its first room (the natural next step).
        if ($isFirst) {
            return redirect()->route('rooms.create', $workspace->id)
                ->with('success', __('app.workspace.form.created_first', ['name' => $workspace->name]));
        }

        return redirect()->route('workspaces.index', ['workspace' => $workspace->id])
            ->with('success', __('app.workspace.form.created', ['name' => $workspace->name]));
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
            'address' => 'required|string|max:500',
            'city' => 'required|string|max:100',
            'phone' => $this->mobileRules(),
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

    /**
     * A workspace's contact number must be a valid Egyptian mobile (010/011/012/015,
     * any common shape — 01012345678, +201012345678…), checked with the same
     * PhoneNumber helper the rest of the app uses.
     *
     * @return array<int, mixed>
     */
    private function mobileRules(): array
    {
        return ['required', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail) {
            if (PhoneNumber::normalize((string) $value) === null) {
                $fail(__('app.workspace.form.phone_invalid'));
            }
        }];
    }
}
