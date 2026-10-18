<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\RoomImage;
use App\Models\Workspace;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Super Admin → business → Rooms tab shows read-only room cards with their photos. */
class AdminBusinessRoomCardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
    }

    private function owner(string $name): Owner
    {
        $plan = Plan::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['workspace'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);

        return Owner::create(['name' => $name, 'email' => strtolower($name).uniqid().'@t.local', 'password' => 'secret123', 'business_name' => $name,
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth()]);
    }

    private function room(Owner $owner, string $name): Room
    {
        $ws = Workspace::firstOrCreate(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => $name, 'type' => 'meeting', 'capacity' => 6, 'price_per_hour' => 100]);
    }

    public function test_rooms_tab_shows_cards_with_photos_or_a_placeholder_for_this_business_only(): void
    {
        $admin = Admin::create(['name' => 'Root', 'email' => 'root@t.local', 'password' => bcrypt('secret123')]);
        $alpha = $this->owner('Alpha');
        $bravo = $this->owner('Bravo');

        $photo = $this->room($alpha, 'Board Room');
        foreach (['a', 'b'] as $i => $f) {
            RoomImage::create(['owner_id' => $alpha->id, 'room_id' => $photo->id, 'path' => "room-images/{$f}.jpg",
                'thumbnail_path' => "room-images/{$f}_thumb.jpg", 'is_cover' => $i === 0, 'sort_order' => $i]);
        }
        $this->room($alpha, 'Quiet Pod');
        $this->room($bravo, 'Bravo Secret Room');

        $html = $this->actingAs($admin, 'admin')->get("/admin/owners/{$alpha->id}/rooms")->assertOk()
            ->assertSee('Board Room')->assertSee('Quiet Pod')->assertDontSee('Bravo Secret Room')
            ->assertSee('room-images/a_thumb.jpg', false)
            ->assertSee('room-images/b_thumb.jpg', false)
            ->assertSee('data-ls-gallery', false)                  // swipeable: 2 photos
            ->assertSee(__('app.workspace.no_photos'))              // Quiet Pod has none
            ->assertSee('class="ls-adm-room-stats"', false)
            ->assertSee('href="/admin/rooms/'.$photo->id.'"', false)
            ->getContent();

        $this->assertSame(2, substr_count($html, 'class="ls-room is-'));
    }
}
