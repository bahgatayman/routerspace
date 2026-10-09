<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Owner;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super Admin → Locations (the `workspaces` table: a business's physical
 * branches). Read-only. Rooms belong to a location; bookings reach a
 * location through their room.
 */
class WorkspaceController extends Controller
{
    public function index(Request $request): Response
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

        return Inertia::render('Admin/Locations/Index', [
            'locations' => $locations->through(fn (Workspace $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'address' => $l->address,
                'owner_id' => $l->owner_id,
                'business' => $l->owner?->business_name,
                'city' => $l->city,
                'rooms_count' => $l->rooms_count,
                'available_rooms_count' => $l->available_rooms_count,
                'is_active' => (bool) $l->is_active,
            ]),
            'owners' => Owner::has('workspaces')->orderBy('business_name')->get(['id', 'business_name', 'name'])
                ->map(fn ($o) => ['id' => $o->id, 'name' => $o->business_name ?: $o->name]),
            'filters' => ['q' => $search, 'owner' => $ownerId],
        ]);
    }

    public function show(int $id): Response
    {
        $location = Workspace::with(['owner:id,business_name,name', 'rooms' => fn ($q) => $q->orderBy('name')])->findOrFail($id);

        $bookings = Booking::where('owner_id', $location->owner_id)
            ->whereIn('room_id', $location->rooms->pluck('id'))
            ->selectRaw('room_id, COUNT(*) as n, SUM(CASE WHEN status = ? THEN amount_paid ELSE 0 END) as earned', ['completed'])
            ->groupBy('room_id')->get()->keyBy('room_id');

        return Inertia::render('Admin/Locations/Show', [
            'location' => [
                'id' => $location->id,
                'name' => $location->name,
                'is_active' => (bool) $location->is_active,
                'owner_id' => $location->owner_id,
                'business' => $location->owner?->business_name,
                'meta' => array_values(array_filter([$location->city, $location->address, $location->phone])),
                'description' => $location->description,
            ],
            'rooms' => $location->rooms->map(fn ($room) => [
                'id' => $room->id,
                'name' => $room->name,
                'type' => $room->typeLabel(),
                'capacity' => $room->capacity,
                'pricing' => $room->pricingSummary(),
                'bookings' => (int) ($bookings[$room->id]->n ?? 0),
                'earned' => (float) ($bookings[$room->id]->earned ?? 0),
                'is_available' => (bool) $room->is_available,
            ])->values(),
        ]);
    }
}
