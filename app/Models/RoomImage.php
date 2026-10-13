<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One photo in a Room's gallery. path/thumbnail_path are storage keys on the
 * 'public' disk (storage/app/public/room-images/{room_id}/...), never a raw
 * filesystem path — always resolve through url()/thumbnailUrl().
 */
class RoomImage extends Model
{
    protected $fillable = ['owner_id', 'room_id', 'path', 'thumbnail_path', 'is_cover', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_cover' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    /** Falls back to the full image when no thumbnail was generated (e.g. GD unavailable at upload time). */
    public function thumbnailUrl(): string
    {
        return Storage::disk('public')->url($this->thumbnail_path ?? $this->path);
    }

    /** Deletes the stored files first, then the row — call instead of plain delete() to avoid orphaned files. */
    public function deleteWithFile(): void
    {
        $disk = Storage::disk('public');
        foreach (array_filter([$this->path, $this->thumbnail_path]) as $file) {
            $disk->delete($file);
        }
        $this->delete();
    }
}
