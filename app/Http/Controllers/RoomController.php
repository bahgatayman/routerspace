<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use App\Services\AvailabilityService;
use App\Services\SharedSessionBillingService;
use App\Support\Pricing\PlanInput;
use App\Support\Pricing\PricingProfileInput;
use App\Support\Pricing\PricingRules;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RoomController extends Controller
{
    public function __construct(private ActivityLogger $activityLogger) {}

    private function getWorkspace(int $workspaceId): Workspace
    {
        return Workspace::where('id', $workspaceId)
            ->where('owner_id', TenantContext::id())
            ->firstOrFail();
    }

    private function getRoom(Workspace $workspace, int $roomId): Room
    {
        return Room::where('id', $roomId)
            ->where('workspace_id', $workspace->id)
            ->firstOrFail();
    }

    /** Keyed the same as the `type` column's allowed values, sourced from lang so the dropdown is bilingual. */
    private function roomTypeOptions(): array
    {
        return Lang::get('app.room_type');
    }

    /**
     * Validate the Pricing section and fold it into the room attributes.
     * pricing_model defaults to 'hourly' (a form or API client that never
     * sends it keeps the original behaviour). A rule-based room keeps its
     * last hourly price (or 0) in price_per_hour so switching back to hourly
     * later restores it; open shared sessions are unaffected either way
     * because they priced from a snapshot taken when they opened.
     */
    /**
     * Billing Buffer (grace minutes past each block boundary): only for a
     * shared room billed in blocks, and always shorter than one block — a
     * 30-minute grace on 30-minute blocks would never charge a second block.
     * Every other room stores 0 so a stray value can't linger.
     */
    private function validatedBuffer(array $data): int
    {
        $unitMinutes = app(SharedSessionBillingService::class)->unitMinutes($data['billing_unit']);
        if ($data['type'] !== 'shared' || $unitMinutes <= 1) {
            return 0;
        }

        $buffer = (int) ($data['billing_buffer_minutes'] ?? 0);
        if ($buffer >= $unitMinutes) {
            throw ValidationException::withMessages([
                'billing_buffer_minutes' => __('app.workspace.billing_buffer_too_long', ['max' => $unitMinutes - 1]),
            ]);
        }

        return $buffer;
    }

    private function withPricing(array $data, ?Room $room = null): array
    {
        $model = $data['pricing_model'] ?? PricingRules::HOURLY;
        [$rules, $errors] = PricingRules::fromInput($model, $data['pricing_rules'] ?? null);

        if ($errors) {
            throw ValidationException::withMessages(['pricing_rules' => $errors]);
        }

        $data['pricing_model'] = $model;
        $data['pricing_rules'] = $rules->toArray();
        $data['price_per_hour'] = $data['price_per_hour'] ?? $room?->price_per_hour ?? 0;

        return $data;
    }

    /**
     * The room form's Custom Plans, validated (PlanInput). Null when the
     * request doesn't carry the field at all, so a client that never sends
     * plans (API, older form) leaves a room's plans untouched.
     */
    private function validatedPlans(Request $request, array $data): ?array
    {
        if (! $request->has('plans')) {
            return null;
        }
        [$plans, $errors] = PlanInput::parse($request->input('plans'), $data['type'], (int) $data['capacity']);
        if ($errors) {
            throw ValidationException::withMessages(['plans' => $errors]);
        }

        return $plans;
    }

    /**
     * Make the room's plans match the submitted list: update the ones it kept
     * (matched by id, scoped to this room), create new ones, delete the rest.
     * Past bookings keep their price — room_plan_id is nulled on delete.
     */
    private function syncPlans(Room $room, array $plans): void
    {
        $existing = $room->plans()->get()->keyBy('id');
        $kept = [];

        foreach ($plans as $i => $p) {
            $attrs = [
                'name' => $p['name'], 'people' => $p['people'], 'duration_minutes' => $p['duration_minutes'],
                'is_full_day' => $p['is_full_day'], 'price' => $p['price'], 'sort_order' => $i,
            ];
            if ($p['id'] && $existing->has($p['id'])) {
                $existing[$p['id']]->update($attrs);
                $kept[] = $p['id'];
            } else {
                $kept[] = $room->plans()->create($attrs + ['owner_id' => $room->owner_id])->id;
            }
        }

        $room->plans()->whereNotIn('id', $kept)->delete();
    }

    /**
     * The room form's Pricing Profiles, validated (PricingProfileInput). Null
     * when the request doesn't carry the field, so other clients leave a
     * room's profiles untouched.
     */
    private function validatedProfiles(Request $request): ?array
    {
        if (! $request->has('pricing_profiles')) {
            return null;
        }
        [$profiles, $errors] = PricingProfileInput::parse($request->input('pricing_profiles'));
        if ($errors) {
            throw ValidationException::withMessages(['pricing_profiles' => $errors]);
        }

        return $profiles;
    }

    /**
     * Make the room's profiles match the submitted list (matched by id, scoped
     * to this room). A removed profile is deleted when nothing used it, else
     * only deactivated — bookings/sessions keep their id + name snapshot.
     */
    private function syncProfiles(Room $room, array $profiles): void
    {
        $existing = $room->pricingProfiles()->get()->keyBy('id');
        $kept = [];

        foreach ($profiles as $i => $p) {
            $attrs = ['name' => $p['name'], 'price_per_hour' => $p['price_per_hour'], 'is_active' => $p['is_active'], 'sort_order' => $i];
            if ($p['id'] && $existing->has($p['id'])) {
                $existing[$p['id']]->update($attrs);
                $kept[] = $p['id'];
            } else {
                $kept[] = $room->pricingProfiles()->create($attrs + ['owner_id' => $room->owner_id])->id;
            }
        }

        foreach ($existing->except($kept) as $removed) {
            $removed->isUsed() ? $removed->update(['is_active' => false]) : $removed->delete();
        }
    }

    public function create(int $workspaceId): View
    {
        $workspace = $this->getWorkspace($workspaceId);

        $roomTypes = $this->roomTypeOptions();

        return view('workspaces.rooms.create', compact('workspace', 'roomTypes'));
    }

    public function store(Request $request, int $workspaceId): RedirectResponse
    {
        $workspace = $this->getWorkspace($workspaceId);

        if (! TenantContext::user()->canAddMoreRooms()) {
            return back()->withInput()->with('error', __('app.plan_limit.rooms'));
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:meeting,training,shared,office,studio',
            'capacity' => 'required|integer|min:1|max:999',
            'price_per_hour' => 'required_unless:pricing_model,duration,people,people_duration|nullable|numeric|min:0',
            'billing_unit' => 'nullable|in:minute,half_hour,hour',
            'billing_buffer_minutes' => 'nullable|integer|min:0|max:'.SharedSessionBillingService::MAX_BUFFER_MINUTES,
            'pricing_model' => 'nullable|in:'.implode(',', PricingRules::MODELS),
            'pricing_rules' => 'nullable|string|max:20000',
            'plans' => 'nullable|string|max:20000',
            'description' => 'nullable|string|max:1000',
        ]);

        // Only meaningful for shared rooms — force 'minute' for every other
        // type so a stray submitted value can never linger on a room the
        // billing-unit field isn't even shown for.
        $data['billing_unit'] = $data['type'] === 'shared' ? ($data['billing_unit'] ?? 'minute') : 'minute';
        $data['billing_buffer_minutes'] = $this->validatedBuffer($data);
        $data = $this->withPricing($data);
        $plans = $this->validatedPlans($request, $data);
        $profiles = $this->validatedProfiles($request);
        unset($data['plans']);

        $room = DB::transaction(function () use ($data, $workspace, $plans, $profiles) {
            $room = Room::create(array_merge($data, [
                'workspace_id' => $workspace->id,
                'owner_id' => TenantContext::id(),
            ]));
            if ($plans !== null) {
                $this->syncPlans($room, $plans);
            }
            if ($profiles !== null) {
                $this->syncProfiles($room, $profiles);
            }

            return $room;
        });

        $this->activityLogger->log('room.created', $room, "Added room {$room->name} to {$workspace->name}");

        return redirect()->route('workspaces.index', ['workspace' => $workspace->id])
            ->with('success', 'Room added successfully.');
    }

    /** Read-only gallery + info view — gated by workspaces.view, not .manage, since it changes nothing. */
    public function show(int $workspaceId, int $roomId): View
    {
        $workspace = $this->getWorkspace($workspaceId);
        $room = $this->getRoom($workspace, $roomId)->load('images');

        return view('workspaces.rooms.show', compact('workspace', 'room'));
    }

    public function edit(int $workspaceId, int $roomId): View
    {
        $workspace = $this->getWorkspace($workspaceId);
        $room = $this->getRoom($workspace, $roomId)->load('images');

        $roomTypes = $this->roomTypeOptions();

        return view('workspaces.rooms.edit', compact('workspace', 'room', 'roomTypes'));
    }

    public function update(Request $request, int $workspaceId, int $roomId, AvailabilityService $availability): RedirectResponse
    {
        $workspace = $this->getWorkspace($workspaceId);
        $room = $this->getRoom($workspace, $roomId);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:meeting,training,shared,office,studio',
            'capacity' => 'required|integer|min:1|max:999',
            'price_per_hour' => 'required_unless:pricing_model,duration,people,people_duration|nullable|numeric|min:0',
            'billing_unit' => 'nullable|in:minute,half_hour,hour',
            'billing_buffer_minutes' => 'nullable|integer|min:0|max:'.SharedSessionBillingService::MAX_BUFFER_MINUTES,
            'pricing_model' => 'nullable|in:'.implode(',', PricingRules::MODELS),
            'pricing_rules' => 'nullable|string|max:20000',
            'plans' => 'nullable|string|max:20000',
            'description' => 'nullable|string|max:1000',
        ]);

        $data['billing_unit'] = $data['type'] === 'shared' ? ($data['billing_unit'] ?? 'minute') : 'minute';
        $data['billing_buffer_minutes'] = $this->validatedBuffer($data);
        $data = $this->withPricing($data, $room);
        $plans = $this->validatedPlans($request, $data);
        $profiles = $this->validatedProfiles($request);
        unset($data['plans']);

        // A type flip mid-occupancy is a bigger semantic break than a
        // capacity number changing, so it's blocked outright rather than
        // measured against usage: there is no "how many open sessions is
        // too many" threshold, any open session on this room makes the
        // room type itself still live.
        if ($data['type'] !== $room->type && $room->openSharedSessions()->exists()) {
            return back()->withInput()->with('error', __('app.workspace.type_change_blocked_open_session'));
        }

        // effectiveCapacity() under the *incoming* type/capacity — a
        // shared→exclusive flip pins this to 1 regardless of the submitted
        // capacity value, which doubles as protection against converting a
        // room away from shared while it still has future bookings for
        // more than one person (the type-change guard above only covers
        // currently-open sessions, not that case).
        $newEffectiveCapacity = $data['type'] === 'shared' ? $data['capacity'] : 1;
        $committedUsage = $availability->peakCommittedUsage($room);

        if ($newEffectiveCapacity < $committedUsage) {
            return back()->withInput()->with('error',
                __('app.workspace.capacity_below_committed_usage', ['count' => $committedUsage]));
        }

        DB::transaction(function () use ($room, $data, $plans, $profiles) {
            $room->update($data);
            if ($plans !== null) {
                $this->syncPlans($room, $plans);
            }
            if ($profiles !== null) {
                $this->syncProfiles($room, $profiles);
            }
        });

        $this->activityLogger->log('room.updated', $room, "Updated room {$room->name}");

        return redirect()->route('workspaces.index', ['workspace' => $workspace->id])
            ->with('success', 'Room updated successfully.');
    }

    public function destroy(int $workspaceId, int $roomId): RedirectResponse
    {
        $workspace = $this->getWorkspace($workspaceId);
        $room = $this->getRoom($workspace, $roomId);

        $this->activityLogger->log('room.deleted', $room, "Deleted room {$room->name}");

        $room->delete();

        return redirect()->route('workspaces.index', ['workspace' => $workspace->id])
            ->with('success', 'Room deleted successfully.');
    }

    public function toggleAvailable(int $workspaceId, int $roomId): RedirectResponse
    {
        $workspace = $this->getWorkspace($workspaceId);
        $room = $this->getRoom($workspace, $roomId);

        $room->update(['is_available' => ! $room->is_available]);

        $this->activityLogger->log('room.availability_toggled', $room, "Room '{$room->name}' marked as ".($room->is_available ? 'available' : 'unavailable'));

        return back()->with(
            'success',
            "Room '{$room->name}' marked as ".($room->is_available ? 'available' : 'unavailable').'.'
        );
    }
}
