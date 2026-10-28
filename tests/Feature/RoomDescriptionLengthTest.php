<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\Workspace;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * rooms.description was VARCHAR(255) while room create/edit validate up to
 * 1000 characters; 2026_10_27_000001 widens it to TEXT (forward-only, lossless).
 */
class RoomDescriptionLengthTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): Workspace
    {
        $this->seed(FeatureSeeder::class);
        $plan = Plan::create(['name' => 'Free', 'slug' => 'p-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['workspace'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);
        $owner = Owner::create(['name' => 'Karim', 'email' => 'k'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Biz',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth()]);
        $owner->enableFeature('workspace');

        return Workspace::create(['owner_id' => $owner->id, 'name' => 'Main', 'address' => '1 St', 'city' => 'Cairo']);
    }

    private function roomFields(string $description): array
    {
        return ['name' => 'Board Room', 'type' => 'meeting', 'capacity' => 6, 'price_per_hour' => 100, 'description' => $description];
    }

    public function test_room_description_column_is_text_and_other_columns_are_untouched(): void
    {
        $this->assertSame('text', Schema::getColumnType('rooms', 'description'));
        $this->assertSame('varchar', Schema::getColumnType('rooms', 'name'));
        $this->assertTrue(Schema::hasColumns('rooms', ['price_per_hour', 'pricing_model', 'pricing_rules', 'billing_unit', 'billing_buffer_minutes', 'is_available']));
    }

    public function test_create_and_update_accept_up_to_1000_characters_and_reject_more(): void
    {
        $ws = $this->workspace();
        $owner = $ws->owner;
        $long = str_repeat('Natural light, whiteboard, screen. ', 28).'Seats eight..'; // 993 chars, no edge whitespace
        $this->assertLessThanOrEqual(1000, mb_strlen($long));
        $max = str_repeat('x', 1000);

        $this->actingAs($owner, 'owner')->post(route('rooms.store', $ws), $this->roomFields($long))->assertSessionHasNoErrors();
        $room = Room::where('workspace_id', $ws->id)->sole();
        $this->assertSame($long, $room->description);

        $this->actingAs($owner, 'owner')->put(route('rooms.update', [$ws, $room]), $this->roomFields($max))->assertSessionHasNoErrors();
        $this->assertSame($max, $room->fresh()->description);

        $arabic = str_repeat('غرفة مريحة ', 90); // multi-byte
        $arabic = trim($arabic);
        $this->actingAs($owner, 'owner')->put(route('rooms.update', [$ws, $room]), $this->roomFields($arabic))->assertSessionHasNoErrors();
        $this->assertSame($arabic, $room->fresh()->description);

        $this->actingAs($owner, 'owner')->put(route('rooms.update', [$ws, $room]), $this->roomFields(str_repeat('x', 1001)))
            ->assertSessionHasErrors('description');
        $this->actingAs($owner, 'owner')->post(route('rooms.store', $ws), $this->roomFields(str_repeat('y', 1001)))
            ->assertSessionHasErrors('description');
        $this->assertSame($arabic, $room->fresh()->description);
        $this->assertSame(1, Room::where('workspace_id', $ws->id)->count());
    }

    public function test_room_descriptions_are_not_part_of_the_public_api(): void
    {
        $ws = $this->workspace();
        Room::create(['owner_id' => $ws->owner_id, 'workspace_id' => $ws->id, 'name' => 'R', 'type' => 'meeting', 'capacity' => 4,
            'price_per_hour' => 50, 'description' => 'ROOM-DESCRIPTION-'.str_repeat('z', 900)]);
        $ws->forceFill(['description' => 'Workspace text', 'publication_status' => Workspace::PUBLISHED, 'slug' => 'main'])->save();
        $ws->images()->create(['owner_id' => $ws->owner_id, 'path' => 'workspace-images/x.jpg', 'is_primary' => true, 'sort_order' => 1]);

        $res = $this->getJson('/api/v1/workspaces/main')->assertOk();
        $this->assertArrayNotHasKey('description', $res->json('data.rooms.0'));
        $this->assertStringNotContainsString('ROOM-DESCRIPTION-', $res->getContent());
    }

    public function test_rollback_refuses_to_truncate_long_room_descriptions(): void
    {
        $ws = $this->workspace();
        Room::create(['owner_id' => $ws->owner_id, 'workspace_id' => $ws->id, 'name' => 'R', 'type' => 'meeting', 'capacity' => 4, 'description' => str_repeat('a', 400)]);

        $migration = require database_path('migrations/2026_10_27_000001_change_room_description_to_text.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('room description(s) are longer than 255 characters');
        $migration->down();
    }
}
