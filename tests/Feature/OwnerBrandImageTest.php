<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Plan;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Owner-uploaded brand image: stored on the public disk, shown in the panel
 * header and on the profile page, replaceable and removable.
 */
class OwnerBrandImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(FeatureSeeder::class);
        Plan::create([
            'name' => 'Basic', 'slug' => 'basic', 'max_members' => 100,
            'price_per_month' => 0, 'is_active' => true, 'sort_order' => 1,
        ]);
    }

    private function makeOwner(): Owner
    {
        return Owner::create([
            'name' => 'Owner', 'email' => 'o' . uniqid() . '@t.local', 'password' => 'password',
            'business_name' => 'Nile Works', 'plan_id' => Plan::first()->id, 'is_active' => true,
            'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
        ])->fresh();
    }

    public function test_owner_uploads_a_brand_image(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner, 'owner')
            ->post('/profile/logo', ['logo' => UploadedFile::fake()->image('brand.png', 400, 400)])
            ->assertRedirect();

        $owner->refresh();

        $this->assertNotNull($owner->logo_path);
        Storage::disk('public')->assertExists($owner->logo_path);
        $this->assertStringContainsString('owner-logos/', $owner->logo_path);
    }

    public function test_replacing_the_image_deletes_the_previous_file(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner, 'owner')
            ->post('/profile/logo', ['logo' => UploadedFile::fake()->image('first.png')]);
        $first = $owner->refresh()->logo_path;

        $this->actingAs($owner, 'owner')
            ->post('/profile/logo', ['logo' => UploadedFile::fake()->image('second.png')]);
        $second = $owner->refresh()->logo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_owner_removes_the_brand_image(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner, 'owner')
            ->post('/profile/logo', ['logo' => UploadedFile::fake()->image('brand.png')]);
        $path = $owner->refresh()->logo_path;

        $this->actingAs($owner, 'owner')->delete('/profile/logo')->assertRedirect();

        $this->assertNull($owner->refresh()->logo_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_non_images_and_oversized_files_are_rejected(): void
    {
        $owner = $this->makeOwner();

        $this->actingAs($owner, 'owner')
            ->post('/profile/logo', ['logo' => UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf')])
            ->assertSessionHasErrors('logo');

        // 2 MB ceiling — 3 MB must not get through.
        $this->actingAs($owner, 'owner')
            ->post('/profile/logo', ['logo' => UploadedFile::fake()->image('huge.png')->size(3072)])
            ->assertSessionHasErrors('logo');

        $this->assertNull($owner->refresh()->logo_path);
    }

    public function test_profile_page_shows_the_image_and_falls_back_to_initials(): void
    {
        $owner = $this->makeOwner();

        // No image yet → initials placeholder from the business name.
        $this->assertSame('NW', $owner->initials());
        $this->assertNull($owner->logoUrl());
        $this->actingAs($owner, 'owner')->get('/profile')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Profile/Index')
                ->where('owner.initials', 'NW')
                ->where('owner.logo_url', null));

        $this->actingAs($owner, 'owner')
            ->post('/profile/logo', ['logo' => UploadedFile::fake()->image('brand.png')]);

        $path = $owner->refresh()->logo_path;
        $this->actingAs($owner, 'owner')->get('/profile')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Profile/Index')
                ->where('owner.logo_url', fn ($url) => str_contains((string) $url, $path)));
    }

    public function test_one_owner_cannot_touch_another_owners_image(): void
    {
        $a = $this->makeOwner();
        $b = $this->makeOwner();

        $this->actingAs($a, 'owner')
            ->post('/profile/logo', ['logo' => UploadedFile::fake()->image('a.png')]);
        $aPath = $a->refresh()->logo_path;

        // The routes act on the authenticated owner only — b's delete leaves a's file alone.
        $this->actingAs($b, 'owner')->delete('/profile/logo')->assertRedirect();

        Storage::disk('public')->assertExists($aPath);
        $this->assertNotNull($a->refresh()->logo_path);
    }

    public function test_guests_cannot_upload(): void
    {
        $this->post('/profile/logo', ['logo' => UploadedFile::fake()->image('x.png')])
            ->assertRedirect('/login');
    }
}
