<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Room;
use App\Models\Sale;
use App\Models\Staff;
use App\Models\Workspace;
use App\Services\AnalyticsPeriod;
use App\Services\RevenueAnalyticsService;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
    }

    private function plan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Test', 'slug' => 'test-'.uniqid(), 'max_members' => 100,
            'price_per_month' => 0, 'is_active' => true, 'sort_order' => 1,
            'features' => ['workspace', 'booking', 'sales'],
            'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ], $overrides));
    }

    private function owner(Plan $plan): Owner
    {
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

    private function product(Owner $owner, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'owner_id' => $owner->id, 'name' => 'Coffee', 'type' => 'product',
            'price' => 25, 'is_active' => true,
        ], $overrides));
    }

    private function bookingFor(Owner $owner, array $overrides = []): Booking
    {
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Main']);
        $room = Room::create([
            'owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'R1',
            'type' => $overrides['room_type'] ?? 'meeting', 'capacity' => 4, 'price_per_hour' => 50,
        ]);
        $user = HotspotUser::create([
            'owner_id' => $owner->id, 'name' => 'Cust', 'phone' => '010'.rand(10000000, 99999999),
            'password' => 'pass1234',
        ]);

        return Booking::create(array_merge([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $user->id,
            'booking_date' => today()->toDateString(), 'start_time' => '10:00', 'end_time' => '12:00',
            'price_per_hour' => 50, 'total_hours' => 2, 'total_price' => 100, 'status' => 'confirmed',
        ], collect($overrides)->except('room_type')->all()));
    }

    private function staffWithRole(Owner $owner, string $roleKey): Staff
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $role = Role::whereNull('owner_id')->where('key', $roleKey)->firstOrFail();
        $staff = Staff::create([
            'owner_id' => $owner->id, 'role_id' => $role->id,
            'name' => 'Staffer', 'email' => 's'.uniqid().'@t.local', 'password' => 'secret123', 'is_active' => true,
        ]);
        $staff->syncPermissionsFromRole();

        return $staff;
    }

    public function test_owner_can_create_a_product(): void
    {
        $owner = $this->owner($this->plan());

        $this->actingAs($owner, 'owner')
            ->post('/products', ['name' => 'Latte', 'type' => 'service', 'price' => 30, 'is_active' => 1])
            ->assertRedirect('/products');

        $product = Product::where('owner_id', $owner->id)->firstOrFail();
        $this->assertSame('Latte', $product->name);
        $this->assertSame('service', $product->type);
        $this->assertEquals(30, (float) $product->price);
    }

    public function test_attaching_a_product_snapshots_name_and_price(): void
    {
        $owner = $this->owner($this->plan());
        $booking = $this->bookingFor($owner);
        $product = $this->product($owner, ['name' => 'Coffee', 'price' => 25]);

        $this->actingAs($owner, 'owner')
            ->post("/bookings/{$booking->id}/items", ['product_id' => $product->id, 'quantity' => 2])
            ->assertRedirect();

        $sale = Sale::where('booking_id', $booking->id)->with('items')->firstOrFail();
        $this->assertEquals(50, (float) $sale->total); // 25 * 2
        $this->assertEquals(50, (float) $sale->subtotal);

        $item = $sale->items->first();
        $this->assertSame('Coffee', $item->name);
        $this->assertEquals(25, (float) $item->unit_price);
        $this->assertEquals(50, (float) $item->line_total);

        // Changing the catalog price must NOT rewrite the snapshotted line item.
        $product->update(['price' => 99, 'name' => 'Renamed']);
        $item->refresh();
        $this->assertSame('Coffee', $item->name);
        $this->assertEquals(25, (float) $item->unit_price);
    }

    public function test_room_total_is_unchanged_and_grand_total_is_additive(): void
    {
        $owner = $this->owner($this->plan());
        $booking = $this->bookingFor($owner);
        $product = $this->product($owner, ['price' => 25]);

        $this->actingAs($owner, 'owner')
            ->post("/bookings/{$booking->id}/items", ['product_id' => $product->id, 'quantity' => 2]);

        $booking->refresh();
        // Room charge is untouched — sales are a separate stream (no double-count).
        $this->assertEquals(100, (float) $booking->total_price);
        $this->assertEquals(150, $booking->grandTotal()); // 100 room + 50 items
    }

    public function test_removing_an_item_recalculates_the_total(): void
    {
        $owner = $this->owner($this->plan());
        $booking = $this->bookingFor($owner);
        $product = $this->product($owner, ['price' => 25]);

        $this->actingAs($owner, 'owner')
            ->post("/bookings/{$booking->id}/items", ['product_id' => $product->id, 'quantity' => 2]);

        $sale = Sale::where('booking_id', $booking->id)->with('items')->firstOrFail();
        $item = $sale->items->first();

        $this->actingAs($owner, 'owner')
            ->delete("/bookings/{$booking->id}/items/{$item->id}")
            ->assertRedirect();

        $this->assertEquals(0, (float) $sale->fresh()->total);
        $this->assertSame(0, $sale->items()->count());
    }

    public function test_cross_tenant_product_cannot_be_attached(): void
    {
        $owner = $this->owner($this->plan());
        $intruderProduct = $this->product($this->owner($this->plan()), ['name' => 'Foreign']);
        $booking = $this->bookingFor($owner);

        $this->actingAs($owner, 'owner')
            ->post("/bookings/{$booking->id}/items", ['product_id' => $intruderProduct->id, 'quantity' => 1])
            ->assertNotFound();

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_max_products_limit_is_enforced(): void
    {
        $owner = $this->owner($this->plan(['max_products' => 1]));

        $this->actingAs($owner, 'owner')
            ->post('/products', ['name' => 'A', 'type' => 'product', 'price' => 1, 'is_active' => 1])
            ->assertRedirect('/products');
        $this->assertSame(1, $owner->products()->count());

        $this->actingAs($owner, 'owner')
            ->post('/products', ['name' => 'B', 'type' => 'product', 'price' => 1, 'is_active' => 1])
            ->assertSessionHas('error');
        $this->assertSame(1, $owner->products()->count());
    }

    public function test_zero_products_limit_means_unlimited(): void
    {
        $owner = $this->owner($this->plan(['max_products' => 0]));

        $this->assertTrue($owner->canAddMoreProducts());
        $this->assertNull($owner->remainingProductSlots());
    }

    public function test_owner_without_sales_feature_cannot_reach_catalog(): void
    {
        $owner = $this->owner($this->plan(['features' => ['workspace', 'booking']]));

        $this->actingAs($owner, 'owner')->get('/products')->assertRedirect('/dashboard');
    }

    // --- Quantity update ---

    public function test_updating_quantity_recalculates_line_total_and_sale_total(): void
    {
        $owner = $this->owner($this->plan());
        $booking = $this->bookingFor($owner);
        $product = $this->product($owner, ['price' => 25]);

        $this->actingAs($owner, 'owner')->post("/bookings/{$booking->id}/items", ['product_id' => $product->id, 'quantity' => 2]);
        $item = Sale::where('booking_id', $booking->id)->firstOrFail()->items->first();

        $this->actingAs($owner, 'owner')
            ->patch("/bookings/{$booking->id}/items/{$item->id}", ['quantity' => 5])
            ->assertRedirect();

        $item->refresh();
        $this->assertSame(5, $item->quantity);
        $this->assertEquals(125, (float) $item->line_total); // 25 * 5, never a client value
        $this->assertEquals(125, (float) $item->sale->fresh()->total);
    }

    public function test_updating_quantity_to_zero_behaves_like_remove(): void
    {
        $owner = $this->owner($this->plan());
        $booking = $this->bookingFor($owner);
        $product = $this->product($owner, ['price' => 25]);
        $this->actingAs($owner, 'owner')->post("/bookings/{$booking->id}/items", ['product_id' => $product->id, 'quantity' => 2]);
        $sale = Sale::where('booking_id', $booking->id)->firstOrFail();
        $item = $sale->items->first();

        $this->actingAs($owner, 'owner')->patch("/bookings/{$booking->id}/items/{$item->id}", ['quantity' => 0])->assertRedirect();

        $this->assertSame(0, $sale->items()->count());
        $this->assertEquals(0, (float) $sale->fresh()->total);
    }

    // --- Finalized-invoice guard regressions ---

    public function test_invoice_is_not_editable_for_a_completed_cancelled_or_no_show_exclusive_booking(): void
    {
        $owner = $this->owner($this->plan());

        foreach (['completed', 'cancelled', 'no_show'] as $status) {
            $booking = $this->bookingFor($owner, ['status' => $status]);
            $product = $this->product($owner);

            $this->actingAs($owner, 'owner')
                ->post("/bookings/{$booking->id}/items", ['product_id' => $product->id, 'quantity' => 1])
                ->assertSessionHas('error');

            $this->assertNull($booking->fresh()->sale, "status={$status} should not allow adding an item");
        }
    }

    public function test_invoice_is_editable_for_pending_confirmed_or_checked_in_exclusive_bookings(): void
    {
        $owner = $this->owner($this->plan());

        foreach (['pending', 'confirmed', 'checked_in'] as $status) {
            $booking = $this->bookingFor($owner, ['status' => $status]);
            $product = $this->product($owner);

            $this->actingAs($owner, 'owner')
                ->post("/bookings/{$booking->id}/items", ['product_id' => $product->id, 'quantity' => 1])
                ->assertSessionHasNoErrors();

            $this->assertNotNull($booking->fresh()->sale, "status={$status} should allow adding an item");
        }
    }

    public function test_invoice_is_not_editable_for_a_completed_shared_room_booking(): void
    {
        $owner = $this->owner($this->plan());
        $booking = $this->bookingFor($owner, ['room_type' => 'shared', 'status' => 'completed']);
        $product = $this->product($owner);

        $this->actingAs($owner, 'owner')
            ->post("/bookings/{$booking->id}/items", ['product_id' => $product->id, 'quantity' => 1])
            ->assertSessionHas('error');

        $this->assertNull($booking->fresh()->sale);
    }

    public function test_invoice_is_editable_for_a_checked_in_shared_room_booking(): void
    {
        $owner = $this->owner($this->plan());
        $booking = $this->bookingFor($owner, ['room_type' => 'shared', 'status' => 'checked_in']);
        $product = $this->product($owner);

        $this->actingAs($owner, 'owner')
            ->post("/bookings/{$booking->id}/items", ['product_id' => $product->id, 'quantity' => 1])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($booking->fresh()->sale);
    }

    public function test_staff_without_bookings_edit_permission_cannot_update_item_quantity(): void
    {
        $owner = $this->owner($this->plan());
        $booking = $this->bookingFor($owner);
        $product = $this->product($owner);
        $this->actingAs($owner, 'owner')->post("/bookings/{$booking->id}/items", ['product_id' => $product->id, 'quantity' => 2]);
        $item = Sale::where('booking_id', $booking->id)->firstOrFail()->items->first();

        $staff = $this->staffWithRole($owner, 'receptionist');
        $staff->permissions()->detach(Permission::where('key', 'bookings.edit')->value('id'));

        auth('owner')->logout();
        $this->actingAs($staff, 'staff')
            ->patch("/bookings/{$booking->id}/items/{$item->id}", ['quantity' => 5])
            ->assertSessionHas('permission_denied');

        $this->assertSame(2, $item->fresh()->quantity);
    }

    public function test_reducing_an_items_quantity_is_reflected_live_in_sale_revenue(): void
    {
        $owner = $this->owner($this->plan());
        // The booking itself can be any editable status — saleForBooking()
        // sets the Sale's own status to 'completed' immediately regardless,
        // which is what saleRevenue() actually sums.
        $booking = $this->bookingFor($owner, ['status' => 'confirmed']);
        $product = $this->product($owner, ['price' => 25]);
        $this->actingAs($owner, 'owner')->post("/bookings/{$booking->id}/items", ['product_id' => $product->id, 'quantity' => 4]);
        $item = Sale::where('booking_id', $booking->id)->firstOrFail()->items->first();

        $revenue = app(RevenueAnalyticsService::class);
        $period = AnalyticsPeriod::today();
        $this->assertSame(100.0, $revenue->saleRevenue($owner, $period));

        $this->actingAs($owner, 'owner')->patch("/bookings/{$booking->id}/items/{$item->id}", ['quantity' => 1]);

        $this->assertSame(25.0, $revenue->saleRevenue($owner->fresh(), $period));
    }

    public function test_cross_tenant_sale_item_cannot_have_its_quantity_updated(): void
    {
        $owner = $this->owner($this->plan());
        $intruder = $this->owner($this->plan());
        $booking = $this->bookingFor($owner);
        $product = $this->product($owner);
        $this->actingAs($owner, 'owner')->post("/bookings/{$booking->id}/items", ['product_id' => $product->id, 'quantity' => 2]);
        $item = Sale::where('booking_id', $booking->id)->firstOrFail()->items->first();

        $this->actingAs($intruder, 'owner')
            ->patch("/bookings/{$booking->id}/items/{$item->id}", ['quantity' => 5])
            ->assertNotFound();

        $this->assertSame(2, $item->fresh()->quantity);
    }
}
