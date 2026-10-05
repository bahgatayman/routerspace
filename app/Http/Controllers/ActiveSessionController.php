<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Room;
use App\Models\SaleItem;
use App\Services\RoomPricingService;
use App\Support\ActiveSessionsQuery;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActiveSessionController extends Controller
{
    public function index(Request $request, RoomPricingService $pricing): View
    {
        $owner = TenantContext::user();
        $roomId = $request->get('room_id') ? (int) $request->get('room_id') : null;

        $allSessions = ActiveSessionsQuery::build($owner->id);

        // Room filter pills: only rooms that currently have at least one
        // active session, each carrying its own live count — an idle room
        // simply never appears, so this list can't grow noisy on its own.
        $roomCounts = $allSessions
            ->groupBy(fn ($row) => $row->room->id)
            ->map(fn ($rows) => ['room' => $rows->first()->room, 'count' => $rows->count()])
            ->sortBy(fn (array $entry) => $entry['room']->name)
            ->values();

        $sessions = $roomId
            ? $allSessions->filter(fn ($row) => $row->room->id === $roomId)->values()
            : $allSessions;

        // Shared-room seat occupancy — a distinct capacity metric (seats
        // used vs. free) from the session-card count above, since one card
        // with party_size > 1 occupies several seats at once.
        $sharedRooms = Room::where('owner_id', $owner->id)
            ->where('type', 'shared')
            ->withSum(['sharedSessions as occupied_seats' => function ($q) {
                $q->where('status', 'open');
            }], 'party_size')
            ->with(['workspace', 'activePricingProfiles'])
            ->get();

        // Catalog for the add-product picker (only when the sales feature is on).
        $products = $owner->hasFeature('sales')
            ? Product::where('owner_id', $owner->id)->where('is_active', true)->orderBy('name')->get()
            : collect();

        // Quick-add chips on each card: the owner's best sellers among active
        // products (by quantity sold), falling back to catalog order. Read-only.
        $quickProducts = collect();
        if ($products->isNotEmpty()) {
            $ranked = SaleItem::query()
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->where('sales.owner_id', $owner->id)
                ->whereIn('sale_items.product_id', $products->pluck('id'))
                ->groupBy('sale_items.product_id')
                ->orderByRaw('SUM(sale_items.quantity) DESC')
                ->limit(3)
                ->pluck('sale_items.product_id');
            $quickProducts = $ranked->map(fn ($id) => $products->firstWhere('id', $id))->filter()
                ->concat($products->whereNotIn('id', $ranked))
                ->take(3)
                ->values();
        }

        // Running-bill estimate for each open shared session AND each open
        // exclusive-room booking (Open Session), computed with the same
        // pricing service the close/preview endpoints use — display only,
        // the authoritative charge is still computed at close time. Keyed by
        // "{type}-{id}", not just id: shared_sessions.id and bookings.id are
        // independent sequences, so a SharedSession #5 and a Booking #5
        // would otherwise silently overwrite each other's estimate.
        $now = now();
        $estimates = $allSessions->filter(fn ($row) => $row->isShared() || $row->model->isOpenSession())
            ->mapWithKeys(fn ($row) => [
                $row->type.'-'.$row->model->id => $row->isShared()
                    ? $pricing->quoteSession($row->model, $now)
                    : $pricing->quoteOpenBooking($row->model, $now),
            ]);

        return view('active-sessions.index', [
            'sessions' => $sessions,
            'roomCounts' => $roomCounts,
            'totalCount' => $allSessions->count(),
            'selectedRoomId' => $roomId,
            'sharedRooms' => $sharedRooms,
            'products' => $products,
            'quickProducts' => $quickProducts,
            'estimates' => $estimates,
            'allSessions' => $allSessions,
        ]);
    }
}
