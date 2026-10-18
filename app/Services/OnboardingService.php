<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Product;
use App\Models\Room;
use App\Models\SharedSession;
use App\Models\Staff;
use App\Models\Workspace;
use App\Support\Pricing\PricingRules;
use Illuminate\Support\Facades\Schema;

/**
 * Setup tasks for a workspace owner, derived ONLY from real persisted data and
 * the owner's enabled modules — never from "mark as done" clicks. Every query is
 * scoped to the owner (the tenant), read-only, and safe to run repeatedly.
 *
 * Task kinds:
 *  - essential  → needed before the space is ready to run (location, rooms,
 *                 pricing, router when Hotspot is on)
 *  - optional   → helpful, never required (products, staff)
 *  - activation → the first real business action (booking/session, or first
 *                 internet user)
 *
 * A module that's off contributes no task at all, so it can never show up as
 * "incomplete". Nothing here blocks access to any page.
 */
class OnboardingService
{
    /** @var array<int, array> per-request memo, keyed by owner id */
    private array $memo = [];

    /**
     * @return array<int, array{key: string, kind: string, title: string, text: string, url: string, action: string, done: bool}>
     */
    public function tasks(Owner $owner): array
    {
        return $this->memo[$owner->id] ??= $this->build($owner);
    }

    /**
     * @return array{done: int, total: int, essential_done: int, essential_total: int, percent: int, essential_complete: bool, all_complete: bool, next: ?array}
     */
    public function summary(Owner $owner): array
    {
        $tasks = collect($this->tasks($owner));
        $essential = $tasks->where('kind', 'essential');
        $done = $tasks->where('done', true)->count();

        // Next step: unfinished essentials first, then the first business action, then optional extras.
        $next = $tasks->firstWhere(fn ($t) => ! $t['done'] && $t['kind'] === 'essential')
            ?? $tasks->firstWhere(fn ($t) => ! $t['done'] && $t['kind'] === 'activation')
            ?? $tasks->firstWhere(fn ($t) => ! $t['done']);

        return [
            'done' => $done,
            'total' => $tasks->count(),
            'essential_done' => $essential->where('done', true)->count(),
            'essential_total' => $essential->count(),
            'percent' => $tasks->count() ? (int) round($done / $tasks->count() * 100) : 100,
            'essential_complete' => $essential->every(fn ($t) => $t['done']),
            'all_complete' => $tasks->every(fn ($t) => $t['done']),
            'next' => $next,
        ];
    }

    /**
     * Onboarding state needs the owners.onboarding_* columns. If that migration
     * hasn't run yet, the value would read as NULL ("never welcomed") for every
     * owner and the dismissal couldn't be saved — the wizard would reopen on every
     * visit for everyone. So without the columns, onboarding simply stays off.
     */
    public function isAvailable(): bool
    {
        return self::$available ??= Schema::hasColumn('owners', 'onboarding_welcomed_at');
    }

    private static ?bool $available = null;

    /**
     * First dashboard visit of a new owner: the Welcome Wizard hasn't been
     * finished or closed yet. An owner who already created a workspace has
     * started setup on their own, so they aren't interrupted either.
     */
    public function shouldShowWelcome(Owner $owner): bool
    {
        return $this->isAvailable()
            && $owner->onboarding_welcomed_at === null
            && ! Workspace::where('owner_id', $owner->id)->exists();
    }

    public function markWelcomed(Owner $owner): void
    {
        if ($this->isAvailable() && $owner->onboarding_welcomed_at === null) {
            $owner->forceFill(['onboarding_welcomed_at' => now()])->save();
        }
    }

    /** Explicit, user-initiated replay of the Welcome Wizard on the next dashboard visit. */
    public function restartWelcome(Owner $owner): void
    {
        if ($this->isAvailable()) {
            $owner->forceFill(['onboarding_welcomed_at' => null, 'onboarding_checklist_hidden_at' => null])->save();
        }
    }

    public function setChecklistHidden(Owner $owner, bool $hidden): void
    {
        if ($this->isAvailable()) {
            $owner->forceFill(['onboarding_checklist_hidden_at' => $hidden ? now() : null])->save();
        }
    }

