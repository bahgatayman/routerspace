<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    use HasFactory;

    // Publication on the public website (separate from the operational is_active flag).
    // Changed only through WorkspacePublicationService, so these fields are not fillable.
    public const DRAFT = 'draft';

    public const PENDING_REVIEW = 'pending_review';

    public const REJECTED = 'rejected';

    public const PUBLISHED = 'published';

    public const UNPUBLISHED = 'unpublished';

    public const PUBLICATION_STATUSES = [self::DRAFT, self::PENDING_REVIEW, self::REJECTED, self::PUBLISHED, self::UNPUBLISHED];

    protected $fillable = [
        'owner_id',
        'name',
        'description',
        'address',
        'city',
        'phone',
        'latitude',
        'longitude',
        'place_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'published_at' => 'datetime',
        ];
    }

    /** Publication history, newest first. */
    public function publicationReviews(): HasMany
    {
        return $this->hasMany(WorkspacePublicationReview::class)->latest('id');
    }

    public function publicationStatus(): string
    {
        return $this->publication_status ?: self::DRAFT;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function activeRooms()
    {
        return $this->rooms()->where('is_available', true);
    }

    public function roomsCount(): int
    {
        return $this->rooms()->count();
    }

    /** The workspace's own photo gallery (not room photos), in display order. */
    public function images(): HasMany
    {
        return $this->hasMany(WorkspaceImage::class)->orderBy('sort_order')->orderBy('id');
    }

    /** The primary photo; falls back to the first one so a gallery always has a face. */
    public function primaryImage(): ?WorkspaceImage
    {
        $images = $this->relationLoaded('images') ? $this->images : $this->images()->get();

        return $images->firstWhere('is_primary', true) ?? $images->first();
    }

    public function primaryImageUrl(): ?string
    {
        return $this->primaryImage()?->url();
    }

    public function primaryThumbnailUrl(): ?string
    {
        return $this->primaryImage()?->thumbnailUrl();
    }
}
