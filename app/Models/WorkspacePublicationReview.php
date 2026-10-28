<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry in a workspace's publication history (append-only). */
class WorkspacePublicationReview extends Model
{
    public const SUBMITTED = 'submitted';

    public const WITHDRAWN = 'withdrawn';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const UNPUBLISHED = 'unpublished';

    protected $fillable = [
        'workspace_id', 'owner_id', 'action', 'from_status', 'to_status',
        'actor_type', 'actor_id', 'actor_name', 'reason', 'readiness',
    ];

    protected function casts(): array
    {
        return [
            'readiness' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
