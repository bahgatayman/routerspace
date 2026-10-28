<?php

namespace App\Services;

use App\Models\Workspace;
use App\Support\Pricing\PricingRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The only way public-facing code may retrieve workspaces (customer website /
 * mobile API). A workspace is publicly visible only when it is `published` AND
 * passes WorkspaceReadinessService right now — so a published workspace that
 * loses its photo, prices, or subscription silently drops out until fixed.
 *
 * Two layers, both required:
 *  1. SQL pre-filter: every readiness rule that SQL can express exactly
 *     (status, slug, non-blank text fields, active owner + live subscription,
 *     a primary photo, an available room priced above zero or on a rule-based
 *     model). It only narrows — it never decides on its own.
 *  2. WorkspaceReadinessService on the loaded models makes the final call
 *     (e.g. a rule-based room whose lowest grid price is 0 — JSON, not SQL).
 *
 * Eager loads are limited to what readiness + the response need: listing loads
 * only the primary photo; rooms are only the available, possibly-priced ones,
 * without their long descriptions. Image rows are metadata only — files are
 * never read; URLs are built from stored paths.
 */
class PublicWorkspaceDirectory
{
    /** Room columns the public payload and readiness/pricing need (no description, buffers, billing). */
    // `id` is loaded for relation matching only; the API exposes `public_id`.
    private const ROOM_COLUMNS = ['id', 'public_id', 'workspace_id', 'name', 'type', 'capacity', 'price_per_hour', 'pricing_model', 'pricing_rules', 'is_available'];

    public function __construct(private WorkspaceReadinessService $readiness) {}

    /**
     * Candidate query. `$withGallery` loads every photo (detail page); otherwise
     * only the primary photo is loaded (listing cards).
     */
    public function candidates(bool $withGallery = false): Builder
    {
        $pricedRooms = fn ($q) => $q->where('is_available', true)
            ->where(fn ($w) => $w->where('price_per_hour', '>', 0)
                ->orWhere(fn ($r) => $r->whereNotNull('pricing_model')->where('pricing_model', '!=', PricingRules::HOURLY)));

        return Workspace::query()
            ->where('publication_status', Workspace::PUBLISHED)
            ->whereNotNull('slug')
            ->whereRaw("TRIM(COALESCE(name, '')) <> ''")
            ->whereRaw("TRIM(COALESCE(address, '')) <> ''")
            ->whereRaw("TRIM(COALESCE(city, '')) <> ''")
            ->whereRaw("TRIM(COALESCE(description, '')) <> ''")
            ->whereHas('owner', fn ($q) => $q->where('is_active', true)
                ->whereNotNull('subscription_expires_at')
                ->where('subscription_expires_at', '>', now()))
            ->whereHas('images', fn ($q) => $q->where('is_primary', true))
            ->whereHas('rooms', $pricedRooms)
            ->with([
                // Owner columns limited to what the readiness check needs; never serialized.
                'owner:id,is_active,subscription_expires_at',
                'images' => fn ($q) => $withGallery ? $q : $q->where('is_primary', true),
                'rooms' => fn ($q) => $pricedRooms($q)->select(self::ROOM_COLUMNS),
            ]);
    }

    /**
     * Eligible workspaces, newest publications first, paginated AFTER the live
     * readiness filter so totals and pages only ever count public workspaces.
     * Still loads every candidate (3 eager queries, no N+1); see docs/API.md
     * "Performance" for the measured cost and the proposed next step.
     */
    public function paginate(int $perPage, int $page, array $query = []): LengthAwarePaginator
    {
        $eligible = $this->all();

        return new LengthAwarePaginator(
            $eligible->forPage($page, $perPage)->values(),
            $eligible->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => $query],
        );
    }

    /** @return Collection<int, Workspace> */
    public function all(): Collection
    {
        return $this->candidates()->orderByDesc('published_at')->orderBy('id')->get()
            ->filter(fn (Workspace $ws) => $this->readiness->isReady($ws))
            ->values();
    }

    public function findBySlug(string $slug): ?Workspace
    {
        $workspace = $this->candidates(withGallery: true)->where('slug', $slug)->first();

        return $workspace && $this->readiness->isReady($workspace) ? $workspace : null;
    }

    /** For a workspace already in hand (e.g. admin screens): full, unfiltered readiness check. */
    public function isVisible(Workspace $workspace): bool
    {
        return $workspace->publicationStatus() === Workspace::PUBLISHED
            && $workspace->slug
            && $this->readiness->isReady($workspace);
    }
}
