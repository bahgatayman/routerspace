<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomImage;
use App\Models\Staff;
use App\Models\Workspace;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Room photo gallery: upload, validation, authorization (workspaces.manage),
 * tenant isolation, cover/reorder/delete, and file cleanup on disk.
 */
class RoomImagesTest extends TestCase
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

    private function room(Owner $owner): Room
    {
        $ws = Workspace::firstOrCreate(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create([
            'owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Room '.uniqid(),
            'type' => 'meeting', 'capacity' => 6, 'price_per_hour' => 100,
        ]);
    }

    public function test_owner_can_upload_multiple_photos_and_the_first_becomes_the_cover(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);

        $this->actingAs($owner, 'owner')->post(route('rooms.images.store', [$room->workspace, $room]), [
            'images' => [
                UploadedFile::fake()->image('a.jpg', 800, 600),
                UploadedFile::fake()->image('b.jpg', 800, 600),
            ],
        ])->assertRedirect()->assertSessionHas('success');

        $room->refresh();
        $this->assertCount(2, $room->images);
        $this->assertSame(1, $room->images()->where('is_cover', true)->count());
        $this->assertTrue($room->images()->orderBy('sort_order')->first()->is_cover);

        foreach ($room->images as $image) {
            Storage::disk('public')->assertExists($image->path);
        }
    }

    public function test_upload_rejects_a_non_image_file(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);

        $this->actingAs($owner, 'owner')->post(route('rooms.images.store', [$room->workspace, $room]), [
            'images' => [UploadedFile::fake()->create('not-a-photo.pdf', 100, 'application/pdf')],
        ])->assertSessionHasErrors('images.0');

        $this->assertCount(0, $room->fresh()->images);
    }

    public function test_upload_rejects_an_oversized_image(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);

        $this->actingAs($owner, 'owner')->post(route('rooms.images.store', [$room->workspace, $room]), [
            'images' => [UploadedFile::fake()->image('big.jpg')->size(6000)], // > 5120 KB limit
        ])->assertSessionHasErrors('images.0');

        $this->assertCount(0, $room->fresh()->images);
    }

    public function test_upload_is_rejected_once_the_per_room_limit_is_reached(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        for ($i = 0; $i < 20; $i++) {
            RoomImage::create(['owner_id' => $owner->id, 'room_id' => $room->id, 'path' => "room-images/{$room->id}/x{$i}.jpg", 'sort_order' => $i]);
        }

        $this->actingAs($owner, 'owner')->post(route('rooms.images.store', [$room->workspace, $room]), [
            'images' => [UploadedFile::fake()->image('one-more.jpg')],
        ])->assertSessionHas('error');

        $this->assertCount(20, $room->fresh()->images);
    }

    public function test_staff_without_workspaces_manage_permission_cannot_upload_delete_or_reorder(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $image = RoomImage::create(['owner_id' => $owner->id, 'room_id' => $room->id, 'path' => 'room-images/x.jpg', 'is_cover' => true, 'sort_order' => 1]);

        $role = Role::whereNull('owner_id')->where('key', 'receptionist')->firstOrFail();
        $staff = Staff::create(['owner_id' => $owner->id, 'role_id' => $role->id, 'name' => 'R', 'email' => 'r'.uniqid().'@t.local', 'password' => 'secret123', 'is_active' => true]);
        $staff->syncPermissionsFromRole();

        $this->actingAs($staff, 'staff')->post(route('rooms.images.store', [$room->workspace, $room]), [
            'images' => [UploadedFile::fake()->image('a.jpg')],
        ]);
        $this->actingAs($staff, 'staff')->delete(route('rooms.images.destroy', [$room->workspace, $room, $image]));
        $this->actingAs($staff, 'staff')->post(route('rooms.images.reorder', [$room->workspace, $room]), ['order' => [$image->id]]);

        $this->assertCount(1, $room->fresh()->images, 'No workspaces.manage -> every mutation is blocked.');
        $this->assertTrue($image->fresh()->is_cover);
    }

    public function test_owner_cannot_upload_delete_or_reorder_another_owners_room_photos(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $theirRoom = $this->room($other);
        $theirImage = RoomImage::create(['owner_id' => $other->id, 'room_id' => $theirRoom->id, 'path' => 'room-images/theirs.jpg', 'is_cover' => true, 'sort_order' => 1]);
        Storage::disk('public')->put($theirImage->path, 'fake-bytes');

        $this->actingAs($owner, 'owner')->post(route('rooms.images.store', [$theirRoom->workspace, $theirRoom]), [
            'images' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertNotFound();

        $this->actingAs($owner, 'owner')->delete(route('rooms.images.destroy', [$theirRoom->workspace, $theirRoom, $theirImage]))->assertNotFound();
        $this->actingAs($owner, 'owner')->post(route('rooms.images.cover', [$theirRoom->workspace, $theirRoom, $theirImage]))->assertNotFound();
        $this->actingAs($owner, 'owner')->post(route('rooms.images.reorder', [$theirRoom->workspace, $theirRoom]), ['order' => [$theirImage->id]])->assertNotFound();

        $this->assertCount(1, $theirRoom->fresh()->images);
        Storage::disk('public')->assertExists($theirImage->path);
    }

    public function test_setting_a_new_cover_unsets_the_previous_one(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $first = RoomImage::create(['owner_id' => $owner->id, 'room_id' => $room->id, 'path' => 'room-images/1.jpg', 'is_cover' => true, 'sort_order' => 1]);
        $second = RoomImage::create(['owner_id' => $owner->id, 'room_id' => $room->id, 'path' => 'room-images/2.jpg', 'is_cover' => false, 'sort_order' => 2]);

        $this->actingAs($owner, 'owner')->post(route('rooms.images.cover', [$room->workspace, $room, $second]))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertFalse($first->fresh()->is_cover);
        $this->assertTrue($second->fresh()->is_cover);
    }

    public function test_deleting_the_cover_photo_promotes_the_next_one(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $first = RoomImage::create(['owner_id' => $owner->id, 'room_id' => $room->id, 'path' => 'room-images/1.jpg', 'is_cover' => true, 'sort_order' => 1]);
        $second = RoomImage::create(['owner_id' => $owner->id, 'room_id' => $room->id, 'path' => 'room-images/2.jpg', 'is_cover' => false, 'sort_order' => 2]);
        Storage::disk('public')->put($first->path, 'fake-bytes');

        $this->actingAs($owner, 'owner')->delete(route('rooms.images.destroy', [$room->workspace, $room, $first]))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertCount(1, $room->fresh()->images);
        $this->assertTrue($second->fresh()->is_cover);
        Storage::disk('public')->assertMissing($first->path);
    }

    public function test_reorder_persists_the_submitted_order_and_ignores_foreign_ids(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $a = RoomImage::create(['owner_id' => $owner->id, 'room_id' => $room->id, 'path' => 'room-images/a.jpg', 'sort_order' => 1]);
        $b = RoomImage::create(['owner_id' => $owner->id, 'room_id' => $room->id, 'path' => 'room-images/b.jpg', 'sort_order' => 2]);

        $other = $this->owner();
        $theirImage = RoomImage::create(['owner_id' => $other->id, 'room_id' => $this->room($other)->id, 'path' => 'room-images/x.jpg', 'sort_order' => 1]);

        $this->actingAs($owner, 'owner')->post(route('rooms.images.reorder', [$room->workspace, $room]), [
            'order' => [$b->id, $theirImage->id, $a->id],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(1, $b->fresh()->sort_order);
        $this->assertSame(2, $a->fresh()->sort_order);
        $this->assertSame(1, $theirImage->fresh()->sort_order, "Another owner's image must be untouched.");
    }

    public function test_json_requests_get_a_json_response_instead_of_a_redirect(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $image = RoomImage::create(['owner_id' => $owner->id, 'room_id' => $room->id, 'path' => 'room-images/1.jpg', 'is_cover' => true, 'sort_order' => 1]);

        $this->actingAs($owner, 'owner')
            ->postJson(route('rooms.images.cover', [$room->workspace, $room, $image]))
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    // ------------------------------------------- shared ImageUploadService regression

    public function test_room_upload_still_stores_under_the_room_folder_with_a_webp_thumbnail(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);

        $this->actingAs($owner, 'owner')->post(route('rooms.images.store', [$room->workspace, $room]), [
            'images' => [UploadedFile::fake()->image('photo.jpg', 1600, 1200)],
        ])->assertSessionHas('success');

        $image = $room->images()->sole();
        $this->assertStringStartsWith("room-images/{$room->id}/", $image->path);
        $this->assertSame(preg_replace('/\.[^.]+$/', '', $image->path).'-thumb.webp', $image->thumbnail_path);
        Storage::disk('public')->assertExists([$image->path, $image->thumbnail_path]);

        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($image->thumbnail_path));
        $this->assertSame(640, max($w, $h), 'Thumbnail keeps the 640px bound.');
        $this->assertSame(asset('storage/'.$image->thumbnail_path), $image->thumbnailUrl());
    }

    public function test_room_upload_that_fails_to_save_leaves_no_orphaned_files(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        RoomImage::creating(function () {
            throw new \RuntimeException('simulated database failure');
        });

        $this->actingAs($owner, 'owner')->post(route('rooms.images.store', [$room->workspace, $room]), [
            'images' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertSessionHas('error');

        $this->assertCount(0, $room->fresh()->images);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
