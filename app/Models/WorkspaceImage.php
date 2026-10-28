<?php

namespace App\Models;

use App\Services\ImageUploadService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One photo in a Workspace's gallery. path/thumbnail_path are storage keys on
 * the 'public' disk, never exposed in serialized output — consumers get url /
 * thumbnail_url. New uploads: workspace-images/{random}.ext (no internal id);
 * older rows keep their original workspace-images/{workspace_id}/... path.
 * Code must always use the recorded path, never rebuild it.
 *
 * A reachable URL says nothing about whether the workspace is public: listing
 * eligibility is decided elsewhere, never by having images.
 */
class WorkspaceImage extends Model
{
    protected $fillable = ['owner_id', 'workspace_id', 'path', 'thumbnail_path', 'is_primary', 'sort_order'];

    protected $hidden = ['owner_id', 'path', 'thumbnail_path'];

    protected $appends = ['url', 'thumbnail_url'];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** Same convention as RoomImage::url(): asset() follows the request host instead of a hard-coded APP_URL. */
    public function url(): string
    {
        return asset('storage/'.$this->path);
    }

    /** Falls back to the full image when no thumbnail was generated. */
    public function thumbnailUrl(): string
    {
        return asset('storage/'.($this->thumbnail_path ?? $this->path));
    }

    public function getUrlAttribute(): string
    {
        return $this->url();
    }

    public function getThumbnailUrlAttribute(): string
    {
        return $this->thumbnailUrl();
    }

    /** Deletes this image's own files, then the row — never another image's files. */
    public function deleteWithFile(): void
    {
        app(ImageUploadService::class)->delete($this->path, $this->thumbnail_path);
        $this->delete();
    }
}
