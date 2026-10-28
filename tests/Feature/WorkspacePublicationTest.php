<?php

namespace Tests\Feature;

use App\Exceptions\PublicationException;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Notification;
use App\Models\Owner;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Room;
use App\Models\Staff;
use App\Models\Workspace;
use App\Models\WorkspaceImage;
use App\Models\WorkspacePublicationReview;
use App\Services\PublicWorkspaceDirectory;
use App\Services\WorkspacePublicationService;
use App\Services\WorkspaceReadinessService;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Workspace publication: state machine, readiness rules (submit / approve /
 * public queries), slugs, review history, notifications + audit, owner-only
 * and admin-only actions, tenant isolation, and the owner/admin UI.
 */
class WorkspacePublicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    // ---------------------------------------------------------------- helpers

    private function owner(float $price = 0, string $name = 'Karim'): Owner
    {
        $plan = Plan::create([
            'name' => $price > 0 ? 'Growth' : 'Free', 'slug' => 'p-'.uniqid(), 'max_members' => 100, 'price_per_month' => $price,
            'is_active' => true, 'sort_order' => 1, 'features' => ['workspace'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ]);
        $owner = Owner::create([
            'name' => $name, 'email' => strtolower($name).uniqid().'@t.local', 'password' => 'secret123', 'business_name' => $name.' Biz',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
        ]);
        $owner->enableFeature('workspace');

        return $owner;
    }

    /** A workspace that passes every readiness check. */
    private function readyWorkspace(Owner $owner, string $name = 'Cairo Desk'): Workspace
    {
        $ws = Workspace::create([
            'owner_id' => $owner->id, 'name' => $name, 'address' => '10 Tahrir St', 'city' => 'Cairo',
            'description' => 'Quiet coworking with fast Wi-Fi.', 'phone' => '01012345678',
        ]);
        WorkspaceImage::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'path' => "workspace-images/{$ws->id}/a.jpg", 'is_primary' => true, 'sort_order' => 1]);
        Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Board Room', 'type' => 'meeting', 'capacity' => 6, 'price_per_hour' => 120]);

        return $ws->fresh();
    }

    private function admin(): Admin
    {
        return Admin::create(['name' => 'Reviewer', 'email' => 'a'.uniqid().'@t.local', 'password' => bcrypt('secret123')]);
    }

    private function service(): WorkspacePublicationService
    {
        return app(WorkspacePublicationService::class);
    }

    private function directory(): PublicWorkspaceDirectory
    {
        return app(PublicWorkspaceDirectory::class);
    }

    private function missing(Workspace $ws): array
    {
        return app(WorkspaceReadinessService::class)->check($ws->fresh())['missing'];
    }

    private function publish(Workspace $ws, ?Admin $admin = null): Workspace
    {
        $this->service()->submit($ws, $ws->owner);
        $this->actingAs($admin ?? $this->admin(), 'admin');
        $this->service()->approve($ws->fresh(), auth('admin')->user());

        return $ws->fresh();
    }

    // ---------------------------------------------------------------- defaults

    public function test_workspaces_default_to_draft_and_are_never_published_automatically(): void
    {
        $this->assertTrue(Schema::hasColumns('workspaces', ['slug', 'publication_status', 'published_at']));

        $ws = $this->readyWorkspace($this->owner());

        $this->assertSame(Workspace::DRAFT, $ws->publication_status);
        $this->assertNull($ws->slug);
        $this->assertNull($ws->published_at);
        $this->assertSame([], $this->missing($ws), 'Fully ready …');
        $this->assertCount(0, $this->directory()->all(), '… yet not public until submitted and approved.');
        $this->assertFalse($this->directory()->isVisible($ws));
    }

    public function test_publication_status_is_not_mass_assignable_and_independent_of_is_active(): void
    {
        $owner = $this->owner();
        $ws = $this->readyWorkspace($owner);

        $ws->update(['publication_status' => Workspace::PUBLISHED, 'slug' => 'hijack', 'published_at' => now()]);
        $this->assertSame(Workspace::DRAFT, $ws->fresh()->publication_status);
        $this->assertNull($ws->fresh()->slug);

        // The workspace update form can't smuggle it in either.
        $this->actingAs($owner, 'owner')->put(route('workspaces.update', $ws), [
            'name' => 'Cairo Desk', 'address' => '10 Tahrir St', 'city' => 'Cairo', 'phone' => '01012345678',
            'publication_status' => 'published', 'slug' => 'hijack',
        ])->assertRedirect();
        $this->assertSame(Workspace::DRAFT, $ws->fresh()->publication_status);

        // Operational toggle doesn't touch publication.
        $this->actingAs($owner, 'owner')->post(route('workspaces.toggle', $ws));
        $this->assertFalse($ws->fresh()->is_active);
        $this->assertSame(Workspace::DRAFT, $ws->fresh()->publication_status);
    }

    // ---------------------------------------------------------------- owner submit / withdraw

    public function test_owner_submits_a_ready_workspace_and_history_records_it(): void
    {
        $owner = $this->owner();
        $ws = $this->readyWorkspace($owner);

        $this->actingAs($owner, 'owner')->post(route('workspaces.publication.submit', $ws))
            ->assertRedirect(route('workspaces.edit', $ws).'#workspace-publication')
            ->assertSessionHas('success', __('app.publication.flash.submitted'));

        $ws->refresh();
        $this->assertSame(Workspace::PENDING_REVIEW, $ws->publication_status);
        $this->assertSame('cairo-desk', $ws->slug);

        $review = WorkspacePublicationReview::sole();
        $this->assertSame([WorkspacePublicationReview::SUBMITTED, Workspace::DRAFT, Workspace::PENDING_REVIEW, 'owner', $owner->id, 'Karim'],
            [$review->action, $review->from_status, $review->to_status, $review->actor_type, $review->actor_id, $review->actor_name]);
        $this->assertSame([], $review->readiness);
        $this->assertSame($owner->id, $review->owner_id);
    }

    public function test_submission_is_blocked_with_actionable_missing_items(): void
    {
        $owner = $this->owner();
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Bare Space', 'address' => '1 St', 'city' => 'Giza', 'phone' => '01012345678']);

        $this->actingAs($owner, 'owner')->post(route('workspaces.publication.submit', $ws))
            ->assertSessionHas('error', __('app.publication.errors.not_ready'))
            ->assertSessionHas('publication_missing', ['description', 'primary_image', 'priced_room']);

        $this->assertSame(Workspace::DRAFT, $ws->fresh()->publication_status);
        $this->assertSame(0, WorkspacePublicationReview::count());

        $this->actingAs($owner, 'owner')->get(route('workspaces.edit', $ws))->assertOk()
            ->assertSee(__('app.publication.checks.description.fix'))
            ->assertSee(__('app.publication.checks.primary_image.fix'))
            ->assertSee(__('app.publication.checks.priced_room.fix'))
            ->assertSee(__('app.publication.not_ready_hint'))
            ->assertDontSee(__('app.publication.checks.name.fix'));
    }

    public function test_owner_can_withdraw_only_while_pending(): void
    {
        $owner = $this->owner();
        $ws = $this->readyWorkspace($owner);

        $this->actingAs($owner, 'owner')->post(route('workspaces.publication.withdraw', $ws))
            ->assertSessionHas('error', __('app.publication.errors.invalid_transition'));

        $this->actingAs($owner, 'owner')->post(route('workspaces.publication.submit', $ws));
        $this->actingAs($owner, 'owner')->post(route('workspaces.publication.withdraw', $ws))->assertSessionHas('success');

        $this->assertSame(Workspace::DRAFT, $ws->fresh()->publication_status);
        $this->assertSame(['submitted', 'withdrawn'], WorkspacePublicationReview::orderBy('id')->pluck('action')->all());
        $this->assertSame('cairo-desk', $ws->fresh()->slug, 'Slug is kept for the next submission.');
    }

    // ---------------------------------------------------------------- readiness rules

    public function test_every_readiness_rule_is_enforced(): void
    {
        $owner = $this->owner();

        $cases = [
            'name' => fn (Workspace $ws) => $ws->forceFill(['name' => '   '])->save(),
            'location' => fn (Workspace $ws) => $ws->forceFill(['city' => null])->save(),
            'description' => fn (Workspace $ws) => $ws->forceFill(['description' => " \n "])->save(),
            'primary_image' => fn (Workspace $ws) => $ws->images()->update(['is_primary' => false]),
            'priced_room' => fn (Workspace $ws) => $ws->rooms()->update(['price_per_hour' => 0]),
        ];
        foreach ($cases as $key => $break) {
            $ws = $this->readyWorkspace($owner, 'Space '.$key);
            $break($ws);
            $this->assertSame([$key], $this->missing($ws), "Breaking {$key} must be reported as {$key} only.");
        }

        $ws = $this->readyWorkspace($owner, 'No photos');
        $ws->images()->delete();
        $this->assertSame(['primary_image'], $this->missing($ws));

        $ws = $this->readyWorkspace($owner, 'Unavailable room');
        $ws->rooms()->update(['is_available' => false]);
        $this->assertSame(['priced_room'], $this->missing($ws));

        $ws = $this->readyWorkspace($owner, 'No rooms');
        $ws->rooms()->delete();
        $this->assertSame(['priced_room'], $this->missing($ws));

        // Owner account + subscription.
        $ws = $this->readyWorkspace($owner, 'Owner checks');
        $owner->forceFill(['is_active' => false])->save();
        $this->assertSame(['owner_active', 'subscription'], $this->missing($ws));
        $owner->forceFill(['is_active' => true, 'subscription_expires_at' => now()->subDay()])->save();
        $this->assertSame(['subscription'], $this->missing($ws));
    }

    public function test_rule_based_room_prices_use_the_displayed_price(): void
    {
        $owner = $this->owner();
        $ws = $this->readyWorkspace($owner);
        $room = $ws->rooms()->first();

        // Duration model: the displayed "From" price is the lowest cell.
        $room->forceFill(['price_per_hour' => 0, 'pricing_model' => 'duration', 'pricing_rules' => ['durations' => [60, 120], 'prices' => [[80, 150]]]])->save();
        $this->assertSame([], $this->missing($ws), 'From EGP 80 → usable price.');

        $room->forceFill(['pricing_rules' => ['durations' => [60, 120], 'prices' => [[0, 150]]]])->save();
        $this->assertSame(['priced_room'], $this->missing($ws), '"From EGP 0" is not a usable display price.');
        $this->assertFalse($room->fresh()->hasDisplayablePrice());

        $room->forceFill(['pricing_model' => 'hourly', 'price_per_hour' => 0.5])->save();
        $this->assertTrue($room->fresh()->hasDisplayablePrice());
    }

    public function test_free_and_trial_plan_owners_are_eligible(): void
    {
        $free = $this->owner(0, 'Free');                       // Free plan, trial month active
        $paid = $this->owner(299, 'Paid');

        foreach ([$free, $paid] as $owner) {
            $ws = $this->publish($this->readyWorkspace($owner, $owner->name.' Space'));
            $this->assertSame(Workspace::PUBLISHED, $ws->publication_status);
            $this->assertTrue($this->directory()->isVisible($ws));
        }
        $this->assertCount(2, $this->directory()->all());
    }

    // ---------------------------------------------------------------- admin decisions

    public function test_admin_approval_rechecks_readiness_then_publishes_notifies_and_audits(): void
    {
        $owner = $this->owner();
        $ws = $this->readyWorkspace($owner);
        $admin = $this->admin();
        $this->actingAs($owner, 'owner')->post(route('workspaces.publication.submit', $ws));

        // The owner removed the primary photo after submitting.
        $ws->images()->delete();
        $this->actingAs($admin, 'admin')->post(route('admin.publications.approve', $ws))
            ->assertRedirect(route('admin.publications.show', $ws))
            ->assertSessionHas('error');
        $this->assertSame(Workspace::PENDING_REVIEW, $ws->fresh()->publication_status);
        $this->assertSame(1, WorkspacePublicationReview::count());

        WorkspaceImage::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'path' => 'workspace-images/x.jpg', 'is_primary' => true, 'sort_order' => 1]);
        $this->actingAs($admin, 'admin')->post(route('admin.publications.approve', $ws))
            ->assertSessionHas('success', __('app.publication.flash.approved'));

        $ws->refresh();
        $this->assertSame(Workspace::PUBLISHED, $ws->publication_status);
        $this->assertNotNull($ws->published_at);

        $review = WorkspacePublicationReview::latest('id')->first();
        $this->assertSame(['approved', 'admin', $admin->id, 'Reviewer'], [$review->action, $review->actor_type, $review->actor_id, $review->actor_name]);

        $note = Notification::where('owner_id', $owner->id)->sole();
        $this->assertSame('publication_approved', $note->type);
        $this->assertStringContainsString('Cairo Desk', $note->title);
        $this->assertSame(route('workspaces.edit', $ws).'#workspace-publication', $note->action_url);

        $audit = AdminAuditLog::where('action', 'publication.approved')->sole();
        $this->assertSame($owner->id, $audit->owner_id);
        $this->assertSame($admin->id, $audit->admin_id);
    }

    public function test_rejection_requires_a_reason_which_the_owner_sees(): void
    {
        $owner = $this->owner();
        $ws = $this->readyWorkspace($owner);
        $admin = $this->admin();
        $this->actingAs($owner, 'owner')->post(route('workspaces.publication.submit', $ws));

        $this->actingAs($admin, 'admin')->post(route('admin.publications.reject', $ws), ['reason' => ''])
            ->assertSessionHasErrors(['reason' => __('app.publication.errors.reason_required')]);
        $this->actingAs($admin, 'admin')->post(route('admin.publications.reject', $ws), ['reason' => 'bad'])
            ->assertSessionHasErrors('reason');
        $this->assertSame(Workspace::PENDING_REVIEW, $ws->fresh()->publication_status);

        $this->actingAs($admin, 'admin')->post(route('admin.publications.reject', $ws), ['reason' => 'Please add photos of the meeting room.'])
            ->assertSessionHas('success');

        $this->assertSame(Workspace::REJECTED, $ws->fresh()->publication_status);
        $review = WorkspacePublicationReview::latest('id')->first();
        $this->assertSame(['rejected', 'Please add photos of the meeting room.'], [$review->action, $review->reason]);
        $this->assertSame('publication_rejected', Notification::where('owner_id', $owner->id)->sole()->type);
        $this->assertSame('Please add photos of the meeting room.', AdminAuditLog::where('action', 'publication.rejected')->sole()->reason);

        // The service refuses a blank reason even if called directly.
        $this->expectException(PublicationException::class);
        $this->service()->unpublish($ws->fresh(), $admin, '   ');
    }

    public function test_rejected_workspace_can_only_be_resubmitted_once_ready_and_history_is_kept(): void
    {
        $owner = $this->owner();
        $ws = $this->readyWorkspace($owner);
        $admin = $this->admin();
        $this->service()->submit($ws, $owner);
        $this->service()->reject($ws->fresh(), $admin, 'Description is too short.');

        $ws->forceFill(['description' => ''])->save();
        $this->actingAs($owner, 'owner')->post(route('workspaces.publication.submit', $ws))->assertSessionHas('error');
        $this->assertSame(Workspace::REJECTED, $ws->fresh()->publication_status);

        $this->actingAs($owner, 'owner')->get(route('workspaces.edit', $ws))->assertOk()
            ->assertSee('Description is too short.')
            ->assertSee(__('app.publication.resubmit'));

        $ws->forceFill(['description' => 'A long, helpful description.'])->save();
        $this->actingAs($owner, 'owner')->post(route('workspaces.publication.submit', $ws))->assertSessionHas('success');

        $this->assertSame(Workspace::PENDING_REVIEW, $ws->fresh()->publication_status);
        $this->assertSame(['submitted', 'rejected', 'submitted'], WorkspacePublicationReview::orderBy('id')->pluck('action')->all());
    }

    public function test_unpublish_requires_a_reason_hides_the_workspace_and_needs_a_new_approval(): void
    {
        $owner = $this->owner();
        $admin = $this->admin();
        $ws = $this->publish($this->readyWorkspace($owner), $admin);
        $this->assertTrue($this->directory()->isVisible($ws));

        $this->actingAs($admin, 'admin')->post(route('admin.publications.unpublish', $ws), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->assertSame(Workspace::PUBLISHED, $ws->fresh()->publication_status);

        $this->actingAs($admin, 'admin')->post(route('admin.publications.unpublish', $ws), ['reason' => 'Photos show a different venue.'])
            ->assertSessionHas('success');
        $ws->refresh();
        $this->assertSame(Workspace::UNPUBLISHED, $ws->publication_status);
        $this->assertFalse($this->directory()->isVisible($ws));
        $this->assertNull($this->directory()->findBySlug('cairo-desk'));
        $this->assertNotNull(Room::where('workspace_id', $ws->id)->first(), 'Operational data is untouched.');

        // An admin can't simply re-approve: the owner must resubmit.
        $this->actingAs($admin, 'admin')->post(route('admin.publications.approve', $ws))->assertSessionHas('error');
        $this->assertSame(Workspace::UNPUBLISHED, $ws->fresh()->publication_status);

        $this->actingAs($owner, 'owner')->get(route('workspaces.edit', $ws))->assertSee('Photos show a different venue.');
        $this->actingAs($owner, 'owner')->post(route('workspaces.publication.submit', $ws))->assertSessionHas('success');
        $this->assertSame(Workspace::PENDING_REVIEW, $ws->fresh()->publication_status);
    }

    public function test_every_invalid_transition_is_refused_server_side(): void
    {
        $owner = $this->owner();
        $admin = $this->admin();
        $ws = $this->readyWorkspace($owner);
        $s = $this->service();

        $attempts = [
            Workspace::DRAFT => ['withdraw', 'approve', 'reject', 'unpublish'],
            Workspace::PENDING_REVIEW => ['submit', 'unpublish'],
            Workspace::REJECTED => ['withdraw', 'approve', 'reject', 'unpublish'],
            Workspace::PUBLISHED => ['submit', 'withdraw', 'approve', 'reject'],
            Workspace::UNPUBLISHED => ['withdraw', 'approve', 'reject', 'unpublish'],
        ];
        $run = fn (string $action, Workspace $w) => match ($action) {
            'submit' => $s->submit($w, $owner),
            'withdraw' => $s->withdraw($w, $owner),
            'approve' => $s->approve($w, $admin),
            'reject' => $s->reject($w, $admin, 'Some reason here'),
            'unpublish' => $s->unpublish($w, $admin, 'Some reason here'),
        };

        foreach ($attempts as $status => $actions) {
            foreach ($actions as $action) {
                $ws->forceFill(['publication_status' => $status])->save();
                $before = WorkspacePublicationReview::count();
                try {
                    $run($action, $ws->fresh());
                    $this->fail("{$action} from {$status} should be refused.");
                } catch (PublicationException $e) {
                    $this->assertSame(__('app.publication.errors.invalid_transition'), $e->getMessage());
                }
                $this->assertSame($status, $ws->fresh()->publication_status, "{$action} from {$status} changed the status.");
                $this->assertSame($before, WorkspacePublicationReview::count());
            }
        }
    }

    public function test_every_valid_transition_succeeds(): void
    {
        $owner = $this->owner();
        $admin = $this->admin();
        $ws = $this->readyWorkspace($owner);
        $s = $this->service();
        $this->actingAs($admin, 'admin');

        $s->submit($ws, $owner);                                    // draft → pending
        $s->withdraw($ws->fresh(), $owner);                         // pending → draft
        $s->submit($ws->fresh(), $owner);                           // draft → pending
        $s->reject($ws->fresh(), $admin, 'Needs more photos');      // pending → rejected
        $s->submit($ws->fresh(), $owner);                           // rejected → pending
        $s->approve($ws->fresh(), $admin);                          // pending → published
        $s->unpublish($ws->fresh(), $admin, 'Temporarily closed');  // published → unpublished
        $s->submit($ws->fresh(), $owner);                           // unpublished → pending

        $this->assertSame(Workspace::PENDING_REVIEW, $ws->fresh()->publication_status);
        $this->assertSame(
            ['draft>pending_review', 'pending_review>draft', 'draft>pending_review', 'pending_review>rejected', 'rejected>pending_review',
                'pending_review>published', 'published>unpublished', 'unpublished>pending_review'],
            WorkspacePublicationReview::orderBy('id')->get()->map(fn ($r) => $r->from_status.'>'.$r->to_status)->all()
        );
    }

    // ---------------------------------------------------------------- permissions & tenancy

    public function test_staff_cannot_submit_or_withdraw_even_with_workspaces_manage(): void
    {
        $owner = $this->owner();
        $ws = $this->readyWorkspace($owner);
        $role = Role::whereNull('owner_id')->where('key', 'manager')->firstOrFail();
        $staff = Staff::create(['owner_id' => $owner->id, 'role_id' => $role->id, 'name' => 'Mgr', 'email' => 'm'.uniqid().'@t.local', 'password' => 'secret123', 'is_active' => true]);
        $staff->syncPermissionsFromRole();
        $staff->permissions()->attach(Permission::where('key', 'workspaces.manage')->value('id'), ['granted_at' => now(), 'granted_by_owner_id' => $owner->id]);

        $this->actingAs($staff, 'staff')->post(route('workspaces.publication.submit', $ws))->assertRedirect(route('login'));
        $this->assertSame(Workspace::DRAFT, $ws->fresh()->publication_status);

        // Staff with workspaces.manage can see the status on the edit page, but no submit button.
        $this->actingAs($staff, 'staff')->get(route('workspaces.edit', $ws))->assertOk()
            ->assertSee(__('app.publication.owner_only'))
            ->assertDontSee(route('workspaces.publication.submit', $ws), false);

        $this->service()->submit($ws, $owner);
        $this->actingAs($staff, 'staff')->post(route('workspaces.publication.withdraw', $ws))->assertRedirect(route('login'));
        $this->assertSame(Workspace::PENDING_REVIEW, $ws->fresh()->publication_status);
    }

    public function test_owners_cannot_touch_another_owners_workspace(): void
    {
        $mine = $this->owner(0, 'Mine');
        $theirs = $this->readyWorkspace($this->owner(0, 'Theirs'));

        $this->actingAs($mine, 'owner')->post(route('workspaces.publication.submit', $theirs))->assertNotFound();
        $this->service()->submit($theirs, $theirs->owner);
        $this->actingAs($mine, 'owner')->post(route('workspaces.publication.withdraw', $theirs))->assertNotFound();
        $this->assertSame(Workspace::PENDING_REVIEW, $theirs->fresh()->publication_status);

        // Service backstop: a mismatched owner is refused even if a caller forgets to scope.
        $this->expectException(PublicationException::class);
        $this->service()->withdraw($theirs->fresh(), $mine);
    }

    public function test_only_admins_reach_moderation(): void
    {
        $owner = $this->owner();
        $ws = $this->readyWorkspace($owner);
        $this->service()->submit($ws, $owner);

        foreach (['get' => route('admin.publications.index'), 'get2' => route('admin.publications.show', $ws)] as $url) {
            $this->get($url)->assertRedirect('/login');
            $this->actingAs($owner, 'owner')->get($url)->assertRedirect('/login');
        }
        $this->actingAs($owner, 'owner')->post(route('admin.publications.approve', $ws))->assertRedirect('/login');
        $this->actingAs($owner, 'owner')->post(route('admin.publications.reject', $ws), ['reason' => 'self-reject attempt'])->assertRedirect('/login');
        $this->assertSame(Workspace::PENDING_REVIEW, $ws->fresh()->publication_status);
    }

    // ---------------------------------------------------------------- slugs

    public function test_slugs_are_unique_url_safe_and_stable(): void
    {
        $a = $this->readyWorkspace($this->owner(0, 'A'), 'Cairo Desk');
        $b = $this->readyWorkspace($this->owner(0, 'B'), 'Cairo  Desk!');
        $c = $this->readyWorkspace($this->owner(0, 'C'), 'كايرو ديسك');
        $d = $this->readyWorkspace($this->owner(0, 'D'), '###');

        foreach ([$a, $b, $c, $d] as $ws) {
            $this->service()->submit($ws, $ws->owner);
        }

        $this->assertSame('cairo-desk', $a->fresh()->slug);
        $this->assertSame('cairo-desk-2', $b->fresh()->slug);
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $c->fresh()->slug);
        $this->assertSame('workspace', $d->fresh()->slug);

        // Renaming a published workspace keeps its public address.
        $published = $this->publish($this->readyWorkspace($this->owner(0, 'E'), 'Nile Hub'));
        $this->assertSame('nile-hub', $published->slug);
        $this->actingAs($published->owner, 'owner')->put(route('workspaces.update', $published), [
            'name' => 'Nile Hub Downtown', 'address' => '10 Tahrir St', 'city' => 'Cairo', 'phone' => '01012345678', 'description' => 'Renamed.',
        ])->assertRedirect();
        $this->assertSame('nile-hub', $published->fresh()->slug);
        $this->assertSame('Nile Hub Downtown', $this->directory()->findBySlug('nile-hub')->name);
    }

    // ---------------------------------------------------------------- public visibility

    public function test_only_published_and_currently_ready_workspaces_are_public(): void
    {
        $owner = $this->owner();
        $admin = $this->admin();

        $draft = $this->readyWorkspace($owner, 'Draft One');
        $pending = $this->readyWorkspace($owner, 'Pending One');
        $this->service()->submit($pending, $owner);
        $rejected = $this->readyWorkspace($owner, 'Rejected One');
        $this->service()->submit($rejected, $owner);
        $this->actingAs($admin, 'admin');
        $this->service()->reject($rejected->fresh(), $admin, 'Not this time');
        $unpublished = $this->publish($this->readyWorkspace($owner, 'Unpublished One'), $admin);
        $this->service()->unpublish($unpublished, $admin, 'Closed for now');
        $live = $this->publish($this->readyWorkspace($owner, 'Live One'), $admin);

        $this->assertSame(['Live One'], $this->directory()->all()->pluck('name')->all());
        foreach ([$draft, $pending, $rejected, $unpublished] as $ws) {
            $this->assertFalse($this->directory()->isVisible($ws->fresh()));
            $this->assertNull($this->directory()->findBySlug((string) $ws->fresh()->slug));
        }
        $this->assertSame($live->id, $this->directory()->findBySlug('live-one')->id);
    }

    public function test_a_published_workspace_drops_out_when_it_stops_being_ready(): void
    {
        $owner = $this->owner();
        $ws = $this->publish($this->readyWorkspace($owner));
        $room = $ws->rooms()->first();

        $breakAndRestore = [
            [fn () => $room->forceFill(['price_per_hour' => 0])->save(), fn () => $room->forceFill(['price_per_hour' => 120])->save()],
            [fn () => $ws->images()->update(['is_primary' => false]), fn () => $ws->images()->update(['is_primary' => true])],
            [fn () => $ws->forceFill(['description' => ''])->save(), fn () => $ws->forceFill(['description' => 'Back again'])->save()],
            [fn () => $owner->forceFill(['subscription_expires_at' => now()->subDay()])->save(), fn () => $owner->forceFill(['subscription_expires_at' => now()->addMonth()])->save()],
            [fn () => $owner->forceFill(['is_active' => false])->save(), fn () => $owner->forceFill(['is_active' => true])->save()],
        ];

        foreach ($breakAndRestore as $i => [$break, $restore]) {
            $break();
            $this->assertCount(0, $this->directory()->all(), "Case {$i}: must disappear.");
            $this->assertNull($this->directory()->findBySlug('cairo-desk'));
            $this->assertSame(Workspace::PUBLISHED, $ws->fresh()->publication_status, 'Status is unchanged — visibility is computed.');
            $restore();
            $this->assertCount(1, $this->directory()->all(), "Case {$i}: must come back once fixed.");
        }
    }

    public function test_owner_edits_to_a_published_workspace_go_live_without_review(): void
    {
        $owner = $this->owner();
        $ws = $this->publish($this->readyWorkspace($owner));

        $this->actingAs($owner, 'owner')->put(route('workspaces.update', $ws), [
            'name' => 'Cairo Desk', 'address' => '10 Tahrir St', 'city' => 'Cairo', 'phone' => '01012345678', 'description' => 'Now with a rooftop.',
        ])->assertRedirect();

        $this->assertSame(Workspace::PUBLISHED, $ws->fresh()->publication_status);
        $this->assertSame('Now with a rooftop.', $this->directory()->findBySlug('cairo-desk')->description);
        $this->assertSame(2, WorkspacePublicationReview::count(), 'No new review entry for ordinary edits.');
    }

    // ---------------------------------------------------------------- UI

    public function test_owner_edit_page_shows_each_status_in_english_and_arabic(): void
    {
        $owner = $this->owner();
        $ws = $this->readyWorkspace($owner);

        foreach (Workspace::PUBLICATION_STATUSES as $status) {
            $ws->forceFill(['publication_status' => $status, 'slug' => 'cairo-desk'])->save();
            $this->actingAs($owner, 'owner')->get(route('workspaces.edit', $ws))->assertOk()
                ->assertSee('data-publication-status="'.$status.'"', false)
                ->assertSee(__('app.publication.status.'.$status))
                ->assertSee(__('app.publication.status_text.'.$status));
        }

        app()->setLocale('ar');
        $ws->forceFill(['publication_status' => Workspace::DRAFT])->save();
        $this->actingAs($owner, 'owner')->withSession(['locale' => 'ar'])->get(route('workspaces.edit', $ws))->assertOk()
            ->assertSee('الظهور على الموقع')
            ->assertSee('لم يُرسل')
            ->assertSee('إرسال للمراجعة');
        app()->setLocale('en');
    }

    public function test_admin_queue_detail_and_nav_badge(): void
    {
        $owner = $this->owner();
        $admin = $this->admin();
        $pending = $this->readyWorkspace($owner, 'Pending Hub');
        $this->service()->submit($pending, $owner);
        $notReady = $this->readyWorkspace($owner, 'Broken Hub');
        $this->service()->submit($notReady, $owner);
        $notReady->forceFill(['description' => ''])->save();
        $draft = $this->readyWorkspace($owner, 'Draft Hub');

        $this->actingAs($admin, 'admin')->get(route('admin.publications.index'))->assertOk()
            ->assertSee('Pending Hub')->assertSee('Broken Hub')->assertDontSee('Draft Hub')
            ->assertSee('Karim Biz')
            ->assertSee(__('app.publication.admin.ready'))
            ->assertSee(trans_choice('app.publication.admin.missing', 1, ['count' => 1]))
            ->assertSee(__('app.publication.admin.nav'))
            ->assertSee('EGP 120.00', false);

        $this->actingAs($admin, 'admin')->get(route('admin.publications.show', $pending))->assertOk()
            ->assertSee(route('admin.publications.approve', $pending), false)
            ->assertSee(route('admin.publications.reject', $pending), false)
            ->assertDontSee(route('admin.publications.unpublish', $pending), false)
            ->assertSee(__('app.publication.actions.submitted'))
            ->assertSee('Quiet coworking with fast Wi-Fi.');

        $this->actingAs($admin, 'admin')->get(route('admin.publications.index', ['status' => 'published']))->assertOk()
            ->assertDontSee('Pending Hub');
    }
}
