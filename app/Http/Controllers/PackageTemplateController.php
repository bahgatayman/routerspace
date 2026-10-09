<?php

namespace App\Http\Controllers;

use App\Models\Owner;
use App\Models\PackageTemplate;
use App\Models\Room;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Hour Package templates — the owner's reusable offers ("30 Hours Monthly"). */
class PackageTemplateController extends Controller
{
    public function index(): Response
    {
        $owner = TenantContext::user();

        $templates = PackageTemplate::where('owner_id', $owner->id)
            ->withCount('memberPackages')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $staff = auth('staff')->user();

        $roomNames = Room::where('owner_id', $owner->id)->pluck('name', 'id');

        return Inertia::render('Packages/Index', [
            'templates' => $templates->map(function (PackageTemplate $t) use ($roomNames) {
                $hours = $t->total_minutes / 60;
                $perHour = $hours > 0 ? (float) $t->price / $hours : 0;
                $rooms = $t->room_ids ? collect($t->room_ids)->map(fn ($id) => $roomNames[$id] ?? null)->filter() : null;

                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'is_active' => (bool) $t->is_active,
                    'hours_label' => $t->hoursLabel(),
                    'validity_days' => $t->validity_days,
                    'price' => (float) $t->price,
                    'per_hour' => round($perHour, 2),
                    'rooms_label' => $rooms ? $rooms->implode(', ') : __('app.packages.all_rooms'),
                    'sold_count' => $t->member_packages_count,
                ];
            })->values(),
            'canManage' => ! $staff || $staff->hasPermission('packages.manage'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Packages/Form', [
            'template' => $this->formTemplate(new PackageTemplate(['is_active' => true, 'validity_days' => 30])),
            'roomGroups' => $this->roomGroupsForForm($this->ownerRoomsGrouped(TenantContext::user())),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $owner = TenantContext::user();
        $data = $this->validated($request, $owner);

        PackageTemplate::create($data + ['owner_id' => $owner->id]);

        return redirect()->route('packages.index')->with('success', __('app.packages.template_created'));
    }

    public function edit(int $id): Response
    {
        $owner = TenantContext::user();

        return Inertia::render('Packages/Form', [
            'template' => $this->formTemplate(PackageTemplate::where('owner_id', $owner->id)->findOrFail($id)),
            'roomGroups' => $this->roomGroupsForForm($this->ownerRoomsGrouped($owner)),
        ]);
    }

    /** Only affects packages assigned from now on — sold ones keep their snapshot. */
    public function update(Request $request, int $id): RedirectResponse
    {
        $owner = TenantContext::user();
        $template = PackageTemplate::where('owner_id', $owner->id)->findOrFail($id);

        $template->update($this->validated($request, $owner));

        return redirect()->route('packages.index')->with('success', __('app.packages.template_updated'));
    }

    public function toggle(int $id): RedirectResponse
    {
        $template = PackageTemplate::where('owner_id', TenantContext::id())->findOrFail($id);
        $template->update(['is_active' => ! $template->is_active]);

        return back()->with('success', __('app.packages.template_toggled'));
    }

    /** Deletable only while no member package came from it (deactivate it otherwise). */
    public function destroy(int $id): RedirectResponse
    {
        $template = PackageTemplate::where('owner_id', TenantContext::id())->findOrFail($id);

        if ($template->memberPackages()->exists()) {
            return back()->with('error', __('app.packages.delete_blocked'));
        }

        $template->delete();

        return redirect()->route('packages.index')->with('success', __('app.packages.template_deleted'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Owner $owner): array
    {
        $v = $request->validate([
            'name' => 'required|string|max:80',
            'hours' => 'required|numeric|min:0.25|max:10000',
            'price' => 'required|numeric|min:0|max:9999999.99',
            'validity_days' => 'required|integer|min:1|max:3650',
            'room_scope' => 'required|in:all,specific',
            'room_ids' => 'required_if:room_scope,specific|array',
            'room_ids.*' => ['integer', 'distinct', Rule::exists('rooms', 'id')->where('owner_id', $owner->id)],
        ]);

        return [
            'name' => $v['name'],
            'total_minutes' => (int) round((float) $v['hours'] * 60),
            'price' => $v['price'],
            'validity_days' => (int) $v['validity_days'],
            'room_ids' => $v['room_scope'] === 'specific' ? array_values(array_map('intval', $v['room_ids'] ?? [])) : null,
            'is_active' => $request->boolean('is_active'),
        ];
    }

    /** Form values as the old _form partial pre-filled them (hours shown without trailing zeros). */
    private function formTemplate(PackageTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name ?? '',
            'hours' => $template->total_minutes ? rtrim(rtrim(number_format($template->total_minutes / 60, 2, '.', ''), '0'), '.') : '',
            'price' => $template->price ?? '',
            'validity_days' => $template->validity_days ?? '',
            'room_scope' => empty($template->room_ids) ? 'all' : 'specific',
            'room_ids' => array_values(array_map('intval', $template->room_ids ?? [])),
            'is_active' => (bool) ($template->is_active ?? true),
        ];
    }

    private function roomGroupsForForm(Collection $groups): array
    {
        return $groups->map(fn ($rooms, $group) => [
            'label' => $group,
            'rooms' => $rooms->map(fn (Room $room) => ['id' => $room->id, 'name' => $room->name])->values()->all(),
        ])->values()->all();
    }

    /** @return Collection<string, Collection<int, Room>> */
    private function ownerRoomsGrouped(Owner $owner): Collection
    {
        return Room::where('owner_id', $owner->id)
            ->with('workspace')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Room $room) => $room->workspace?->name ?? __('app.workspace.rooms'));
    }
}
