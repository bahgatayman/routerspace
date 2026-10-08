<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Owner;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Super Admin → Locations (the `workspaces` table: a business's physical
 * branches). Read-only. Rooms belong to a location; bookings reach a
 * location through their room.
 */
class WorkspaceController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $ownerId = Owner::whereKey($request->integer('owner'))->value('id');

        $locations = Workspace::with('owner:id,business_name,name')
            ->withCount(['rooms', 'rooms as available_rooms_count' => fn ($q) => $q->where('is_available', true)])
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $w->where('name', 'like', $like)->orWhere('city', 'like', $like)->orWhere('address', 'like', $like)
                    ->orWhereHas('owner', fn ($o) => $o->where('business_name', 'like', $like));
            }))
            ->when($ownerId, fn ($q) => $q->where('owner_id', $ownerId))
            ->orderBy('owner_id')->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.locations.index', [
            'locations' => $locations,
            'owners' => Owner::has('workspaces')->orderBy('business_name')->get(['id', 'business_name', 'name']),
            'filters' => ['q' => $search, 'owner' => $ownerId],
        ]);
    }

    public function show(int $id): View
    {
        $location = Workspace::with(['owner:id,business_name,name', 'rooms' => fn ($q) => $q->orderBy('name')])->findOrFail($id);

        $bookings = Booking::where('owner_id', $location->owner_id)
            ->whereIn('room_id', $location->rooms->pluck('id'))
            ->selectRaw('room_id, COUNT(*) as n, SUM(CASE WHEN status = ? THEN amount_paid ELSE 0 END) as earned', ['completed'])
            ->groupBy('room_id')->get()->keyBy('room_id');

        return view('admin.locations.show', compact('location', 'bookings'));
    }
}
