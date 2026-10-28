<?php

namespace App\Http\Resources\V1;

use App\Models\Room;
use App\Models\Workspace;
use App\Services\RoomPricingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Public view of a workspace. Every field is an explicit allow-list entry —
 * workspace/owner ids, owner contact, phone, place id, operational flags,
 * publication status/history and moderation notes are never included.
 * Coordinates appear only when the owner set a valid map pin.
 *
 * `detail` (single-workspace endpoint) adds the full gallery and room list;
 * the directory listing gets the card fields only.
 *
 * @mixin Workspace
 */
class PublicWorkspaceResource extends JsonResource
{
    private bool $detail = false;

    public function detailed(): static
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $rooms = $this->publicRooms();
        $primary = $this->primaryImage();
        $pricing = app(RoomPricingService::class);
        $cheapest = $rooms->sortBy(fn (Room $room) => $pricing->displayPrice($room))->first();

        $data = [
            'slug' => $this->slug,
            'name' => $this->name,
            'city' => $this->city,
            'address' => $this->address,
            'coordinates' => $this->publicCoordinates(),
            'description' => $this->description,
            'primary_image' => $primary ? new PublicImageResource($primary) : null,
            'rooms_count' => $rooms->count(),
            'starting_price' => $cheapest ? [
                'amount' => round($pricing->displayPrice($cheapest), 2),
                'currency' => 'EGP',
            ] : null,
        ];

        if ($this->detail) {
            $data['images'] = PublicImageResource::collection($this->images);
            $data['rooms'] = PublicRoomResource::collection($rooms);
        }

        return $data;
    }

    /**
     * The map pin the owner chose on the Create Workspace picker — only when
     * both values exist and are real coordinates (in range, not the 0,0
     * "null island" placeholder). Otherwise null; clients fall back to address.
     */
    private function publicCoordinates(): ?array
    {
        $lat = $this->latitude;
        $lng = $this->longitude;
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }
        $lat = (float) $lat;
        $lng = (float) $lng;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) {
            return null;
        }

        return ['latitude' => round($lat, 7), 'longitude' => round($lng, 7)];
    }

    /** Only rooms a customer could book: available, with a usable displayed price. */
    private function publicRooms(): Collection
    {
        return $this->rooms
            ->filter(fn (Room $room) => $room->is_available && $room->hasDisplayablePrice())
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }
}