    private function build(Owner $owner): array
    {
        $t = fn (string $key, array $r = []) => __('app.onboarding.tasks.'.$key, $r);
        $tasks = [];

        if ($owner->hasFeature('workspace')) {
            $firstWorkspace = Workspace::where('owner_id', $owner->id)->orderBy('id')->first(['id']);
            $roomsQuery = Room::where('owner_id', $owner->id);
            $hasRoom = (clone $roomsQuery)->exists();

            // Priced = an available room with a price above zero, or a room on a
            // rule-based model (duration/people tiers are validated to have rules).
            $unpriced = (clone $roomsQuery)
                ->where('is_available', true)
                ->where(fn ($q) => $q->whereNull('pricing_model')->orWhere('pricing_model', PricingRules::HOURLY))
                ->where('price_per_hour', '<=', 0)
                ->orderBy('id')->first(['id', 'workspace_id']);
            $hasPricedRoom = (clone $roomsQuery)->where('is_available', true)
                ->where(fn ($q) => $q->where('price_per_hour', '>', 0)
                    ->orWhere(fn ($w) => $w->whereNotNull('pricing_model')->where('pricing_model', '!=', PricingRules::HOURLY)))
                ->exists();

            $tasks[] = [
                'key' => 'location', 'kind' => 'essential',
                'title' => $t('location.title'), 'text' => $t('location.text'), 'action' => $t('location.action'),
                'url' => '/workspaces/create',
                'done' => $firstWorkspace !== null,
            ];
            $tasks[] = [
                'key' => 'rooms', 'kind' => 'essential',
                'title' => $t('rooms.title'), 'text' => $t('rooms.text'), 'action' => $t('rooms.action'),
                'url' => $firstWorkspace ? "/workspaces/{$firstWorkspace->id}/rooms/create" : '/workspaces/create',
                'done' => $hasRoom,
            ];
            $tasks[] = [
                'key' => 'pricing', 'kind' => 'essential',
                'title' => $t('pricing.title'), 'text' => $t('pricing.text'), 'action' => $t('pricing.action'),
                'url' => $unpriced ? "/workspaces/{$unpriced->workspace_id}/rooms/{$unpriced->id}/edit" : '/workspaces',
                'done' => $hasPricedRoom,
            ];
        }

        if ($owner->hasFeature('hotspot')) {
            $tasks[] = [
                'key' => 'router', 'kind' => 'essential',
                'title' => $t('router.title'), 'text' => $t('router.text'), 'action' => $t('router.action'),
                'url' => '/settings',
                'done' => $owner->hasRouterConfigured(),
            ];
        }

        if ($owner->hasFeature('sales')) {
            $tasks[] = [
                'key' => 'products', 'kind' => 'optional',
                'title' => $t('products.title'), 'text' => $t('products.text'), 'action' => $t('products.action'),
                'url' => '/products/create',
                'done' => Product::where('owner_id', $owner->id)->exists(),
            ];
        }

        $tasks[] = [
            'key' => 'staff', 'kind' => 'optional',
            'title' => $t('staff.title'), 'text' => $t('staff.text'), 'action' => $t('staff.action'),
            'url' => '/staff/create',
            'done' => Staff::where('owner_id', $owner->id)->exists(),
        ];

        // First real business action, from the modules the owner actually uses.
        if ($owner->hasFeature('booking') && $owner->hasFeature('workspace')) {
            $tasks[] = [
                'key' => 'first_booking', 'kind' => 'activation',
                'title' => $t('first_booking.title'), 'text' => $t('first_booking.text'), 'action' => $t('first_booking.action'),
                'url' => '/bookings/create',
                'done' => Booking::where('owner_id', $owner->id)->exists()
                    || SharedSession::where('owner_id', $owner->id)->exists(),
            ];
        } elseif ($owner->hasFeature('hotspot')) {
            $tasks[] = [
                'key' => 'first_member', 'kind' => 'activation',
                'title' => $t('first_member.title'), 'text' => $t('first_member.text'), 'action' => $t('first_member.action'),
                'url' => '/users/create',
                'done' => HotspotUser::where('owner_id', $owner->id)->exists(),
            ];
        }

        return $tasks;
    }
}
