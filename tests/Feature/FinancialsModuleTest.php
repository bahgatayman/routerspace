<?php

namespace Tests\Feature;

use App\Exports\FinancialsExport;
use App\Models\Booking;
use App\Models\HotspotUser;
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
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

/**
 * The Financials module's core invariant is "no double-counting": a closed
 * SharedSession + the completed Booking it auto-creates must appear as ONE
 * transaction row, and a Booking with an attached completed Sale must show
 * one combined total — never two rows for the same economic event. These
 * tests exercise that structurally (via the real HTTP surface), plus tenant
 * isolation and the new permission gate.
 */
class FinancialsModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    private function asGuard($user, string $guard): static
    {
        auth('owner')->logout();
        auth('staff')->logout();
        auth('admin')->logout();

        return $this->actingAs($user, $guard);
    }

    private function owner(array $features = ['workspace', 'booking', 'sales']): Owner
    {
        $plan = Plan::create([
            'name' => 'Test', 'slug' => 'test-'.uniqid(), 'max_members' => 100,
            'price_per_month' => 0, 'is_active' => true, 'sort_order' => 1,
            'features' => $features, 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ]);

        $owner = Owner::create([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123',
            'business_name' => 'Space', 'plan_id' => $plan->id, 'is_active' => true,
            'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
        ]);

        foreach ($features as $key) {
            $owner->enableFeature($key);
        }

        return $owner;
    }

    private function room(Owner $owner): Room
    {
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create([
            'owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Room A',
            'type' => 'meeting', 'capacity' => 4, 'price_per_hour' => 40,
        ]);
    }

    private function member(Owner $owner): HotspotUser
    {
        return HotspotUser::create([
            'owner_id' => $owner->id, 'name' => 'Member', 'phone' => '01'.rand(100000000, 999999999),
            'password' => 'x', 'status' => 'active',
        ]);
    }

    private function booking(Owner $owner, Room $room, HotspotUser $member, string $date, float $totalPrice, string $status = 'completed'): Booking
    {
        return Booking::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'party_size' => 1, 'booking_date' => $date, 'start_time' => '10:00', 'end_time' => '11:00',
            'price_per_hour' => $totalPrice, 'total_hours' => 1, 'total_price' => $totalPrice,
            'amount_paid' => $totalPrice, 'payment_status' => 'paid',
            'status' => $status,
        ]);
    }

    private function staff(Owner $owner, string $roleKey = 'receptionist'): Staff
    {
        $role = Role::whereNull('owner_id')->where('key', $roleKey)->firstOrFail();

        $staff = Staff::create([
            'owner_id' => $owner->id, 'role_id' => $role->id,
            'name' => 'Staffer', 'email' => 's'.uniqid().'@t.local',
            'password' => 'secret123', 'is_active' => true,
        ]);
        $staff->syncPermissionsFromRole();

        return $staff;
    }

    // --- No double-counting: closed SharedSession + its spawned Booking ---

    public function test_a_closed_shared_session_and_its_spawned_booking_appear_as_one_transaction_row(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);

        $booking = $this->booking($owner, $room, $member, today()->toDateString(), 30.0);

        SharedSession::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'session_date' => today()->toDateString(), 'start_time' => '10:00',
            'opened_at' => today()->setTime(10, 0), 'closed_at' => today()->setTime(10, 30),
            'total_minutes' => 30, 'total_price' => 30.0, 'status' => 'closed',
            'booking_id' => $booking->id,
        ]);

        // Any other bookings that day, so the assertion below is meaningful.
        $this->booking($owner, $room, $member, today()->toDateString(), 50.0);

        $response = $this->asGuard($owner, 'owner')->get('/financials/transactions?period=today');

        $response->assertOk();
        $this->assertSame(2, $response->inertiaProps('bookings.total'));
    }

    // --- No double-counting: Booking + attached completed Sale ---

    public function test_a_booking_with_an_attached_sale_shows_one_combined_grand_total(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->booking($owner, $room, $member, today()->toDateString(), 100.0);

        $sale = Sale::create([
            'owner_id' => $owner->id, 'booking_id' => $booking->id, 'hotspot_user_id' => $member->id,
            'status' => 'completed', 'subtotal' => 25, 'total' => 25, 'sold_at' => now(),
        ]);
        SaleItem::create([
            'sale_id' => $sale->id, 'name' => 'Coffee', 'unit_price' => 25, 'quantity' => 1, 'line_total' => 25,
        ]);

        $response = $this->asGuard($owner, 'owner')->get("/financials/transactions/{$booking->id}");

        $response->assertOk();
        // 100 room + 25 product, combined — one figure, not two rows
        $response->assertInertia(fn (Assert $page) => $page->component('Financials/TransactionShow')
            ->where('booking.grand_total', 125)
            ->has('booking.sale.items', 1));
        $this->assertSame(125.0, $booking->fresh()->grandTotal());
    }

    // --- Revenue math delegates entirely to RevenueAnalyticsService ---

    public function test_overview_revenue_matches_the_revenue_analytics_service(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $this->booking($owner, $room, $member, today()->toDateString(), 100.0, 'completed');
        $this->booking($owner, $room, $member, today()->toDateString(), 999.0, 'pending'); // must not count

        $response = $this->asGuard($owner, 'owner')->get('/financials?period=today');

        $response->assertOk();
        $this->assertEquals(100.0, $response->inertiaProps('bookingRevenue'));
    }

    // --- Tenant isolation ---

    public function test_staff_cannot_reach_another_owners_transaction_by_guessing_the_id(): void
    {
        $owner = $this->owner();
        $intruderOwner = $this->owner();
        $room = $this->room($intruderOwner);
        $member = $this->member($intruderOwner);
        $foreignBooking = $this->booking($intruderOwner, $room, $member, today()->toDateString(), 100.0);

        $staff = $this->staff($owner, 'manager');

        $this->asGuard($staff, 'staff')
            ->get("/financials/transactions/{$foreignBooking->id}")
            ->assertNotFound();
    }

    // --- Permission gating ---

    public function test_staff_without_financials_view_is_denied(): void
    {
        $owner = $this->owner();
        $staff = $this->staff($owner, 'receptionist'); // does not include financials.view

        $response = $this->asGuard($staff, 'staff')->get('/financials');

        $response->assertRedirect();
        $response->assertSessionHas('permission_denied');
    }

    public function test_manager_role_can_view_financials_by_default(): void
    {
        $owner = $this->owner();
        $staff = $this->staff($owner, 'manager'); // RoleSeeder grants financials.view/export

        $this->asGuard($staff, 'staff')->get('/financials')->assertOk();
    }

    public function test_export_requires_financials_export_permission(): void
    {
        Excel::fake();
        $owner = $this->owner();

        // A staff member with view-but-not-export access (receptionist +
        // financials.view only, no financials.export) must be blocked.
        $staff = $this->staff($owner, 'receptionist');
        $staff->permissions()->sync(
            Permission::where('key', 'financials.view')->pluck('id')
                ->mapWithKeys(fn ($id) => [$id => ['granted_at' => now(), 'granted_by_owner_id' => $owner->id]])
        );

        $this->asGuard($staff, 'staff')
            ->get('/financials/export')
            ->assertRedirect()
            ->assertSessionHas('permission_denied');
    }

    public function test_owner_can_export_financials_as_an_excel_workbook(): void
    {
        Excel::fake();
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $this->booking($owner, $room, $member, today()->toDateString(), 100.0);

        $this->asGuard($owner, 'owner')->get('/financials/export?period=today')->assertOk();

        Excel::matchByRegex();
        Excel::assertDownloaded('/financials-.*\.xlsx/', fn ($export) => $export instanceof FinancialsExport);
    }

    // --- Retired /sales routes redirect into Financials ---

    public function test_sales_index_redirects_to_financials_transactions(): void
    {
        $owner = $this->owner();

        $this->asGuard($owner, 'owner')->get('/sales')
            ->assertRedirect('/financials/transactions');
    }

    public function test_sales_show_redirects_to_the_owning_transaction(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $member = $this->member($owner);
        $booking = $this->booking($owner, $room, $member, today()->toDateString(), 100.0);
        $sale = Sale::create([
            'owner_id' => $owner->id, 'booking_id' => $booking->id, 'hotspot_user_id' => $member->id,
            'status' => 'completed', 'subtotal' => 10, 'total' => 10, 'sold_at' => now(),
        ]);

        $this->asGuard($owner, 'owner')->get("/sales/{$sale->id}")
            ->assertRedirect("/financials/transactions/{$booking->id}");
    }

    // --- Products catalog is unaffected by the module swap ---

    public function test_products_catalog_is_unchanged_and_unaffected(): void
    {
        $owner = $this->owner();
        Product::create(['owner_id' => $owner->id, 'name' => 'Coffee', 'type' => 'product', 'price' => 25, 'is_active' => true]);

        $this->asGuard($owner, 'owner')->get('/products')->assertOk();
    }
}
