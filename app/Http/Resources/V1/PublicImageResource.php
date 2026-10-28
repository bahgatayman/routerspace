<?php

namespace App\Http\Resources\V1;

use App\Models\WorkspaceImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A workspace photo for the public API: URLs only — never storage paths or ids.
 *
 * @mixin WorkspaceImage
 */
class PublicImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'url' => $this->url(),
            'thumbnail_url' => $this->thumbnailUrl(),
            'is_primary' => (bool) $this->is_primary,
        ];
    }
}
