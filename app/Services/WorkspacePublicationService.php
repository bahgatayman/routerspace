<?php

namespace App\Services;

use App\Exceptions\PublicationException;
use App\Models\Admin;
use App\Models\Owner;
use App\Models\Workspace;
use App\Models\WorkspacePublicationReview as Review;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only place a workspace's publication status changes.
 *
 *   draft ─submit→ pending_review ─approve→ published ─unpublish→ unpublished
 *                     │  ↑   └─reject→ rejected                     │
 *                  withdraw  └──────────── submit ──────────────────┘ (from rejected / unpublished)
 *
 * Owners submit/withdraw (their own workspaces only); admins approve/reject/
 * unpublish. Every change re-reads the row under a lock, writes the new status
 * and an append-only history row in one transaction, so concurrent clicks
 * can't double-apply. Readiness is enforced on submit AND re-checked on approve.
 */
class WorkspacePublicationService
{
    /** action => [allowed from-statuses, to-status] */
    private const TRANSITIONS = [
        Review::SUBMITTED => [[Workspace::DRAFT, Workspace::REJECTED, Workspace::UNPUBLISHED], Workspace::PENDING_REVIEW],
        Review::WITHDRAWN => [[Workspace::PENDING_REVIEW], Workspace::DRAFT],
        Review::APPROVED => [[Workspace::PENDING_REVIEW], Workspace::PUBLISHED],
        Review::REJECTED => [[Workspace::PENDING_REVIEW], Workspace::REJECTED],
        Review::UNPUBLISHED => [[Workspace::PUBLISHED], Workspace::UNPUBLISHED],
    ];

    public function __construct(
        private WorkspaceReadinessService $readiness,
        private NotificationService $notifications,
        private AdminAuditLogger $audit,
    ) {}

    /** Can this action run from the given status? (UI hint only — always re-checked server-side.) */
    public static function allows(string $action, string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$action][0] ?? [], true);
    }

    public function submit(Workspace $workspace, Owner $owner): Review
    {
        $this->assertOwns($workspace, $owner);

        return $this->transition($workspace, Review::SUBMITTED, $this->ownerActor($owner), null, requireReady: true);
    }

    public function withdraw(Workspace $workspace, Owner $owner): Review
    {
        $this->assertOwns($workspace, $owner);

        return $this->transition($workspace, Review::WITHDRAWN, $this->ownerActor($owner));
    }

    public function approve(Workspace $workspace, Admin $admin): Review
    {
        $review = $this->transition($workspace, Review::APPROVED, $this->adminActor($admin), null, requireReady: true);
        $this->afterAdminDecision($workspace, $review, 'success');

        return $review;
    }

    public function reject(Workspace $workspace, Admin $admin, string $reason): Review
    {
        $review = $this->transition($workspace, Review::REJECTED, $this->adminActor($admin), $this->reason($reason));
        $this->afterAdminDecision($workspace, $review, 'warning');

        return $review;
    }

    public function unpublish(Workspace $workspace, Admin $admin, string $reason): Review
    {
        $review = $this->transition($workspace, Review::UNPUBLISHED, $this->adminActor($admin), $this->reason($reason));
        $this->afterAdminDecision($workspace, $review, 'warning');

        return $review;
    }

    private function transition(Workspace $workspace, string $action, array $actor, ?string $reason = null, bool $requireReady = false): Review
    {
        [$from, $to] = self::TRANSITIONS[$action];

        $review = DB::transaction(function () use ($workspace, $action, $actor, $reason, $requireReady, $from, $to) {
            $locked = Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $current = $locked->publicationStatus();

            if (! in_array($current, $from, true)) {
                throw PublicationException::invalidTransition();
            }

            $result = $this->readiness->check($locked);
            if ($requireReady && ! $result['ready']) {
                throw PublicationException::notReady($result['missing']);
            }

            $changes = ['publication_status' => $to];
            if ($action === Review::SUBMITTED && ! $locked->slug) {
                $changes['slug'] = $this->uniqueSlug($locked);
            }
            if ($action === Review::APPROVED) {
                $changes['published_at'] = now();
            }
            $locked->forceFill($changes)->save();

            return Review::create([
                'workspace_id' => $locked->id,
                'owner_id' => $locked->owner_id,
                'action' => $action,
                'from_status' => $current,
                'to_status' => $to,
                'actor_type' => $actor['type'],
                'actor_id' => $actor['id'],
                'actor_name' => $actor['name'],
                'reason' => $reason,
                'readiness' => $result['missing'],
            ]);
        });

        $workspace->refresh();

        return $review;
    }

    /** Owner notification + admin audit trail for approve / reject / unpublish. */
    private function afterAdminDecision(Workspace $workspace, Review $review, string $level): void
    {
        $owner = $workspace->owner;
        $key = 'app.publication.notify.'.$review->action;

        $this->notifications->notify($owner, [
            'type' => 'publication_'.$review->action,
            'level' => $level,
            'title' => __($key.'.title', ['name' => $workspace->name]),
            'body' => $review->reason ? __($key.'.body_reason', ['reason' => $review->reason]) : __($key.'.body'),
            'action_url' => route('workspaces.edit', $workspace).'#workspace-publication',
            'reference' => 'publication-review:'.$review->id,
        ]);

        $this->audit->log(
            'publication.'.$review->action,
            $owner,
            __('app.publication.audit.'.$review->action, ['name' => $workspace->name]),
            ['workspace_id' => $workspace->id, 'from' => $review->from_status, 'to' => $review->to_status],
            $review->reason,
        );
    }

    /** URL-safe, unique, assigned once. Arabic names are transliterated by Str::slug. */
    private function uniqueSlug(Workspace $workspace): string
    {
        $base = Str::limit(Str::slug($workspace->name), 120, '') ?: 'workspace';
        $base = trim($base, '-') ?: 'workspace';

        $slug = $base;
        for ($i = 2; Workspace::where('slug', $slug)->whereKeyNot($workspace->id)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    private function assertOwns(Workspace $workspace, Owner $owner): void
    {
        // Callers already scope by owner; this is the service-level backstop.
        if ((int) $workspace->owner_id !== (int) $owner->id) {
            throw PublicationException::invalidTransition();
        }
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new PublicationException(__('app.publication.errors.reason_required'));
        }

        return $reason;
    }

    private function ownerActor(Owner $owner): array
    {
        return ['type' => 'owner', 'id' => $owner->id, 'name' => $owner->name];
    }

    private function adminActor(Admin $admin): array
    {
        return ['type' => 'admin', 'id' => $admin->id, 'name' => $admin->name];
    }
}
