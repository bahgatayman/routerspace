<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\InventoryMovement;
use App\Models\Notification;
use App\Models\Owner;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Room;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SharedSession;
use App\Models\Staff;
use App\Models\Workspace;
use App\Services\InventoryService;
use App\Services\SalesService;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Inventory & product cost: pricing/margin, stock taken when a product is
 * added to a bill or tab (and returned when removed), no overselling, restock
 * and reductions with history, de-duplicated low/out alerts, cost snapshots
 * for historical profit, and tenant/feature/permission isolation.
 */
class InventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    // ------------------------------------------------------------------ fixtures

    private function owner(array $features = ['workspace', 'booking', 'sales']): Owner
    {
        $plan = Plan::create([
            'name' => 'Test', 'slug' => 'test-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0,
            'is_active' => true, 'sort_order' => 1, 'features' => $features,
            'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ]);
        $owner = Owner::create([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Space',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
        ]);
        foreach ($features as $key) {
            $owner->enableFeature($key);
        }

        return $owner;
    }

    /** A tracked product created through the real form. */
    private function cola(Owner $owner, int $stock = 20, ?int $threshold = 5, array $extra = []): Product
    {
        $this->actingAs($owner, 'owner')->post('/products', array_merge([
            'name' => 'Coca Cola', 'type' => 'product', 'price' => 25, 'purchase_price' => 15,
            'is_active' => 1, 'track_stock' => 1, 'stock_quantity' => $stock, 'low_stock_threshold' => $threshold,
        ], $extra))->assertRedirect('/products');

        return Product::where('owner_id', $owner->id)->latest('id')->firstOrFail();
    }

    /** An in-progress exclusive booking to sell to. */
    private function booking(Owner $owner): Booking
    {
        $ws = Workspace::firstOrCreate(['owner_id' => $owner->id, 'name' => 'Main']);
        $room = Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Room '.uniqid(), 'type' => 'meeting', 'capacity' => 6, 'price_per_hour' => 100]);
        $member = HotspotUser::create(['owner_id' => $owner->id, 'name' => 'Cust', 'phone' => '010'.rand(10000000, 99999999), 'password' => 'pass1234']);

        return Booking::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $member->id, 'party_size' => 1,
            'booking_date' => today()->toDateString(), 'start_time' => '09:00', 'end_time' => '18:00',
            'price_per_hour' => 100, 'total_hours' => 9, 'total_price' => 900, 'status' => 'confirmed',
        ]);
    }

    private function sell(Owner $owner, Booking $booking, Product $product, int $qty)
    {
        return $this->actingAs($owner, 'owner')->postJson("/bookings/{$booking->id}/items", ['product_id' => $product->id, 'quantity' => $qty]);
    }

    private function updateQty(Owner $owner, Booking $booking, SaleItem $item, int $qty)
    {
        return $this->actingAs($owner, 'owner')->patchJson("/bookings/{$booking->id}/items/{$item->id}", ['quantity' => $qty]);
    }

    // ----------------------------------------------------------------- pricing

    public function test_prices_profit_and_margin(): void
    {
        $owner = $this->owner();
        $p = $this->cola($owner);

        $this->assertSame('15.00', (string) $p->purchase_price);
        $this->assertSame('25.00', (string) $p->price);
        $this->assertSame(10.0, $p->profitPerUnit());
        $this->assertSame(40.0, $p->marginPercent(), 'Margin is profit ÷ selling price, not markup (66.7%).');
        $this->assertSame(300.0, $p->inventoryValue());
        $this->assertSame(500.0, $p->potentialRevenue());
        $this->assertSame(200.0, $p->potentialProfit());
    }

    public function test_invalid_prices_are_rejected(): void
    {
        $owner = $this->owner();
        $base = ['name' => 'X', 'type' => 'product', 'is_active' => 1];

        $this->actingAs($owner, 'owner')->post('/products', $base + ['price' => 0])->assertSessionHasErrors('price');
        $this->actingAs($owner, 'owner')->post('/products', $base + ['price' => 10, 'purchase_price' => -1])->assertSessionHasErrors('purchase_price');
        $this->actingAs($owner, 'owner')->post('/products', $base + ['price' => 10, 'track_stock' => 1, 'stock_quantity' => -3])->assertSessionHasErrors('stock_quantity');
        $this->actingAs($owner, 'owner')->post('/products', $base + ['price' => 10, 'track_stock' => 1, 'low_stock_threshold' => -1])->assertSessionHasErrors('low_stock_threshold');
        $this->assertDatabaseCount('products', 0);
    }

    // ------------------------------------------------------------------- stock

    public function test_initial_stock_is_recorded_as_a_movement(): void
    {
        $p = $this->cola($this->owner(), 20);

        $this->assertSame(20, $p->stock_quantity);
        $m = $p->movements()->firstOrFail();
        $this->assertSame(InventoryMovement::INITIAL, $m->type);
        $this->assertSame([20, 0, 20], [$m->quantity_change, $m->previous_quantity, $m->new_quantity]);
    }

    public function test_selling_decrements_stock_and_removing_returns_it(): void
    {
        $owner = $this->owner();
        $p = $this->cola($owner, 20);
        $booking = $this->booking($owner);

        $this->sell($owner, $booking, $p, 3)->assertOk()->assertJson(['success' => true]);
        $this->assertSame(17, $p->fresh()->stock_quantity);
        $sale = $p->movements()->where('type', InventoryMovement::SALE)->firstOrFail();
        $this->assertSame([-3, 20, 17], [$sale->quantity_change, $sale->previous_quantity, $sale->new_quantity]);

        $item = SaleItem::firstOrFail();
        $this->actingAs($owner, 'owner')->deleteJson("/bookings/{$booking->id}/items/{$item->id}")->assertOk();
        $this->assertSame(20, $p->fresh()->stock_quantity);
        $this->assertTrue($p->movements()->where('type', InventoryMovement::SALE_REMOVED)->where('quantity_change', 3)->exists());
    }

    public function test_increasing_a_line_items_quantity_takes_additional_stock(): void
    {
        $owner = $this->owner();
        $p = $this->cola($owner, 20);
        $booking = $this->booking($owner);
        $this->sell($owner, $booking, $p, 2)->assertOk();
        $item = SaleItem::firstOrFail();

        $this->updateQty($owner, $booking, $item, 5)->assertOk()->assertJson(['success' => true]);

        $this->assertSame(15, $p->fresh()->stock_quantity); // 20 - 2 (initial) - 3 (delta)
        $movement = $p->movements()->where('type', InventoryMovement::SALE)->where('sale_item_id', $item->id)->where('quantity_change', -3)->firstOrFail();
        $this->assertSame([18, 15], [$movement->previous_quantity, $movement->new_quantity]);
        $this->assertSame(5, $item->fresh()->quantity);
    }

    public function test_decreasing_a_line_items_quantity_returns_stock(): void
    {
        $owner = $this->owner();
        $p = $this->cola($owner, 20);
        $booking = $this->booking($owner);
        $this->sell($owner, $booking, $p, 5)->assertOk();
        $item = SaleItem::firstOrFail();

        $this->updateQty($owner, $booking, $item, 2)->assertOk();

        $this->assertSame(18, $p->fresh()->stock_quantity); // 20 - 5 + 3
        $movement = $p->movements()->where('type', InventoryMovement::SALE_REMOVED)->where('sale_item_id', $item->id)->where('quantity_change', 3)->firstOrFail();
        $this->assertSame([15, 18], [$movement->previous_quantity, $movement->new_quantity]);
        $this->assertSame(2, $item->fresh()->quantity);
    }

    public function test_increasing_quantity_beyond_available_stock_throws_and_writes_nothing(): void
    {
        $owner = $this->owner();
        $p = $this->cola($owner, 5);
        $booking = $this->booking($owner);
        $this->sell($owner, $booking, $p, 3)->assertOk();
        $item = SaleItem::firstOrFail();

        $this->updateQty($owner, $booking, $item, 10)->assertStatus(422);

        $this->assertSame(2, $p->fresh()->stock_quantity, 'unchanged since the initial sale of 3');
        $this->assertSame(3, $item->fresh()->quantity, 'unchanged — the failed update wrote nothing');
        $this->assertFalse($p->movements()->where('sale_item_id', $item->id)->where('quantity_change', -7)->exists());
    }

    public function test_reducing_quantity_to_zero_behaves_identically_to_remove_item(): void
    {
        $owner = $this->owner();
        $p = $this->cola($owner, 20);
        $booking = $this->booking($owner);
        $this->sell($owner, $booking, $p, 4)->assertOk();
        $item = SaleItem::firstOrFail();

        $this->updateQty($owner, $booking, $item, 0)->assertOk();

        $this->assertSame(20, $p->fresh()->stock_quantity);
        $this->assertSame(0, SaleItem::count());
        $this->assertTrue($p->movements()->where('type', InventoryMovement::SALE_REMOVED)->where('quantity_change', 4)->exists());
    }

    public function test_service_type_items_never_touch_inventory_on_quantity_change(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'owner')->post('/products', ['name' => 'Printing', 'type' => 'service', 'price' => 5, 'is_active' => 1]);
        $service = Product::where('name', 'Printing')->firstOrFail();
        $booking = $this->booking($owner);
        $this->sell($owner, $booking, $service, 2)->assertOk();
        $item = SaleItem::firstOrFail();

        $this->updateQty($owner, $booking, $item, 10)->assertOk();
        $this->updateQty($owner, $booking, $item, 1)->assertOk();

        $this->assertSame(0, InventoryMovement::count());
        $this->assertSame(1, $item->fresh()->quantity);
    }

    public function test_session_tab_takes_stock_when_added_not_again_at_close(): void
    {
        $owner = $this->owner();
        $p = $this->cola($owner, 10);
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Main']);
        $room = Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Lounge', 'type' => 'shared', 'capacity' => 10, 'price_per_hour' => 60]);
        $member = HotspotUser::create(['owner_id' => $owner->id, 'name' => 'Cust', 'phone' => '01011112222', 'password' => 'pass1234']);
        $session = SharedSession::create(['owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'party_size' => 1, 'session_date' => today()->toDateString(), 'start_time' => now()->subHour()->format('H:i'),
            'opened_at' => now()->subHour(), 'status' => 'open', 'billing_unit' => 'minute', 'billed_price_per_hour' => 60]);

        $this->actingAs($owner, 'owner')->postJson("/shared-sessions/{$session->id}/items", ['product_id' => $p->id, 'quantity' => 2])->assertOk();
        $this->assertSame(8, $p->fresh()->stock_quantity);

        $this->actingAs($owner, 'owner')->postJson("/shared-sessions/{$session->id}/close")->assertOk();
        $this->assertSame(8, $p->fresh()->stock_quantity, 'Checkout finalizes the sale without moving stock again.');
        $this->assertSame('completed', Sale::firstOrFail()->status);
    }

    public function test_overselling_is_refused_server_side_and_nothing_is_written(): void
    {
        $owner = $this->owner();
        $p = $this->cola($owner, 3);
        $booking = $this->booking($owner);

        $this->sell($owner, $booking, $p, 5)->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Only 3 units of Coca Cola are available.']);
        $this->assertSame(3, $p->fresh()->stock_quantity);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertSame(0, Sale::count(), 'The empty bill created for the failed add is rolled back too.');

        // Non-JSON (booking page) gets a flash error instead.
        $this->actingAs($owner, 'owner')->from("/bookings/{$booking->id}")
            ->post("/bookings/{$booking->id}/items", ['product_id' => $p->id, 'quantity' => 5])
            ->assertRedirect("/bookings/{$booking->id}")->assertSessionHas('error');
    }

    public function test_stock_never_goes_negative_and_races_for_the_last_units_cannot_both_win(): void
    {
        $owner = $this->owner();
        $p = $this->cola($owner, 2);
        $booking = $this->booking($owner);

        // Two requests racing for the last 2 units: the first wins, the second is refused.
        $this->sell($owner, $booking, $p, 2)->assertOk();
        $this->sell($owner, $booking, $p, 1)->assertStatus(422);
        $this->assertSame(0, $p->fresh()->stock_quantity);

        // A stale in-memory model that still "sees" 2 units can't oversell: the guarded update refuses.
        $stale = $p->replicate();
        $stale->id = $p->id;
        $stale->stock_quantity = 2;
        $sale = Sale::firstOrFail();
        $this->actingAs($owner, 'owner');
        try {
            app(SalesService::class)->addItem($sale, $stale, 1);
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException $e) {
            $this->assertSame(0, $e->available);
        }
        $this->assertSame(0, $p->fresh()->stock_quantity);
        $this->assertSame(1, SaleItem::count());
    }

    public function test_untracked_products_and_services_sell_without_limits(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'owner')->post('/products', ['name' => 'Printing', 'type' => 'service', 'price' => 5, 'is_active' => 1, 'track_stock' => 1]);
        $service = Product::where('name', 'Printing')->firstOrFail();
        $this->assertFalse($service->tracksStock(), 'Services are never stock-tracked.');

        $untracked = Product::create(['owner_id' => $owner->id, 'name' => 'Legacy', 'type' => 'product', 'price' => 10, 'is_active' => true]);
        $booking = $this->booking($owner);

        $this->sell($owner, $booking, $service, 50)->assertOk();
        $this->sell($owner, $booking, $untracked, 50)->assertOk();
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_inactive_products_cannot_be_sold(): void
    {
        $owner = $this->owner();
        $p = $this->cola($owner, 10, 5, ['is_active' => 0]);

        $this->sell($owner, $this->booking($owner), $p, 1)->assertStatus(422);
        $this->assertSame(10, $p->fresh()->stock_quantity);
    }

    // ------------------------------------------------------- restock / adjust

    public function test_restock_and_adjustment_with_reason(): void
    {
        $owner = $this->owner();
        $p = $this->cola($owner, 5);

        $this->actingAs($owner, 'owner')->post("/products/{$p->id}/stock", ['direction' => 'restock', 'quantity' => 20, 'note' => 'Supplier'])
            ->assertRedirect(route('products.show', $p));
        $this->assertSame(25, $p->fresh()->stock_quantity);

        $this->actingAs($owner, 'owner')->post("/products/{$p->id}/stock", ['direction' => 'remove', 'quantity' => 2, 'reason' => 'damaged'])->assertRedirect();
        $this->assertSame(23, $p->fresh()->stock_quantity);
        $adj = $p->movements()->where('type', InventoryMovement::ADJUSTMENT)->firstOrFail();
        $this->assertSame(['damaged', -2, 'owner', $owner->id], [$adj->reason, $adj->quantity_change, $adj->created_by_type, $adj->created_by_id]);

        // A reason is required, and you can't remove more than you have.
        $this->actingAs($owner, 'owner')->post("/products/{$p->id}/stock", ['direction' => 'remove', 'quantity' => 1])->assertSessionHasErrors('reason');
        $this->actingAs($owner, 'owner')->post("/products/{$p->id}/stock", ['direction' => 'remove', 'quantity' => 99, 'reason' => 'lost'])->assertSessionHas('error');
        $this->actingAs($owner, 'owner')->post("/products/{$p->id}/stock", ['direction' => 'restock', 'quantity' => -5])->assertSessionHasErrors('quantity');
        $this->assertSame(23, $p->fresh()->stock_quantity);
    }

    public function test_stock_is_not_editable_through_the_product_form(): void
    {
        $owner = $this->owner();
        $p = $this->cola($owner, 10);

        $this->actingAs($owner, 'owner')->put("/products/{$p->id}", [
            'name' => 'Coca Cola', 'type' => 'product', 'price' => 25, 'purchase_price' => 15, 'is_active' => 1,
            'track_stock' => 1, 'stock_quantity' => 999, 'low_stock_threshold' => 5,
        ])->assertRedirect('/products');

        $this->assertSame(10, $p->fresh()->stock_quantity);
    }

    // ------------------------------------------------------------ notifications

    public function test_low_and_out_of_stock_alerts_fire_once_per_cycle(): void
    {
        $owner = $this->owner();
        $p = $this->cola($owner, 6, 5);
        $booking = $this->booking($owner);
        $alerts = fn (string $type) => Notification::where('owner_id', $owner->id)->where('type', $type)->count();

        $this->sell($owner, $booking, $p, 1); // 6 → 5
        $this->assertSame(1, $alerts('stock_low'));
        $low = Notification::where('type', 'stock_low')->firstOrFail();
        $this->assertSame('/products/'.$p->id, $low->action_url);
        $this->assertStringContainsString('Coca Cola', $low->body);

        foreach ([1, 1, 1, 1] as $q) {
            $this->sell($owner, $booking, $p, $q); // 5 → 1
        }
        $this->assertSame(1, $alerts('stock_low'), 'No repeat while it stays low.');
        $this->assertSame(0, $alerts('stock_out'));

        $this->sell($owner, $booking, $p, 1); // → 0
        $this->assertSame(1, $alerts('stock_out'));

        // Restock above the threshold re-arms the cycle.
        $this->actingAs($owner, 'owner')->post("/products/{$p->id}/stock", ['direction' => 'restock', 'quantity' => 10]);
        $this->assertNull($p->fresh()->stock_alert);
        $this->sell($owner, $booking, $p, 5); // 10 → 5
        $this->assertSame(2, $alerts('stock_low'));
    }

    public function test_restock_that_stays_low_can_still_alert_out_again(): void
    {
        $owner = $this->owner();
        $p = $this->cola($owner, 1, 5);
        $booking = $this->booking($owner);

        $this->sell($owner, $booking, $p, 1); // → 0 (out)
        $this->actingAs($owner, 'owner')->post("/products/{$p->id}/stock", ['direction' => 'restock', 'quantity' => 2]); // 2, still low
        $this->sell($owner, $booking, $p, 2); // → 0 again

        $this->assertSame(2, Notification::where('owner_id', $owner->id)->where('type', 'stock_out')->count());
    }

    // --------------------------------------------------------- historical profit

    public function test_sale_keeps_its_cost_snapshot_when_the_purchase_price_changes(): void
    {
        $owner = $this->owner();
        $p = $this->cola($owner, 20);
        $this->sell($owner, $this->booking($owner), $p, 2)->assertOk();

        $this->actingAs($owner, 'owner')->put("/products/{$p->id}", [
            'name' => 'Coca Cola', 'type' => 'product', 'price' => 25, 'purchase_price' => 18, 'is_active' => 1, 'track_stock' => 1,
        ]);

        $item = SaleItem::firstOrFail();
        $this->assertSame('15.00', (string) $item->unit_cost);
        $this->assertSame(20.0, $item->lineProfit(), '2 × (25 − 15), not 2 × (25 − 18).');
        $this->assertSame(14.0, $p->fresh()->profitPerUnit() * 2, 'Only new sales use the new cost.');

        $this->actingAs($owner, 'owner')->get("/products/{$p->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Products/Show')->where('sold.profit', 20));
    }

    // ----------------------------------------------------- tenancy & gating

    public function test_owners_cannot_see_adjust_or_sell_each_others_products(): void
    {
        $a = $this->owner();
        $b = $this->owner();
        $p = $this->cola($a, 10);

        $this->actingAs($b, 'owner')->get("/products/{$p->id}")->assertNotFound();
        $this->actingAs($b, 'owner')->post("/products/{$p->id}/stock", ['direction' => 'restock', 'quantity' => 5])->assertNotFound();
        $this->sell($b, $this->booking($b), $p, 1)->assertNotFound();

        $this->assertSame(10, $p->fresh()->stock_quantity);
        $this->assertSame(0, InventoryMovement::where('owner_id', $b->id)->count());
    }

    public function test_staff_without_manage_permission_cannot_adjust_stock(): void
    {
        $owner = $this->owner();
        $p = $this->cola($owner, 10);
        $role = Role::whereNull('owner_id')->where('key', 'staff')->firstOrFail();
        $staff = Staff::create(['owner_id' => $owner->id, 'role_id' => $role->id, 'name' => 'S', 'email' => 's'.uniqid().'@t.local', 'password' => 'secret123', 'is_active' => true]);
        $staff->syncPermissionsFromRole();
        // Can view products, but not manage them (whatever the role bundle currently grants).
        $staff->permissions()->detach(Permission::where('key', 'products.manage')->value('id'));
        $this->assertTrue($staff->hasPermission('products.view'));
        // cola() created the product as the owner; drop that session so only the staff guard is signed in.
        auth('owner')->logout();

        $this->actingAs($staff, 'staff')->get("/products/{$p->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Products/Show')->where('canManage', false));
        $this->actingAs($staff, 'staff')->post("/products/{$p->id}/stock", ['direction' => 'restock', 'quantity' => 5]);
        $this->assertSame(10, $p->fresh()->stock_quantity);
    }

    public function test_inventory_pages_need_the_sales_feature(): void
    {
        $owner = $this->owner();
        $p = $this->cola($owner, 10);
        $owner->disableFeature('sales');

        $this->actingAs($owner, 'owner')->get("/products/{$p->id}")->assertRedirect('/dashboard');
        $this->actingAs($owner, 'owner')->post("/products/{$p->id}/stock", ['direction' => 'restock', 'quantity' => 5])->assertRedirect('/dashboard');
        $this->assertSame(10, $p->fresh()->stock_quantity);
    }

    public function test_product_list_filters_by_stock_status(): void
    {
        $owner = $this->owner();
        $low = $this->cola($owner, 3, 5, ['name' => 'Low Cola']);
        $gone = $this->cola($owner, 0, 5, ['name' => 'Gone Cola']);
        $plenty = $this->cola($owner, 50, 5, ['name' => 'Plenty Cola']);
        // Product cards only (the names also appear in the bell's stock notifications).
        $ids = fn ($res) => collect($res->inertiaProps('products.data'))->pluck('id')->all();

        $res = $this->actingAs($owner, 'owner')->get('/products?stock=low')->assertOk();
        $this->assertSame([$low->id], $ids($res));
        $res = $this->actingAs($owner, 'owner')->get('/products?stock=out')->assertOk();
        $this->assertSame([$gone->id], $ids($res));
    }

    public function test_inventory_service_reports_available_units(): void
    {
        $owner = $this->owner();
        $p = $this->cola($owner, 4);
        $this->actingAs($owner, 'owner');

        $this->expectException(InsufficientStockException::class);
        app(InventoryService::class)->adjustDown($p, 5, 'lost');
    }
}
