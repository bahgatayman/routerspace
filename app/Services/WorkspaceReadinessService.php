<?php

namespace App\Services;

use App\Models\Workspace;

/**
 * The single definition of "this workspace may appear on the public website".
 * Used when the owner submits, when an admin approves, and on every public
 * query — so a published workspace that later breaks a rule disappears from
 * public results without its status changing.
 *
 * Plan names never matter: Free/trial owners qualify exactly like paid ones as
 * long as their account and subscription are active (Owner::isSubscriptionActive).
 */
class WorkspaceReadinessService
{
    /** Display order of the checklist (and the keys used for messages). */
    public const CHECKS = ['name', 'location', 'description', 'primary_image', 'priced_room', 'owner_active', 'subscription'];

    public function __construct(private RoomPricingService $pricing) {}

    /**
     * @return array{ready: bool, checks: array<string, bool>, missing: list<string>}
     */
    public function check(Workspace $workspace): array
    {
        $owner = $workspace->relationLoaded('owner') ? $workspace->owner : $workspace->owner()->first();
        $images = $workspace->relationLoaded('images') ? $workspace->images : $workspace->images()->get();
        $rooms = $workspace->relationLoaded('rooms') ? $workspace->rooms : $workspace->rooms()->get();

        $checks = [
            'name' => $this->filled($workspace->name),
            'location' => $this->filled($workspace->address) && $this->filled($workspace->city),
            'description' => $this->filled($workspace->description),
            'primary_image' => $images->contains(fn ($image) => $image->is_primary),
            'priced_room' => $rooms->contains(fn ($room) => $room->is_available && $this->pricing->displayPrice($room) > 0),
            'owner_active' => (bool) $owner?->is_active,
            'subscription' => (bool) $owner?->isSubscriptionActive(),
        ];

        $missing = array_keys(array_filter($checks, fn ($ok) => ! $ok));

        return ['ready' => $missing === [], 'checks' => $checks, 'missing' => $missing];
    }

    public function isReady(Workspace $workspace): bool
    {
        return $this->check($workspace)['ready'];
    }

    private function filled(?string $value): bool
    {
        return trim((string) $value) !== '';
    }
}
