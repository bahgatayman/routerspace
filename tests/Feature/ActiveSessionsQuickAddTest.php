<?php

namespace Tests\Feature;

use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Room;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SharedSession;
use App\Models\Workspace;
use App\Services\ProductAnalyticsService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Active Sessions quick-add chips: real best sellers (completed sales, by
 * units) for each card's own workspace, topped up from the owner's overall
 * best sellers, never mixing tenants.
 */
class ActiveSessionsQuickAddTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
    }

    private function owner(): Owner
    {
        $plan = Plan::create([
            'name' => 'Test', 'slug' => 'test-'.uniqid(), 'max_members' => 100,
            'price_per_month' => 0, 'is_active' => true, 'sort_order' => 1,
            'features' => ['workspace', 'booking', 'sales'],
            'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ]);
        $owner = Owner::create([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123',
            'business_name' => 'Space', 'plan_id' => $plan->id, 'is_active' => true,
            'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
        ]);
        foreach ($plan->features as $key) {
            $owner->enableFeature($key);
        }

        return $owner;
    }

    /** Each call makes its own workspace, so two rooms = two workspaces. */
    private function room(Owner $owner, string $name): Room
    {
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => $name.' WS']);

        return Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => $name,
            'type' => 'shared', 'capacity' => 8, 'price_per_hour' => 60]);
    }

    private function openSession(Owner $owner, Room $room): SharedSession
    {
        $user = HotspotUser::create(['owner_id' => $owner->id, 'name' => 'Guest '.$room->name,
            'phone' => '010'.rand(10000000, 99999999), 'password' => 'pass1234']);

        return SharedSession::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $user->id,
            'session_date' => today()->toDateString(), 'start_time' => now()->format('H:i'),
            'opened_at' => now(), 'status' => 'open', 'billing_unit' => 'minute', 'billed_price_per_hour' => 60,
        ]);
    }

    private function sell(Owner $owner, Product $product, int $qty, ?SharedSession $session = null, string $status = 'completed'): void
    {
        $total = $product->price * $qty;
        $sale = Sale::create([
            'owner_id' => $owner->id, 'shared_session_id' => $session?->id, 'status' => $status,
            'subtotal' => $total, 'discount_total' => 0, 'tax_total' => 0, 'total' => $total, 'sold_at' => now(),
        ]);
        SaleItem::create(['sale_id' => $sale->id, 'product_id' => $product->id, 'name' => $product->name,
            'unit_price' => $product->price, 'unit_cost' => 0, 'quantity' => $qty, 'line_total' => $total]);
    }

    private function product(Owner $owner, string $name): Product
    {
        return Product::create(['owner_id' => $owner->id, 'name' => $name, 'type' => 'product', 'price' => 10, 'is_active' => true]);
    }

    /** The quick-add chip names on the card for $roomName, in display order. */
    private function chipsOnCard(string $html, string $roomName): array
    {
        foreach (explode('<article class="ls-session', $html) as $card) {
            if (str_contains($card, '>'.$roomName.'<')) {
                preg_match_all('/data-qa-name="([^"]+)"/', $card, $m);

                return $m[1];
            }
        }

        return [];
    }

    public function test_ranking_is_per_workspace_counts_completed_sales_only_and_never_mixes_tenants(): void
    {
        $owner = $this->owner();
        $roomA = $this->room($owner, 'Hall A');
        $roomB = $this->room($owner, 'Hall B');
        [$coffee, $tea, $water, $chips] = array_map(fn ($n) => $this->product($owner, $n), ['Coffee', 'Tea', 'Water', 'Chips']);

        $sessionA = $this->openSession($owner, $roomA);
        $sessionB = $this->openSession($owner, $roomB);
        $this->sell($owner, $tea, 5, $sessionA);                    // workspace A
        $this->sell($owner, $water, 9, $sessionB);                  // workspace B
        $this->sell($owner, $coffee, 3);                            // walk-in, no workspace
        $this->sell($owner, $chips, 50, $sessionA, 'cancelled');    // never counts

        $other = $this->owner();                                    // another tenant's huge Chips volume
        $otherChips = $this->product($other, 'Chips');
        $this->sell($other, $otherChips, 100, $this->openSession($other, $this->room($other, 'Elsewhere')));

        $best = app(ProductAnalyticsService::class)->bestSellersByWorkspace($owner, [$coffee->id, $tea->id, $water->id, $chips->id]);
        $this->assertSame([$tea->id], $best['byWorkspace'][$roomA->workspace_id]);
        $this->assertSame([$water->id], $best['byWorkspace'][$roomB->workspace_id]);
        $this->assertSame([$water->id, $tea->id, $coffee->id], $best['overall']);

        $html = $this->actingAs($owner, 'owner')->get('/active-sessions')->assertOk()->getContent();
        // Each card leads with its own workspace's best seller, topped up from the overall ranking.
        $this->assertSame(['Tea', 'Water', 'Coffee'], $this->chipsOnCard($html, 'Hall A'));
        $this->assertSame(['Water', 'Tea', 'Coffee'], $this->chipsOnCard($html, 'Hall B'));
    }

    public function test_an_owner_with_no_sales_yet_still_gets_quick_add_chips_in_catalog_order(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner, 'Hall A');
        foreach (['Water', 'Coffee', 'Tea', 'Juice'] as $name) {
            $this->product($owner, $name);
        }
        $this->openSession($owner, $room);

        $html = $this->actingAs($owner, 'owner')->get('/active-sessions')->assertOk()->getContent();

        $this->assertSame(['Coffee', 'Juice', 'Tea'], $this->chipsOnCard($html, 'Hall A'));
    }
}
