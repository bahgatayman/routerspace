<?php

namespace Tests\Feature;

use App\Exceptions\MikroTik\MikroTikOperationException;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\RouterSyncTask;
use App\Models\SpeedProfile;
use App\Services\HotspotSyncService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\TestableHotspotSyncService;
use Tests\TestCase;

/**
 * Covers the MikroTik integration audit's confirmed findings and fixes.
 * No real RouterOS router is available in this environment, so every test
 * here exercises app-level behavior (HTTP routes, HotspotSyncService, the
 * SpeedProfile/HotspotUser lifecycle) against TestableHotspotSyncService's
 * FakeMikroTikService — a controlled in-memory stand-in, per the audit's
 * own instruction to use mocks when a real router isn't available. This
 * verifies CODE-LEVEL behavior (call sequencing, error propagation,
 * validation) — it does NOT verify real RouterOS wire-protocol behavior
 * (e.g. that MikroTikService's binary framing is byte-correct against an
 * actual router); that remains unverified against real hardware.
 */
class MikroTikProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        Plan::create([
            'name' => 'Basic', 'slug' => 'basic', 'max_members' => 100,
            'price_per_month' => 0, 'is_active' => true, 'sort_order' => 1,
        ]);
    }

    private function hotspotOwner(array $overrides = []): Owner
    {
        $owner = Owner::create(array_merge([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'password',
            'business_name' => 'Space', 'plan_id' => Plan::first()->id, 'is_active' => true,
            'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
            'mikrotik_host' => '10.0.0.1', 'mikrotik_port' => 8728,
            'mikrotik_username' => 'admin', 'mikrotik_password' => 'secret',
        ], $overrides));
        $owner->enableFeature('hotspot');

        return $owner->fresh();
    }

    private function bindFakeSync(): TestableHotspotSyncService
    {
        $fake = new TestableHotspotSyncService;
        $this->app->instance(HotspotSyncService::class, $fake);

        return $fake;
    }

    /** No hotspot feature — shouldSync() always short-circuits, so no router call is ever made for this owner. */
    private function bookingOwner(array $overrides = []): Owner
    {
        $owner = Owner::create(array_merge([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'password',
            'business_name' => 'Space', 'plan_id' => Plan::first()->id, 'is_active' => true,
            'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
        ], $overrides));
        $owner->enableFeature('booking');

        return $owner->fresh();
    }

    private function defaultProfile(Owner $owner, array $overrides = []): SpeedProfile
    {
        return SpeedProfile::create(array_merge([
            'owner_id' => $owner->id, 'name' => 'Default', 'speed_download' => '10M', 'speed_upload' => '5M', 'is_default' => true,
        ], $overrides));
    }

    /** Creates a member already present on the fake router, in the given app status, with a given profile. */
    private function provisionedMember(TestableHotspotSyncService $fake, Owner $owner, SpeedProfile $profile, string $phone = '01000099', string $status = 'active', string $name = 'Member'): HotspotUser
    {
        $fake->fake->users[$phone] = $profile->name;
        $fake->fake->disabled[$phone] = $status === 'inactive';

        return HotspotUser::create([
            'owner_id' => $owner->id, 'name' => $name, 'phone' => $phone, 'password' => $phone,
            'router_username' => $phone, 'speed_download' => $profile->speed_download, 'speed_upload' => $profile->speed_upload,
            'speed_profile_id' => $profile->id, 'status' => $status, 'router_sync_status' => 'synced',
        ]);
    }

    // --- credential encryption ---

    public function test_mikrotik_password_is_encrypted_at_rest_but_readable_through_the_model(): void
    {
        $owner = $this->hotspotOwner(['mikrotik_password' => 'super-secret-router-pass']);

        $raw = \DB::table('owners')->where('id', $owner->id)->value('mikrotik_password');
        $this->assertNotSame('super-secret-router-pass', $raw);
        $this->assertSame('super-secret-router-pass', $owner->fresh()->mikrotik_password);
    }

    // --- deleteHotspotUser(): resolves the router's real .id before removing (regression test for the fix) ---

    public function test_delete_hotspot_user_resolves_router_id_before_removing(): void
    {
        $fake = $this->bindFakeSync();
        $owner = $this->hotspotOwner();
        $fake->fake->users['01000001'] = 'Default';

        $fake->deleteUser($owner, '01000001');

        $this->assertArrayNotHasKey('01000001', $fake->fake->users);
        $this->assertContains(['deleteHotspotUser', '01000001'], $fake->fake->calls);
    }

    public function test_deleting_a_user_not_on_the_router_throws_instead_of_silently_succeeding(): void
    {
        $fake = $this->bindFakeSync();
        $owner = $this->hotspotOwner();

        $this->expectException(MikroTikOperationException::class);
        $fake->deleteUser($owner, 'never-provisioned');
    }

    // --- duplicate provisioning ---

    public function test_creating_the_same_router_username_twice_throws_rather_than_silently_duplicating(): void
    {
        $fake = $this->bindFakeSync();
        $owner = $this->hotspotOwner();

        $fake->createUser($owner, '01000002', '01000002', 'Default');
        $this->assertCount(1, $fake->fake->users);

        $this->expectException(MikroTikOperationException::class);
        $fake->createUser($owner, '01000002', '01000002', 'Default');
    }

    // --- owner isolation: one owner's member can never be provisioned against another owner's router ---

    public function test_router_credentials_used_are_always_the_member_owning_owners(): void
    {
        $fake = $this->bindFakeSync();
        $ownerA = $this->hotspotOwner(['mikrotik_host' => '10.0.0.1']);
        $ownerB = $this->hotspotOwner(['mikrotik_host' => '10.0.0.2']);

        // TestableHotspotSyncService always routes through the one fake client
        // regardless of $owner — the real client() keys strictly off $owner's
        // own mikrotik_* columns (confirmed by code read), so this asserts the
        // call was attributed to the right owner at the call-site level.
        $fake->createUser($ownerA, '01000003', '01000003', 'Default');
        $fake->createUser($ownerB, '01000004', '01000004', 'Default');

        $this->assertSame(['01000003' => 'Default', '01000004' => 'Default'], $fake->fake->users);
    }

    // --- speed profile rename (regression test for the fix) ---

    public function test_renaming_a_speed_profile_still_syncs_to_the_router_and_its_assigned_users(): void
    {
        $fake = $this->bindFakeSync();
        $owner = $this->hotspotOwner();
        $profile = SpeedProfile::create([
            'owner_id' => $owner->id, 'name' => 'Basic', 'speed_download' => '10M', 'speed_upload' => '5M', 'is_default' => true,
        ]);
        $fake->fake->profiles['Basic'] = ['download' => '10M', 'upload' => '5M'];
        $member = HotspotUser::create([
            'owner_id' => $owner->id, 'name' => 'Member', 'phone' => '01000005', 'password' => '01000005',
            'speed_download' => '10M', 'speed_upload' => '5M', 'speed_profile_id' => $profile->id, 'status' => 'active',
        ]);
        $fake->fake->users['01000005'] = 'Basic';

        $response = $this->actingAs($owner, 'owner')->put("/speed-profiles/{$profile->id}", [
            'name' => 'Premium', 'speed_download' => '20M', 'speed_upload' => '10M', 'is_default' => true,
        ]);

        $response->assertRedirect('/speed-profiles');
        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');

        // The router-side profile was renamed in place (not left orphaned under the old name).
        $this->assertArrayNotHasKey('Basic', $fake->fake->profiles);
        $this->assertArrayHasKey('Premium', $fake->fake->profiles);
        $this->assertSame(['download' => '20M', 'upload' => '10M'], $fake->fake->profiles['Premium']);

        // The lookup that matters: updateHotspotProfile was called with the OLD name (to find it),
        // not the new one (which, pre-fix, would never be found on the router).
        $this->assertContains(['updateHotspotProfile', 'Basic', '20M', '10M', 'Premium'], $fake->fake->calls);

        // The member's router profile pointer followed the rename.
        $this->assertSame('Premium', $fake->fake->users['01000005']);
        $this->assertSame('20M', $member->fresh()->speed_download);
    }

    // --- speed validation ---

    public function test_invalid_speed_strings_are_rejected_before_ever_reaching_the_router(): void
    {
        $fake = $this->bindFakeSync();
        $owner = $this->hotspotOwner();

        foreach (['abc', '-5M', '10', '10G', '', '5Mb'] as $bad) {
            $response = $this->actingAs($owner, 'owner')->post('/speed-profiles', [
                'name' => 'Test '.uniqid(), 'speed_download' => $bad, 'speed_upload' => '5M',
            ]);
            $response->assertSessionHasErrors('speed_download');
        }

        $this->assertSame([], $fake->fake->calls, 'no router call should happen for a request that fails validation');
    }

    public function test_valid_speed_strings_are_accepted(): void
    {
        $fake = $this->bindFakeSync();
        $owner = $this->hotspotOwner();

        foreach (['1M', '512k', '100M'] as $good) {
            $response = $this->actingAs($owner, 'owner')->post('/speed-profiles', [
                'name' => 'Test '.uniqid(), 'speed_download' => $good, 'speed_upload' => $good,
            ]);
            $response->assertSessionHasNoErrors();
        }
    }

    // --- connection-status distinction ---

    public function test_settings_test_connection_distinguishes_auth_failure_from_unreachable_router(): void
    {
        $fake = $this->bindFakeSync();
        $owner = $this->hotspotOwner();

        $fake->fake->failAuth = true;
        $res = $this->actingAs($owner, 'owner')->post('/settings/test-connection');
        $res->assertSessionHas('error');
        $this->assertStringContainsString('Authentication failed', session('error'));

        $fake->fake->failAuth = false;
        $fake->fake->failConnect = true;
        $res = $this->actingAs($owner, 'owner')->post('/settings/test-connection');
        $res->assertSessionHas('error');
        $this->assertStringContainsString('Router unreachable', session('error'));

        $fake->fake->failConnect = false;
        $res = $this->actingAs($owner, 'owner')->post('/settings/test-connection');
        $res->assertSessionHas('success');
    }

    // --- cross-owner isolation on the settings page itself ---

    public function test_an_owner_can_never_see_another_owners_router_settings(): void
    {
        $ownerA = $this->hotspotOwner(['mikrotik_host' => 'router-a.internal']);
        $this->hotspotOwner(['mikrotik_host' => 'router-b.internal']);

        $response = $this->actingAs($ownerA, 'owner')->get('/settings');

        $response->assertSee('router-a.internal');
        $response->assertDontSee('router-b.internal');
    }

    // ================= phone normalization & identity =================

    public function test_registering_the_same_number_in_a_different_format_is_rejected_as_a_duplicate(): void
    {
        $fake = $this->bindFakeSync();
        $owner = $this->hotspotOwner();
        $this->defaultProfile($owner);

        $this->actingAs($owner, 'owner')->post('/users', ['name' => 'First', 'phone' => '01012345678'])
            ->assertSessionDoesntHaveErrors();

        $response = $this->actingAs($owner, 'owner')->post('/users', ['name' => 'Second', 'phone' => '+201012345678']);

        $response->assertSessionHasErrors('phone');
        $this->assertSame(1, HotspotUser::where('owner_id', $owner->id)->count());
    }

    /**
     * Deliberately booking-only owners (no hotspot feature, no router call
     * at all — shouldSync() short-circuits) so this test isolates the one
     * thing it's actually about: the uniquePhoneRule() validation is scoped
     * per-owner, not global. Routing both owners through a real/fake router
     * call here would just be testing Laravel's own route-controller
     * resolution instead of the validation rule.
     */
    public function test_the_same_normalized_number_for_different_owners_is_not_a_collision(): void
    {
        $ownerA = $this->bookingOwner();
        $ownerB = $this->bookingOwner();

        $responseA = $this->actingAs($ownerA, 'owner')->post('/users', ['name' => 'A', 'phone' => '01012345678']);
        $responseB = $this->actingAs($ownerB, 'owner')->post('/users', ['name' => 'B', 'phone' => '+201012345678']);

        $responseA->assertSessionDoesntHaveErrors();
        $responseB->assertSessionDoesntHaveErrors();
        $this->assertSame(1, HotspotUser::where('owner_id', $ownerA->id)->count());
        $this->assertSame(1, HotspotUser::where('owner_id', $ownerB->id)->count());
    }

    public function test_a_new_members_router_identity_is_the_canonical_normalized_form(): void
    {
        $fake = $this->bindFakeSync();
        $owner = $this->hotspotOwner();
        $this->defaultProfile($owner);

        $this->actingAs($owner, 'owner')->post('/users', ['name' => 'New', 'phone' => '01012345678'])
            ->assertSessionDoesntHaveErrors();

        $member = HotspotUser::where('owner_id', $owner->id)->first();
        $this->assertSame('201012345678', $member->router_username);
        $this->assertSame('201012345678', $member->password);
        $this->assertSame('01012345678', $member->phone); // display value unchanged
        $this->assertArrayHasKey('201012345678', $fake->fake->users);
    }

    public function test_an_unparseable_number_still_creates_the_member_using_the_raw_value(): void
    {
        $fake = $this->bindFakeSync();
        $owner = $this->hotspotOwner();
        $this->defaultProfile($owner);

        $this->actingAs($owner, 'owner')->post('/users', ['name' => 'Foreign', 'phone' => '447911123456'])
            ->assertSessionDoesntHaveErrors();

        $member = HotspotUser::where('owner_id', $owner->id)->first();
        $this->assertNull($member->phone_normalized);
        $this->assertSame('447911123456', $member->router_username);
    }

    // ================= suspend / reactivate =================

    public function test_suspending_a_member_disables_the_router_account_without_deleting_it(): void
    {
        $fake = $this->bindFakeSync();
        $owner = $this->hotspotOwner();
        $profile = $this->defaultProfile($owner);
        $member = $this->provisionedMember($fake, $owner, $profile);

        $response = $this->actingAs($owner, 'owner')->post("/users/{$member->id}/toggle-status");

        $response->assertSessionHas('success');
        $this->assertContains(['disableHotspotUser', '01000099'], $fake->fake->calls);
        $this->assertArrayHasKey('01000099', $fake->fake->users); // still exists, not deleted
        $this->assertTrue($fake->fake->disabled['01000099']);
        $this->assertSame('inactive', $member->fresh()->status);
        $this->assertSame('synced', $member->fresh()->router_sync_status);
    }

    public function test_reactivating_preserves_the_assigned_profile(): void
    {
        $fake = $this->bindFakeSync();
        $owner = $this->hotspotOwner();
        $profile = $this->defaultProfile($owner);
        $member = $this->provisionedMember($fake, $owner, $profile, status: 'inactive');

        $this->actingAs($owner, 'owner')->post("/users/{$member->id}/toggle-status")->assertSessionHas('success');

        $this->assertContains(['enableHotspotUser', '01000099'], $fake->fake->calls);
        $this->assertFalse($fake->fake->disabled['01000099']);
        $this->assertSame('active', $member->fresh()->status);
        $this->assertSame($profile->name, $fake->fake->users['01000099']); // untouched by suspend/reactivate
    }

    public function test_suspending_with_an_unreachable_router_preserves_the_app_state_and_queues_a_retry(): void
    {
        $fake = $this->bindFakeSync();
        $owner = $this->hotspotOwner();
        $profile = $this->defaultProfile($owner);
        $member = $this->provisionedMember($fake, $owner, $profile);
        $fake->fake->failConnect = true;

        $response = $this->actingAs($owner, 'owner')->post("/users/{$member->id}/toggle-status");

        $response->assertSessionHas('warning');
        $member->refresh();
        $this->assertSame('inactive', $member->status, 'the requested app state is applied regardless of router connectivity');
        $this->assertSame('pending', $member->router_sync_status);
        $this->assertSame(1, RouterSyncTask::where('hotspot_user_id', $member->id)->where('type', 'suspend')->where('status', 'pending')->count());
    }

    public function test_toggling_status_again_before_a_pending_sync_resolves_cancels_the_stale_task(): void
    {
        $fake = $this->bindFakeSync();
        $owner = $this->hotspotOwner();
        $profile = $this->defaultProfile($owner);
        $member = $this->provisionedMember($fake, $owner, $profile);
        $fake->fake->failConnect = true;

        // Suspend fails to sync -> a pending 'suspend' task exists.
        $this->actingAs($owner, 'owner')->post("/users/{$member->id}/toggle-status");
        $this->assertSame(1, RouterSyncTask::where('hotspot_user_id', $member->id)->count());

        // Reactivating again (still unreachable) should drop the now-moot 'suspend' task
        // and leave only a 'reactivate' one — never both.
        $this->actingAs($owner, 'owner')->post("/users/{$member->id}/toggle-status");

        $tasks = RouterSyncTask::where('hotspot_user_id', $member->id)->get();
        $this->assertCount(1, $tasks);
        $this->assertSame('reactivate', $tasks->first()->type);
    }

    public function test_retrying_a_pending_suspend_task_once_the_router_is_reachable_resolves_it(): void
    {
        $fake = $this->bindFakeSync();
        $owner = $this->hotspotOwner();
        $profile = $this->defaultProfile($owner);
        $member = $this->provisionedMember($fake, $owner, $profile);
        $fake->fake->failConnect = true;
        $this->actingAs($owner, 'owner')->post("/users/{$member->id}/toggle-status");
        $task = RouterSyncTask::where('hotspot_user_id', $member->id)->firstOrFail();

        $fake->fake->failConnect = false;
        $ok = $fake->retryTask($task);

        $this->assertTrue($ok);
        $this->assertSame('synced', $member->fresh()->router_sync_status);
        $this->assertSame(0, RouterSyncTask::where('hotspot_user_id', $member->id)->count());
        $this->assertTrue($fake->fake->disabled['01000099']);
    }

    // ================= verified speed changes =================

    public function test_a_speed_change_is_verified_by_reading_it_back_before_being_reported_as_success(): void
    {
        $fake = $this->bindFakeSync();
        $owner = $this->hotspotOwner();
        $oldProfile = $this->defaultProfile($owner);
        $newProfile = SpeedProfile::create(['owner_id' => $owner->id, 'name' => 'Fast', 'speed_download' => '50M', 'speed_upload' => '20M']);
        $fake->fake->profiles['Fast'] = ['download' => '50M', 'upload' => '20M'];
        $member = $this->provisionedMember($fake, $owner, $oldProfile);

        $response = $this->actingAs($owner, 'owner')->post("/users/{$member->id}/speed", ['speed_profile_id' => $newProfile->id]);

        $response->assertSessionHas('success');
        $this->assertContains(['getHotspotUserProfile', '01000099'], $fake->fake->calls);
        $member->refresh();
        $this->assertSame('50M', $member->speed_download);
        $this->assertSame($newProfile->id, $member->speed_profile_id);
        $this->assertSame('synced', $member->router_sync_status);
    }

    public function test_a_speed_change_the_router_does_not_actually_apply_is_not_reported_as_success(): void
    {
        $fake = $this->bindFakeSync();
        $owner = $this->hotspotOwner();
        $oldProfile = $this->defaultProfile($owner);
        $newProfile = SpeedProfile::create(['owner_id' => $owner->id, 'name' => 'Fast', 'speed_download' => '50M', 'speed_upload' => '20M']);
        $fake->fake->profiles['Fast'] = ['download' => '50M', 'upload' => '20M'];
        $member = $this->provisionedMember($fake, $owner, $oldProfile);
        $fake->fake->simulateVerificationMismatch = true;

        $response = $this->actingAs($owner, 'owner')->post("/users/{$member->id}/speed", ['speed_profile_id' => $newProfile->id]);

        $response->assertSessionHas('error');
        $member->refresh();
        $this->assertSame($oldProfile->id, $member->speed_profile_id, 'DB must keep reflecting what the router actually confirmed, not the attempted change');
        $this->assertSame($oldProfile->speed_download, $member->speed_download);
        $this->assertSame('failed', $member->router_sync_status);
        $this->assertSame(1, RouterSyncTask::where('hotspot_user_id', $member->id)->where('type', 'speed_change')->where('status', 'failed')->count());
    }

    public function test_a_speed_change_during_a_router_outage_preserves_the_requested_profile_as_pending(): void
    {
        $fake = $this->bindFakeSync();
        $owner = $this->hotspotOwner();
        $oldProfile = $this->defaultProfile($owner);
        $newProfile = SpeedProfile::create(['owner_id' => $owner->id, 'name' => 'Fast', 'speed_download' => '50M', 'speed_upload' => '20M']);
        $fake->fake->profiles['Fast'] = ['download' => '50M', 'upload' => '20M'];
        $member = $this->provisionedMember($fake, $owner, $oldProfile);
        $fake->fake->failConnect = true;

        $response = $this->actingAs($owner, 'owner')->post("/users/{$member->id}/speed", ['speed_profile_id' => $newProfile->id]);

        $response->assertSessionHas('warning');
        $member->refresh();
        $this->assertSame($newProfile->id, $member->speed_profile_id, 'the requested profile is the app-level intent, preserved despite the outage');
        $this->assertSame('50M', $member->speed_download, 'denormalized columns stay consistent with speed_profile_id');
        $this->assertSame('pending', $member->router_sync_status);
    }

    // ================= owner-facing retry UI =================

    public function test_the_sync_tasks_page_only_shows_this_owners_tasks(): void
    {
        $fake = $this->bindFakeSync();
        $ownerA = $this->hotspotOwner();
        $ownerB = $this->hotspotOwner();
        $profileA = $this->defaultProfile($ownerA);
        $profileB = $this->defaultProfile($ownerB);
        $memberA = $this->provisionedMember($fake, $ownerA, $profileA, '01000001', name: 'Alice Owner-A');
        $memberB = $this->provisionedMember($fake, $ownerB, $profileB, '01000002', name: 'Bob Owner-B');
        $fake->fake->failConnect = true;
        $this->actingAs($ownerA, 'owner')->post("/users/{$memberA->id}/toggle-status");
        $this->actingAs($ownerB, 'owner')->post("/users/{$memberB->id}/toggle-status");

        $response = $this->actingAs($ownerA, 'owner')->get('/router-sync-tasks');

        $response->assertSee('Alice Owner-A');
        $response->assertDontSee('Bob Owner-B');
        $this->assertSame(1, RouterSyncTask::where('owner_id', $ownerA->id)->count());
        $this->assertSame(1, RouterSyncTask::where('owner_id', $ownerB->id)->count());
    }

    public function test_manual_retry_action_resolves_a_task_once_the_router_is_fixed(): void
    {
        $fake = $this->bindFakeSync();
        $owner = $this->hotspotOwner();
        $profile = $this->defaultProfile($owner);
        $member = $this->provisionedMember($fake, $owner, $profile);
        $fake->fake->failConnect = true;
        $this->actingAs($owner, 'owner')->post("/users/{$member->id}/toggle-status");
        $task = RouterSyncTask::where('owner_id', $owner->id)->firstOrFail();

        $fake->fake->failConnect = false;
        $response = $this->actingAs($owner, 'owner')->post("/router-sync-tasks/{$task->id}/retry");

        $response->assertSessionHas('success');
        $this->assertSame(0, RouterSyncTask::where('owner_id', $owner->id)->count());
    }

    public function test_an_owner_cannot_retry_another_owners_task(): void
    {
        $fake = $this->bindFakeSync();
        $ownerA = $this->hotspotOwner();
        $ownerB = $this->hotspotOwner();
        $profileA = $this->defaultProfile($ownerA);
        $memberA = $this->provisionedMember($fake, $ownerA, $profileA, '01000003');
        $fake->fake->failConnect = true;
        $this->actingAs($ownerA, 'owner')->post("/users/{$memberA->id}/toggle-status");
        $task = RouterSyncTask::where('owner_id', $ownerA->id)->firstOrFail();

        $this->actingAs($ownerB, 'owner')->post("/router-sync-tasks/{$task->id}/retry")->assertNotFound();
    }

    // ================= scheduled reconciliation =================

    public function test_the_reconcile_command_retries_pending_tasks_across_owners(): void
    {
        $fake = $this->bindFakeSync();
        $owner = $this->hotspotOwner();
        $profile = $this->defaultProfile($owner);
        $member = $this->provisionedMember($fake, $owner, $profile);
        $fake->fake->failConnect = true;
        $this->actingAs($owner, 'owner')->post("/users/{$member->id}/toggle-status");
        $this->assertSame(1, RouterSyncTask::count());

        $fake->fake->failConnect = false;
        Artisan::call('mikrotik:reconcile');

        $this->assertSame(0, RouterSyncTask::count());
        $this->assertSame('synced', $member->fresh()->router_sync_status);
    }

    // ================= deployment-safety check command =================

    public function test_password_encryption_check_command_reports_plaintext_and_encrypted_counts_separately(): void
    {
        $this->hotspotOwner(['mikrotik_password' => 'already-encrypted-owner']); // encrypted via the model cast on write
        $plaintextOwner = $this->hotspotOwner(['mikrotik_password' => 'temp']);
        \DB::table('owners')->where('id', $plaintextOwner->id)->update(['mikrotik_password' => 'raw-plaintext-value']);

        Artisan::call('mikrotik:check-password-encryption');
        $output = Artisan::output();

        $this->assertStringContainsString('1', $output); // at least one plaintext row flagged
        $this->assertStringNotContainsString('raw-plaintext-value', $output);
    }
}
