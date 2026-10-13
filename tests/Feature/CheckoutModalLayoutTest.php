<?php

namespace Tests\Feature;

use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Room;
use App\Models\SharedSession;
use App\Models\Workspace;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Simplified End Session / Checkout modal on Active Sessions: searchable product
 * picker (own catalog only), no repeated User/Room rows, summary + actions in the
 * footer. Billing itself is unchanged and covered by the session/pricing tests.
 */
class CheckoutModalLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
    }

    private function owner(): Owner
    {
        $plan = Plan::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['workspace', 'booking', 'sales'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);
        $owner = Owner::create(['name' => 'O', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Space',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth()]);
        foreach ($plan->features as $key) {
            $owner->enableFeature($key);
        }

        return $owner;
    }

    private function openSession(Owner $owner): SharedSession
    {
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'WS']);
        $room = Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Hall', 'type' => 'shared', 'capacity' => 8, 'price_per_hour' => 50]);
        $user = HotspotUser::create(['owner_id' => $owner->id, 'name' => 'Guest', 'phone' => '010'.rand(10000000, 99999999), 'password' => 'x']);

        return SharedSession::create(['owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $user->id,
            'session_date' => today()->toDateString(), 'start_time' => now()->format('H:i'), 'opened_at' => now()->subMinutes(7),
            'status' => 'open', 'billing_unit' => 'minute', 'billed_price_per_hour' => 50]);
    }

    public function test_checkout_modal_renders_the_simplified_layout_with_a_searchable_picker(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $this->openSession($owner);
        $mine = Product::create(['owner_id' => $owner->id, 'name' => 'Espresso', 'type' => 'product', 'price' => 35, 'is_active' => true]);
        Product::create(['owner_id' => $owner->id, 'name' => 'Old Tea', 'type' => 'product', 'price' => 5, 'is_active' => false]);
        Product::create(['owner_id' => $other->id, 'name' => 'Foreign Cola', 'type' => 'product', 'price' => 9, 'is_active' => true]);

        $html = $this->actingAs($owner, 'owner')->get('/active-sessions')->assertOk()->getContent();

        $this->assertStringContainsString('id="modal-product-search"', $html);
        $this->assertStringContainsString('id="modal-picker-toggle"', $html);
        $this->assertStringContainsString('data-add-product="'.$mine->id.'"', $html);
        $this->assertStringNotContainsString('Old Tea', $html);          // inactive products aren't offered
        $this->assertStringNotContainsString('Foreign Cola', $html);     // never another business's catalog
        $this->assertStringContainsString('class="ls-co-summary"', $html);
        $this->assertStringContainsString('id="confirm-close-btn"', $html);
        // The repeated User / Room rows are gone (who/where live in the title only).
        $this->assertStringNotContainsString('id="modal-user"', $html);
        $this->assertStringNotContainsString('id="modal-room"', $html);
    }

    public function test_preview_still_returns_what_the_modal_needs(): void
    {
        $owner = $this->owner();
        $session = $this->openSession($owner);

        $this->actingAs($owner, 'owner')->getJson("/shared-sessions/{$session->id}/close-preview")
            ->assertOk()
            ->assertJsonStructure(['user_name', 'room_name', 'party_size', 'start_time', 'end_time', 'duration', 'billed_duration',
                'price_per_hour', 'pricing_note', 'total_price', 'items', 'items_total', 'grand_total', 'package']);
    }

    public function test_bill_lines_carry_their_product_so_plus_raises_the_existing_line(): void
    {
        $owner = $this->owner();
        $session = $this->openSession($owner);
        $p = Product::create(['owner_id' => $owner->id, 'name' => 'Espresso', 'type' => 'product', 'price' => 35, 'is_active' => true]);

        $this->actingAs($owner, 'owner')->postJson("/shared-sessions/{$session->id}/items", ['product_id' => $p->id, 'quantity' => 1])->assertOk();
        $item = $this->actingAs($owner, 'owner')->getJson("/shared-sessions/{$session->id}/close-preview")->assertOk()->json('items.0');
        $this->assertSame($p->id, $item['product_id']);

        // The picker's + on a product already on the bill PATCHes that line (+1) instead of adding a second line.
        $this->actingAs($owner, 'owner')->patchJson("/shared-sessions/{$session->id}/items/{$item['id']}", ['quantity' => 2])->assertOk();
        $preview = $this->actingAs($owner, 'owner')->getJson("/shared-sessions/{$session->id}/close-preview")->json();
        $this->assertCount(1, $preview['items']);
        $this->assertSame(2, (int) $preview['items'][0]['quantity']);
        $this->assertSame('70.00', $preview['items_total']);
    }

    public function test_new_strings_exist_in_english_and_arabic(): void
    {
        foreach (['co_billed', 'co_search', 'co_no_match', 'co_nothing_yet', 'co_each', 'co_add', 'co_add_one'] as $key) {
            foreach (['en', 'ar'] as $locale) {
                $this->assertNotSame("app.ui.sessions.$key", __("app.ui.sessions.$key", [], $locale), "$locale: $key");
            }
        }
    }
}
