<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Models\Owner;

/**
 * Props for the Business 360° header (resources/js/Pages/Admin/Business/Header.jsx),
 * shared by every tab of one business. Expects the owner with plan + workspaces loaded.
 */
trait BusinessHeaderProps
{
    /** @return array<string, mixed> */
    protected function businessHeader(Owner $owner): array
    {
        $status = $owner->subscriptionStatus();

        return [
            'id' => $owner->id,
            'name' => $owner->business_name ?: $owner->name,
            'owner_name' => $owner->name,
            'email' => $owner->email,
            'plan' => $owner->plan?->name,
            'status' => $status,
            'status_tone' => match ($status) {
                'active' => 'ok', 'expiring_soon' => 'warn', 'never' => 'neutral', default => 'danger'
            },
            'expires' => $owner->subscription_expires_at?->translatedFormat('M j, Y'),
            'joined' => $owner->created_at?->translatedFormat('M j, Y'),
            'is_active' => (bool) $owner->is_active,
            'locations' => $owner->workspaces->sortBy('name')->values()
                ->map(fn ($ws) => ['id' => $ws->id, 'name' => $ws->name])->all(),
        ];
    }
}
