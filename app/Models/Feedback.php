<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One response from the public feedback page. The submitted fields (rating,
 * category, product_area, message, details, contact_*) are never edited after
 * creation; Super Admins only change status / admin_notes.
 */
class Feedback extends Model
{
    protected $table = 'feedback';

    public const CATEGORIES = ['general', 'feature', 'bug', 'complaint', 'other'];

    /** Categories that ask one optional follow-up question (stored in `details`). */
    public const CATEGORIES_WITH_DETAILS = ['feature', 'bug'];

    public const AREAS = ['dashboard', 'bookings', 'rooms', 'sessions', 'sales', 'payments', 'internet', 'settings', 'other'];

    public const STATUSES = ['new', 'reviewing', 'planned', 'completed', 'rejected'];

    public const MESSAGE_MIN = 10;

    public const MESSAGE_MAX = 2000;

    protected $fillable = [
        'rating', 'category', 'product_area', 'message', 'details', 'contact_name', 'contact_email', 'locale',
        'owner_id', 'staff_id',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }

    public function isNew(): bool
    {
        return $this->status === 'new';
    }
}
