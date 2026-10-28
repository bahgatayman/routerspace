<?php

namespace App\Http\Resources\V1;

use App\Models\Room;
use App\Services\RoomPricingService;
use App\Support\Pricing\PricingRules;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe room summary for the public API. Prices come from RoomPricingService
 * (the only place a room price is decided) — never computed here. Exposes the
 * room's public UUID as `id` (a reference, not authorization) — never the
 * internal primary key — and no owner/workspace ids,
 * description, rules JSON, buffers or internal flags.
 *
 * @mixin Room
 */
class PublicRoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $pricing = app(RoomPricingService::class);

        return [
            // Public room identifier (rooms.public_id, a random UUID) — never the internal primary
            // key. Stable across renames; always used together with the workspace slug. It grants
            // nothing: every future endpoint must re-check that the room belongs to that public
            // workspace and is eligible for the operation.
            'id' => (string) $this->public_id,
            'name' => $this->name,
            'type' => $this->type,
            'type_label' => $this->typeLabel(),
            'capacity' => (int) $this->capacity,
            'price' => [
                'amount' => round($pricing->displayPrice($this->resource), 2),
                'currency' => 'EGP',
                // "From …" when the room is priced by duration/people tiers.
                'is_starting_price' => $this->pricingRules()->model !== PricingRules::HOURLY,
                'display' => $pricing->summary($this->resource),
            ],
        ];
    }
}
