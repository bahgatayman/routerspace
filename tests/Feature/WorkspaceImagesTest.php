<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomImage;
use App\Models\Staff;
use App\Models\Workspace;
use App\Models\WorkspaceImage;
use App\Services\ImageUploadService;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Workspace photo gallery: upload/validation, primary-image invariant,
 * reorder/delete, file cleanup (incl. failed uploads and workspace deletion),
 * serialization (no storage paths), authorization and tenant isolation.
 */
class WorkspaceImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        Storage::fake('public');
    }

    private function owner(): Owner
    {
        $plan = Plan::create([
            'name' => 'Test', 'slug' => 'test-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0,
            'is_active' => true, 'sort_order' => 1, 'features' => ['workspace'],
            'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ]);
        $owner = Owner::create([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Space',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
        ]);
        $owner->enableFeature('workspace');

        return $owner;
    }

    private function workspace(Owner $owner, string $name = 'Main'): Workspace
    {
        return Workspace::create(['owner_id' => $owner->id, 'name' => $name.' '.uniqid(), 'address' => '1 St', 'city' => 'Cairo']);
    }

    /**
     * A stored image row with real (fake-disk) files. Uses the legacy layout
     * (workspace-images/{workspace_id}/...) that images uploaded before the
     * id-free layout keep, so the tests below also cover those rows.
     */
    private function image(Workspace $ws, int $sort, bool $primary = false): WorkspaceImage
    {
        $path = "workspace-images/{$ws->id}/img{$sort}-".uniqid().'.jpg';
        $thumb = preg_replace('/\.jpg$/', '-thumb.webp', $path);
        Storage::disk('public')->put($path, 'img');
        Storage::disk('public')->put($thumb, 'thumb');

        return WorkspaceImage::create(['owner_id' => $ws->owner_id, 'workspace_id' => $ws->id, 'path' => $path,
            'thumbnail_path' => $thumb, 'is_primary' => $primary, 'sort_order' => $sort]);
    }

    private function staff(Owner $owner, string $roleKey): Staff
    {
        $role = Role::whereNull('owner_id')->where('key', $roleKey)->firstOrFail();
        $staff = Staff::create(['owner_id' => $owner->id, 'role_id' => $role->id, 'name' => 'S', 'email' => 's'.uniqid().'@t.local', 'password' => 'secret123', 'is_active' => true]);
        $staff->syncPermissionsFromRole();

        return $staff;
    }

    // ---------------------------------------------------------------- upload

    public function test_single_upload_stores_the_file_and_a_thumbnail_and_becomes_primary(): void
    {
        $owner = $this->owner();
        $ws = $this->workspace($owner);

        $this->actingAs($owner, 'owner')->post(route('workspaces.images.store', $ws), [
            'images' => [UploadedFile::fake()->image('lobby.jpg', 1600, 900)],
        ])->assertRedirect()->assertSessionHas('success')->assertSessionMissing('error');

        $image = $ws->images()->sole();
        $this->assertTrue($image->is_primary);
        $this->assertSame(1, $image->sort_order);
        $this->assertSame($owner->id, $image->owner_id);
        // No internal id in the stored path (it becomes a public URL): workspace-images/{random 40}.ext.
        $this->assertMatchesRegularExpression('#^workspace-images/[A-Za-z0-9]{40}\.jpe?g$#', $image->path);
        $this->assertMatchesRegularExpression('#^workspace-images/[A-Za-z0-9]{40}-thumb\.webp$#', $image->thumbnail_path);
        $this->assertStringNotContainsString('lobby', $image->path, 'Client file names are never used.');
        $this->assertStringEndsWith('-thumb.webp', $image->thumbnail_path);
        Storage::disk('public')->assertExists([$image->path, $image->thumbnail_path]);
    }

    public function test_multiple_upload_keeps_order_and_only_the_first_is_primary(): void
    {
        $owner = $this->owner();
        $ws = $this->workspace($owner);

        $this->actingAs($owner, 'owner')->post(route('workspaces.images.store', $ws), [
            'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png'), UploadedFile::fake()->image('c.webp')],
        ])->assertSessionHas('success');

        $images = $ws->images()->get();
        $this->assertCount(3, $images);
        $this->assertSame([1, 2, 3], $images->pluck('sort_order')->all());
        $this->assertSame([true, false, false], $images->pluck('is_primary')->all());
    }

    public function test_uploading_into_a_gallery_that_has_a_primary_keeps_that_primary(): void
    {
        $owner = $this->owner();
        $ws = $this->workspace($owner);
        $existing = $this->image($ws, 1, true);

        $this->actingAs($owner, 'owner')->post(route('workspaces.images.store', $ws), [
            'images' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertSessionHas('success');

        $this->assertSame(1, $ws->images()->where('is_primary', true)->count());
        $this->assertTrue($existing->fresh()->is_primary);
        $this->assertSame(2, WorkspaceImage::where('workspace_id', $ws->id)->latest('id')->first()->sort_order);
    }

    public function test_unsupported_files_are_rejected_by_content_not_just_name(): void
    {
        $owner = $this->owner();
        $ws = $this->workspace($owner);

        $this->actingAs($owner, 'owner')->post(route('workspaces.images.store', $ws), [
            'images' => [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
        ])->assertSessionHasErrors('images.0');

        // A text file renamed to .jpg is still not an image. A real temp file is used (not a
        // testing fake, whose reported MIME type comes from the name) so content sniffing runs.
        $tmp = tempnam(sys_get_temp_dir(), 'ls-test');
        file_put_contents($tmp, '<?php echo "definitely not a jpeg";');
        $this->actingAs($owner, 'owner')->post(route('workspaces.images.store', $ws), [
            'images' => [new UploadedFile($tmp, 'fake.jpg', 'image/jpeg', null, true)],
        ])->assertSessionHasErrors('images.0');
        @unlink($tmp);

        // Even a testing fake that *claims* image/jpeg is rejected when the bytes aren't an image.
        $this->actingAs($owner, 'owner')->post(route('workspaces.images.store', $ws), [
            'images' => [UploadedFile::fake()->createWithContent('fake.jpg', 'definitely not a jpeg')],
        ])->assertSessionHasErrors('images.0');

        // GIF is a real image but not an allowed format.
        $this->actingAs($owner, 'owner')->post(route('workspaces.images.store', $ws), [
            'images' => [UploadedFile::fake()->image('anim.gif')],
        ])->assertSessionHasErrors('images.0');

        $this->assertSame(0, $ws->images()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_oversized_images_are_rejected(): void
    {
        $owner = $this->owner();
        $ws = $this->workspace($owner);

        $this->actingAs($owner, 'owner')->post(route('workspaces.images.store', $ws), [
            'images' => [UploadedFile::fake()->image('big.jpg')->size(6000)],
        ])->assertSessionHasErrors('images.0');

        $this->assertSame(0, $ws->images()->count());
    }

    public function test_the_twenty_image_limit_counts_existing_photos(): void
    {
        $owner = $this->owner();
        $ws = $this->workspace($owner);
        for ($i = 1; $i <= 19; $i++) {
            $this->image($ws, $i, $i === 1);
        }

        $this->actingAs($owner, 'owner')->post(route('workspaces.images.store', $ws), [
            'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ])->assertSessionHas('error', __('app.workspace.workspace_images_limit', ['max' => 20]));
        $this->assertSame(19, $ws->images()->count());

        $this->actingAs($owner, 'owner')->post(route('workspaces.images.store', $ws), [
            'images' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertSessionHas('success');
        $this->assertSame(20, $ws->images()->count());

        // More than 20 files in one request fails validation outright.
        $many = array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg", 10, 10), range(1, 21));
        $this->actingAs($owner, 'owner')->post(route('workspaces.images.store', $this->workspace($owner, 'Other')), ['images' => $many])
            ->assertSessionHasErrors('images');
    }

    // ---------------------------------------------------------------- primary / reorder / delete

    public function test_setting_a_primary_leaves_exactly_one_primary(): void
    {
        $owner = $this->owner();
        $ws = $this->workspace($owner);
        $a = $this->image($ws, 1, true);
        $b = $this->image($ws, 2);
        $c = $this->image($ws, 3);

        $this->actingAs($owner, 'owner')->postJson(route('workspaces.images.primary', [$ws, $c]))
            ->assertOk()->assertJson(['success' => true, 'message' => __('app.workspace.workspace_image_primary_set')]);

        $this->assertSame([$c->id], $ws->images()->where('is_primary', true)->pluck('id')->all());
        $this->assertSame($c->id, $ws->fresh()->primaryImage()->id);
    }

    public function test_deleting_the_primary_promotes_the_next_image_and_removes_its_files(): void
    {
        $owner = $this->owner();
        $ws = $this->workspace($owner);
        $a = $this->image($ws, 1, true);
        $b = $this->image($ws, 2);
        $c = $this->image($ws, 3);

        $this->actingAs($owner, 'owner')->deleteJson(route('workspaces.images.destroy', [$ws, $a]))->assertOk();

        $this->assertNull($a->fresh());
        Storage::disk('public')->assertMissing([$a->path, $a->thumbnail_path]);
        Storage::disk('public')->assertExists([$b->path, $b->thumbnail_path, $c->path, $c->thumbnail_path]);
        $this->assertTrue($b->fresh()->is_primary);
        $this->assertSame(1, $ws->images()->where('is_primary', true)->count());
    }

    public function test_deleting_a_non_primary_image_keeps_the_primary(): void
    {
        $owner = $this->owner();
        $ws = $this->workspace($owner);
        $a = $this->image($ws, 1, true);
        $b = $this->image($ws, 2);

        $this->actingAs($owner, 'owner')->delete(route('workspaces.images.destroy', [$ws, $b]))->assertRedirect()->assertSessionHas('success');

        $this->assertTrue($a->fresh()->is_primary);
        Storage::disk('public')->assertMissing($b->path);
    }

    public function test_deleting_the_last_image_leaves_a_valid_workspace_with_no_images(): void
    {
        $owner = $this->owner();
        $ws = $this->workspace($owner);
        $only = $this->image($ws, 1, true);

        $this->actingAs($owner, 'owner')->deleteJson(route('workspaces.images.destroy', [$ws, $only]))->assertOk();

        $ws->refresh();
        $this->assertNotNull($ws);
        $this->assertSame(0, $ws->images()->count());
        $this->assertNull($ws->primaryImage());
        $this->assertNull($ws->primaryImageUrl());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->actingAs($owner, 'owner')->get(route('workspaces.edit', $ws))->assertOk()->assertSee(__('app.workspace.workspace_photos_empty'));
    }

    public function test_reorder_persists_order_and_ignores_foreign_and_unknown_ids(): void
    {
        $owner = $this->owner();
        $ws = $this->workspace($owner);
        $a = $this->image($ws, 1, true);
        $b = $this->image($ws, 2);
        $c = $this->image($ws, 3);
        $foreign = $this->image($this->workspace($this->owner()), 1, true);

        $this->actingAs($owner, 'owner')->postJson(route('workspaces.images.reorder', $ws), ['order' => [$c->id, $foreign->id, $a->id, 999999, $b->id]])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame([$c->id, $a->id, $b->id], $ws->images()->pluck('id')->all());
        $this->assertSame(1, $foreign->fresh()->sort_order, 'Another workspace\'s image is untouched.');
        $this->assertTrue($a->fresh()->is_primary, 'Reordering never changes the primary.');
    }

    // ---------------------------------------------------------------- authorization / tenancy

    public function test_another_owner_gets_404_on_every_route_and_nothing_changes(): void
    {
        $owner = $this->owner();
        $theirs = $this->workspace($this->owner());
        $image = $this->image($theirs, 1, true);
        $other = $this->image($theirs, 2);

        $this->actingAs($owner, 'owner')->getJson(route('workspaces.images.index', $theirs))->assertNotFound();
        $this->actingAs($owner, 'owner')->post(route('workspaces.images.store', $theirs), ['images' => [UploadedFile::fake()->image('a.jpg')]])->assertNotFound();
        $this->actingAs($owner, 'owner')->post(route('workspaces.images.primary', [$theirs, $other]))->assertNotFound();
        $this->actingAs($owner, 'owner')->post(route('workspaces.images.reorder', $theirs), ['order' => [$other->id, $image->id]])->assertNotFound();
        $this->actingAs($owner, 'owner')->delete(route('workspaces.images.destroy', [$theirs, $image]))->assertNotFound();

        $this->assertSame(2, $theirs->images()->count());
        $this->assertTrue($image->fresh()->is_primary);
        Storage::disk('public')->assertExists([$image->path, $image->thumbnail_path]);
    }

    public function test_an_image_must_belong_to_the_workspace_in_the_url(): void
    {
        $owner = $this->owner();
        $mine = $this->workspace($owner, 'A');
        $alsoMine = $this->workspace($owner, 'B');
        $image = $this->image($alsoMine, 1, true);

        // Same owner, wrong workspace in the URL → not found, nothing touched.
        $this->actingAs($owner, 'owner')->delete(route('workspaces.images.destroy', [$mine, $image]))->assertNotFound();
        $this->actingAs($owner, 'owner')->post(route('workspaces.images.primary', [$mine, $image]))->assertNotFound();

        $this->assertNotNull($image->fresh());
        Storage::disk('public')->assertExists($image->path);
    }

    public function test_staff_need_workspaces_manage_to_change_photos_but_view_can_list(): void
    {
        $owner = $this->owner();
        $ws = $this->workspace($owner);
        $image = $this->image($ws, 1, true);
        $second = $this->image($ws, 2);
        $receptionist = $this->staff($owner, 'receptionist'); // workspaces.view only

        $this->actingAs($receptionist, 'staff')->getJson(route('workspaces.images.index', $ws))->assertOk()->assertJsonCount(2, 'images');

        $this->actingAs($receptionist, 'staff')->post(route('workspaces.images.store', $ws), ['images' => [UploadedFile::fake()->image('a.jpg')]]);
        $this->actingAs($receptionist, 'staff')->post(route('workspaces.images.primary', [$ws, $second]));
        $this->actingAs($receptionist, 'staff')->post(route('workspaces.images.reorder', $ws), ['order' => [$second->id, $image->id]]);
        $this->actingAs($receptionist, 'staff')->delete(route('workspaces.images.destroy', [$ws, $image]));

        $this->assertSame(2, $ws->images()->count());
        $this->assertTrue($image->fresh()->is_primary);
        $this->assertSame(1, $image->fresh()->sort_order);

        // Staff who were granted workspaces.manage can (no system role includes it; owners grant it).
        $manager = $this->staff($owner, 'manager');
        $manager->permissions()->attach(Permission::where('key', 'workspaces.manage')->value('id'), ['granted_at' => now(), 'granted_by_owner_id' => $owner->id]);
        $this->actingAs($manager, 'staff')->post(route('workspaces.images.store', $ws), ['images' => [UploadedFile::fake()->image('m.jpg')]])
            ->assertSessionHas('success');
        $this->assertSame(3, $ws->images()->count());
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $ws = $this->workspace($this->owner());

        $this->get(route('workspaces.images.index', $ws))->assertRedirect(route('login'));
        $this->post(route('workspaces.images.store', $ws), ['images' => [UploadedFile::fake()->image('a.jpg')]])->assertRedirect(route('login'));
        $this->assertSame(0, $ws->images()->count());
    }

    // ---------------------------------------------------------------- failures & cleanup

    public function test_a_failure_while_saving_the_row_leaves_no_files_and_no_rows(): void
    {
        $owner = $this->owner();
        $ws = $this->workspace($owner);
        WorkspaceImage::creating(function () {
            throw new \RuntimeException('simulated database failure');
        });

        $this->actingAs($owner, 'owner')->post(route('workspaces.images.store', $ws), [
            'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ])->assertRedirect()
            ->assertSessionHas('error', trans_choice('app.workspace.images_partial_failure', 2, ['count' => 2]))
            ->assertSessionMissing('success');

        $this->assertSame(0, $ws->images()->count());
        $this->assertSame([], Storage::disk('public')->allFiles(), 'Originals and thumbnails of failed uploads are removed.');
    }

    public function test_a_partial_failure_keeps_the_successful_images(): void
    {
        $owner = $this->owner();
        $ws = $this->workspace($owner);
        $calls = 0;
        WorkspaceImage::creating(function () use (&$calls) {
            if (++$calls === 2) {
                throw new \RuntimeException('second file fails');
            }
        });

        $this->actingAs($owner, 'owner')->post(route('workspaces.images.store', $ws), [
            'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->image('c.jpg')],
        ])->assertSessionHas('success')->assertSessionHas('error', trans_choice('app.workspace.images_partial_failure', 1, ['count' => 1]));

        $images = $ws->images()->get();
        $this->assertCount(2, $images);
        $this->assertSame(1, $images->where('is_primary', true)->count());
        // Exactly the files of the two saved images remain — nothing orphaned from the failed one.
        $expected = $images->flatMap(fn ($i) => [$i->path, $i->thumbnail_path])->sort()->values()->all();
        $this->assertSame($expected, collect(Storage::disk('public')->allFiles())->sort()->values()->all());
    }

    public function test_a_failed_thumbnail_still_saves_the_image_and_falls_back_to_the_full_url(): void
    {
        $owner = $this->owner();
        $ws = $this->workspace($owner);
        $this->app->instance(ImageUploadService::class, new class extends ImageUploadService
        {
            protected function makeThumbnail(UploadedFile $file, string $originalPath): ?string
            {
                return null; // e.g. GD unavailable or an undecodable image
            }
        });

        $this->actingAs($owner, 'owner')->post(route('workspaces.images.store', $ws), [
            'images' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertSessionHas('success');

        $image = $ws->images()->sole();
        $this->assertNull($image->thumbnail_path);
        $this->assertSame($image->url(), $image->thumbnailUrl());
        $this->assertSame([$image->path], Storage::disk('public')->allFiles());
    }

    public function test_storage_errors_inside_the_service_clean_up_what_was_written(): void
    {
        $service = new class extends ImageUploadService
        {
            protected function makeThumbnail(UploadedFile $file, string $originalPath): ?string
            {
                throw new \RuntimeException('disk full');
            }
        };

        try {
            $service->store(UploadedFile::fake()->image('a.jpg'), 'workspace-images/1');
            $this->fail('Expected the exception to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // ---------------------------------------------------------------- urls / serialization / lifecycle

    public function test_urls_and_serialization_never_expose_storage_paths(): void
    {
        $owner = $this->owner();
        $ws = $this->workspace($owner);
        $primary = $this->image($ws, 1, true);
        $this->image($ws, 2);

        $this->assertSame(asset('storage/'.$primary->path), $primary->url());
        $this->assertSame(asset('storage/'.$primary->thumbnail_path), $primary->thumbnailUrl());
        $this->assertSame($primary->url(), $ws->fresh()->primaryImageUrl());
        $this->assertSame($primary->thumbnailUrl(), $ws->fresh()->primaryThumbnailUrl());

        $json = $primary->toArray();
        $this->assertSame($primary->url(), $json['url']);
        $this->assertSame($primary->thumbnailUrl(), $json['thumbnail_url']);
        $this->assertArrayNotHasKey('path', $json);
        $this->assertArrayNotHasKey('thumbnail_path', $json);
        $this->assertArrayNotHasKey('owner_id', $json);

        $response = $this->actingAs($owner, 'owner')->getJson(route('workspaces.images.index', $ws))->assertOk()
            ->assertJsonPath('images.0.id', $primary->id)
            ->assertJsonPath('images.0.is_primary', true)
            ->assertJsonPath('images.0.url', $primary->url())
            ->assertJsonPath('max', 20);
        $this->assertStringNotContainsString('"path"', $response->getContent());
    }

    public function test_deleting_a_workspace_removes_its_image_files(): void
    {
        $owner = $this->owner();
        $ws = $this->workspace($owner);
        $a = $this->image($ws, 1, true);
        $b = $this->image($ws, 2);
        $keep = $this->image($this->workspace($owner, 'Other'), 1, true);

        $this->actingAs($owner, 'owner')->delete(route('workspaces.destroy', $ws))->assertRedirect(route('workspaces.index'));

        $this->assertNull($ws->fresh());
        $this->assertSame(0, WorkspaceImage::where('workspace_id', $ws->id)->count());
        Storage::disk('public')->assertMissing([$a->path, $a->thumbnail_path, $b->path, $b->thumbnail_path]);
        Storage::disk('public')->assertExists([$keep->path, $keep->thumbnail_path]);
    }

    public function test_workspace_photos_do_not_touch_room_photos(): void
    {
        $owner = $this->owner();
        $ws = $this->workspace($owner);
        $room = Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'R', 'type' => 'meeting', 'capacity' => 4, 'price_per_hour' => 50]);
        Storage::disk('public')->put('room-images/'.$room->id.'/r.jpg', 'r');
        $roomImage = RoomImage::create(['owner_id' => $owner->id, 'room_id' => $room->id, 'path' => 'room-images/'.$room->id.'/r.jpg', 'is_cover' => true, 'sort_order' => 1]);
        $wsImage = $this->image($ws, 1, true);

        $this->actingAs($owner, 'owner')->delete(route('workspaces.images.destroy', [$ws, $wsImage]))->assertRedirect();

        $this->assertNotNull($roomImage->fresh());
        $this->assertTrue($roomImage->fresh()->is_cover);
        Storage::disk('public')->assertExists($roomImage->path);
    }

    // ---------------------------------------------------------------- UI

    public function test_edit_page_shows_the_gallery_and_rooms_page_links_to_it(): void
    {
        $owner = $this->owner();
        $ws = $this->workspace($owner);
        $primary = $this->image($ws, 1, true);
        $other = $this->image($ws, 2);

        $this->actingAs($owner, 'owner')->get(route('workspaces.edit', $ws))->assertOk()
            ->assertSee('id="workspace-photos"', false)
            ->assertSee(__('app.workspace.workspace_photos'))
            ->assertSee(__('app.workspace.primary_photo'))
            ->assertSee(__('app.workspace.photos_limits', ['max' => 20]))
            ->assertSee($primary->thumbnailUrl(), false)
            ->assertSee(route('workspaces.images.primary', [$ws, $other]), false)
            ->assertSee(route('workspaces.images.destroy', [$ws, $other]), false)
            ->assertSee(route('workspaces.images.reorder', $ws), false)
            ->assertDontSee(route('workspaces.images.primary', [$ws, $primary]), false);

        $this->actingAs($owner, 'owner')->get(route('workspaces.index', ['workspace' => $ws->id]))->assertOk()
            ->assertSee(route('workspaces.edit', $ws).'#workspace-photos', false);

        // View-only staff don't get the entry point (and can't open the edit page).
        $receptionist = $this->staff($owner, 'receptionist');
        $this->actingAs($receptionist, 'staff')->get(route('workspaces.index', ['workspace' => $ws->id]))->assertOk()
            ->assertDontSee(route('workspaces.edit', $ws).'#workspace-photos', false);
    }

    public function test_legacy_id_paths_keep_working_alongside_new_id_free_uploads(): void
    {
        $owner = $this->owner();
        $ws = $this->workspace($owner);
        $legacy = $this->image($ws, 1, true);
        $legacyUrl = $legacy->url();
        $this->assertStringContainsString("/storage/workspace-images/{$ws->id}/", $legacyUrl);

        $this->actingAs($owner, 'owner')->post(route('workspaces.images.store', $ws), ['images' => [UploadedFile::fake()->image('new.jpg')]])
            ->assertSessionHasNoErrors();
        $new = $ws->images()->where('id', '!=', $legacy->id)->sole();

        // Old row: untouched path, URL and file; still primary.
        $legacy->refresh();
        $this->assertSame($legacyUrl, $legacy->url());
        $this->assertTrue($legacy->is_primary);
        Storage::disk('public')->assertExists([$legacy->path, $legacy->thumbnail_path]);
        // New row: id-free path.
        $this->assertStringNotContainsString("workspace-images/{$ws->id}/", $new->path);
        $this->assertStringNotContainsString("/{$ws->id}/", $new->url());

        // Primary, reorder and delete work across both layouts, and delete removes only that row's files.
        $this->actingAs($owner, 'owner')->postJson(route('workspaces.images.primary', [$ws, $new]))->assertOk();
        $this->assertTrue($new->fresh()->is_primary);
        $this->actingAs($owner, 'owner')->postJson(route('workspaces.images.reorder', $ws), ['order' => [$new->id, $legacy->id]])->assertOk();
        $this->assertSame([$new->id, $legacy->id], $ws->images()->pluck('id')->all());
        $this->actingAs($owner, 'owner')->deleteJson(route('workspaces.images.destroy', [$ws, $legacy]))->assertOk();
        Storage::disk('public')->assertMissing([$legacy->path, $legacy->thumbnail_path]);
        Storage::disk('public')->assertExists([$new->path, $new->thumbnail_path]);
    }

    public function test_public_api_urls_of_new_uploads_carry_no_internal_id(): void
    {
        $owner = $this->owner();
        $ws = $this->workspace($owner);
        Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Board', 'type' => 'meeting', 'capacity' => 6, 'price_per_hour' => 120]);
        $legacy = $this->image($ws, 1, true);
        $this->actingAs($owner, 'owner')->post(route('workspaces.images.store', $ws), ['images' => [UploadedFile::fake()->image('new.jpg')]])
            ->assertSessionHasNoErrors();
        $new = $ws->images()->where('id', '!=', $legacy->id)->sole();
        $ws->forceFill(['description' => 'Quiet desks', 'publication_status' => Workspace::PUBLISHED, 'slug' => 'main-space'])->save();
        auth('owner')->logout();

        $images = $this->getJson('/api/v1/workspaces/main-space')->assertOk()->json('data.images');

        $this->assertSame([$legacy->url(), $new->url()], array_column($images, 'url'), 'Existing URL unchanged; new one listed too.');
        $this->assertSame($new->thumbnailUrl(), $images[1]['thumbnail_url']);
        $this->assertMatchesRegularExpression('#/storage/workspace-images/[A-Za-z0-9]{40}\.jpe?g$#', $images[1]['url']);
        $this->assertMatchesRegularExpression('#/storage/workspace-images/[A-Za-z0-9]{40}-thumb\.webp$#', $images[1]['thumbnail_url']);
    }

    public function test_edit_page_renders_in_arabic(): void
    {
        $owner = $this->owner();
        $ws = $this->workspace($owner);

        $this->actingAs($owner, 'owner')->withSession(['locale' => 'ar'])->get(route('workspaces.edit', $ws))->assertOk()
            ->assertSee('صور مساحة العمل')
            ->assertSee('لا توجد صور لمساحة العمل بعد.');
    }
}
