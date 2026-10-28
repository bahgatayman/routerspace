<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Plan;
use App\Models\Workspace;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * workspaces.description was VARCHAR(255) while the forms allow 1000 chars;
 * 2026_10_26_000001 widens it to TEXT (forward-only, lossless).
 */
class WorkspaceDescriptionLengthTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): Owner
    {
        $this->seed(FeatureSeeder::class);
        $plan = Plan::create(['name' => 'Free', 'slug' => 'p-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['workspace'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);
        $owner = Owner::create(['name' => 'Karim', 'email' => 'k'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Biz',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth()]);
        $owner->enableFeature('workspace');

        return $owner;
    }

    private function fields(string $description): array
    {
        return ['name' => 'Cairo Desk', 'address' => '10 Tahrir St', 'city' => 'Cairo', 'phone' => '01012345678', 'description' => $description];
    }

    public function test_description_column_is_text_and_other_workspace_indexes_survive(): void
    {
        $this->assertSame('text', Schema::getColumnType('workspaces', 'description'));

        // The column change rebuilds the table on SQLite — the publication indexes must still be there.
        $indexes = collect(Schema::getIndexes('workspaces'));
        $this->assertTrue($indexes->contains(fn ($i) => $i['columns'] === ['slug'] && $i['unique']), 'slug unique index');
        $this->assertTrue($indexes->contains(fn ($i) => $i['columns'] === ['publication_status']), 'publication_status index');
    }

    public function test_a_1000_character_description_is_saved_and_returned_intact(): void
    {
        $owner = $this->owner();
        $long = str_repeat('Quiet desks, fast Wi-Fi! ', 39).'Quiet desks, fast Wi-Fi!!'; // exactly 1000 chars, no edge whitespace (inputs are trimmed)
        $this->assertSame(1000, mb_strlen($long));

        $this->actingAs($owner, 'owner')->post(route('workspaces.store'), $this->fields($long))->assertSessionHasNoErrors();
        $ws = Workspace::where('owner_id', $owner->id)->sole();
        $this->assertSame($long, $ws->description);

        $arabic = str_repeat('مساحة هادئة ', 82).'أهلًا'; // multi-byte, ≤ 1000 chars
        $this->assertLessThanOrEqual(1000, mb_strlen($arabic));
        $this->actingAs($owner, 'owner')->put(route('workspaces.update', $ws), $this->fields($arabic))->assertSessionHasNoErrors();
        $this->assertSame($arabic, $ws->fresh()->description);

        $this->actingAs($owner, 'owner')->put(route('workspaces.update', $ws), $this->fields(str_repeat('x', 1001)))
            ->assertSessionHasErrors('description');
        $this->assertSame($arabic, $ws->fresh()->description);
    }

    public function test_long_descriptions_reach_the_public_api_untruncated(): void
    {
        $owner = $this->owner();
        $long = str_repeat('Bright rooms with natural light. ', 30);
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Long Desk', 'address' => '1 St', 'city' => 'Cairo', 'description' => $long]);
        $ws->images()->create(['owner_id' => $owner->id, 'path' => 'workspace-images/x.jpg', 'is_primary' => true, 'sort_order' => 1]);
        $ws->rooms()->create(['owner_id' => $owner->id, 'name' => 'R', 'type' => 'meeting', 'capacity' => 4, 'price_per_hour' => 50]);
        $ws->forceFill(['publication_status' => Workspace::PUBLISHED, 'slug' => 'long-desk'])->save();

        $this->getJson('/api/v1/workspaces/long-desk')->assertOk()->assertJsonPath('data.description', $long);
    }

    public function test_rollback_refuses_to_truncate_long_descriptions(): void
    {
        $owner = $this->owner();
        Workspace::create(['owner_id' => $owner->id, 'name' => 'Long', 'description' => str_repeat('a', 300)]);

        $migration = require database_path('migrations/2026_10_26_000001_change_workspace_description_to_text.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('longer than 255 characters');
        $migration->down();
    }
}
