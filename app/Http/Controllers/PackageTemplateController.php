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
use Illuminate\View\View;

/** Hour Package templates — the owner's reusable offers ("30 Hours Monthly"). */
class PackageTemplateController extends Controller
{
    public function index(): View
    {
        $owner = TenantContext::user();

        $templates = PackageTemplate::where('owner_id', $owner->id)
            ->withCount('memberPackages')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $staff = auth('staff')->user();
        $canManage = ! $staff || $staff->hasPermission('packages.manage');

        return view('packages.index', [
            'templates' => $templates,
            'roomNames' => Room::where('owner_id', $owner->id)->pluck('name', 'id'),
            'canManage' => $canManage,
            // Only needed to render the create modal, which is hidden when the
            // staff member can't manage packages anyway.
            'template' => $canManage ? new PackageTemplate(['is_active' => true, 'validity_days' => 30]) : null,
            'roomGroups' => $canManage ? $this->ownerRoomsGrouped($owner) : collect(),
        ]);
    }

    public function create(): View
    {
        return view('packages.create', [
            'template' => new PackageTemplate(['is_active' => true, 'validity_days' => 30]),
            'roomGroups' => $this->ownerRoomsGrouped(TenantContext::user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $owner = TenantContext::user();
        $data = $this->validated($request, $owner);

        PackageTemplate::create($data + ['owner_id' => $owner->id]);

        return redirect()->route('packages.index')->with('success', __('app.packages.template_created'));
    }

    public function edit(int $id): View
    {
        $owner = TenantContext::user();

        return view('packages.edit', [
            'template' => PackageTemplate::where('owner_id', $owner->id)->findOrFail($id),
            'roomGroups' => $this->ownerRoomsGrouped($owner),
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
