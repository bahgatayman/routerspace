<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Room;
use App\Models\Staff;
use App\Models\Workspace;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Super Admin → Coupons: server-side admin-only access, cross-business listing,
 * and create/edit that reuses the owner rules (CouponForm) against the chosen
 * business only. Plus the redesigned coupon card on the owner screen.
 */
class AdminCouponManagementTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->admin = Admin::create(['name' => 'Root Admin', 'email' => 'root@t.local', 'password' => bcrypt('secret123')]);
    }

    private function as($user, string $guard): static
    {
        auth('owner')->logout();
        auth('staff')->logout();
        auth('admin')->logout();

        return $this->actingAs($user, $guard);
    }

    private function owner(string $name): Owner
    {
        $plan = Plan::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['workspace', 'booking', 'sales'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);
        $owner = Owner::create(['name' => $name, 'email' => strtolower($name).uniqid().'@t.local', 'password' => 'secret123', 'business_name' => $name,
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth()]);
        foreach ($plan->features as $key) {
            $owner->enableFeature($key);
        }

        return $owner;
    }

    private function room(Owner $owner, string $name = 'Room A'): Room
    {
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => $name.' WS']);

        return Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => $name, 'type' => 'meeting', 'capacity' => 4, 'price_per_hour' => 100]);
    }

    private function coupon(Owner $owner, string $code, array $attrs = []): Coupon
    {
        return Coupon::create(array_merge(['owner_id' => $owner->id, 'code' => $code, 'discount_type' => 'percentage',
            'discount_value' => 20, 'applies_to' => 'both', 'is_active' => true], $attrs));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['code' => 'summer20', 'discount_type' => 'percentage', 'discount_value' => '20', 'applies_to' => 'both',
            'room_scope' => 'all', 'product_scope' => 'all', 'is_active' => '1'], $overrides);
    }

    // ------------------------------------------------------------------ access

    public function test_only_a_super_admin_can_reach_any_coupon_management_route(): void
    {
        $alpha = $this->owner('Alpha');
        $coupon = $this->coupon($alpha, 'ALPHA10');
        $manager = Staff::create(['owner_id' => $alpha->id, 'role_id' => Role::whereNull('owner_id')->where('key', 'manager')->value('id'),
            'name' => 'Mgr', 'email' => 'm'.uniqid().'@t.local', 'password' => 'secret123', 'is_active' => true]);

        $requests = [
            ['get', '/admin/coupons'], ['get', '/admin/coupons/create'], ['get', "/admin/coupons/{$coupon->id}"],
            ['get', "/admin/coupons/{$coupon->id}/edit"], ['post', '/admin/coupons'], ['put', "/admin/coupons/{$coupon->id}"],
            ['post', "/admin/coupons/{$coupon->id}/toggle"], ['delete', "/admin/coupons/{$coupon->id}"],
        ];

        foreach ([null, [$alpha, 'owner'], [$manager, 'staff']] as $who) {
            foreach ($requests as [$method, $url]) {
                $who ? $this->as($who[0], $who[1]) : auth('admin')->logout();
                $response = $this->{$method}($url, $method === 'get' ? [] : $this->payload(['owner_id' => $alpha->id]));
                $response->assertRedirect(route('login'));
            }
        }

        $coupon->refresh();
        $this->assertTrue($coupon->is_active);                       // toggle never ran
        $this->assertSame(1, Coupon::count());                       // nothing created or deleted
        $this->assertSame(0, AdminAuditLog::count());
    }

    public function test_admin_navigation_links_to_coupons(): void
    {
        $this->as($this->admin, 'admin')->get('/admin/coupons')->assertOk()
            ->assertSee('href="'.route('admin.coupons.index').'"', false);
    }

    // ------------------------------------------------------------------ list + detail

    public function test_index_lists_every_business_with_search_business_and_status_filters(): void
    {
        $alpha = $this->owner('Alpha');
        $bravo = $this->owner('Bravo');
        $this->coupon($alpha, 'ALPHASALE');
        $this->coupon($bravo, 'BRAVOSALE', ['discount_type' => 'fixed', 'discount_value' => 50]);
        $this->coupon($bravo, 'BRAVOOFF', ['is_active' => false]);

        $this->as($this->admin, 'admin')->get('/admin/coupons')->assertOk()
            ->assertSee('ALPHASALE')->assertSee('BRAVOSALE')->assertSee('Alpha')->assertSee('Bravo')->assertSee('20%');

        $this->get('/admin/coupons?q=alpha')->assertOk()->assertSee('ALPHASALE')->assertDontSee('BRAVOSALE');
        $this->get('/admin/coupons?owner='.$bravo->id)->assertOk()->assertSee('BRAVOSALE')->assertDontSee('ALPHASALE');
        $this->get('/admin/coupons?status=inactive')->assertOk()->assertSee('BRAVOOFF')->assertDontSee('BRAVOSALE');
        // Wildcards are literal, not "match everything".
        $this->get('/admin/coupons?q=%25')->assertOk()->assertDontSee('ALPHASALE');
    }

    public function test_show_has_the_card_targets_and_usage_history(): void
    {
        $alpha = $this->owner('Alpha');
        $room = $this->room($alpha, 'Board Room');
        $coupon = $this->coupon($alpha, 'BOARD15', ['applies_to' => 'rooms', 'usage_limit' => 10, 'minimum_spend' => 200]);
        $coupon->rooms()->sync([$room->id]);
        CouponUsage::create(['coupon_id' => $coupon->id, 'owner_id' => $alpha->id, 'original_amount' => 300, 'discount_amount' => 45,
            'final_amount' => 255, 'room_discount' => 45, 'used_at' => now()]);

        $this->as($this->admin, 'admin')->get("/admin/coupons/{$coupon->id}")->assertOk()
            ->assertSee('ls-coupon', false)->assertSee('data-coupon-copy="BOARD15"', false)
            ->assertSee('Board Room')->assertSee('1 / 10 used')->assertSee('Alpha');

        $this->get('/admin/coupons/999999')->assertNotFound();
    }

    // ------------------------------------------------------------------ create / edit

    public function test_create_asks_for_the_business_then_offers_only_its_rooms(): void
    {
        $alpha = $this->owner('Alpha');
        $bravo = $this->owner('Bravo');
        $this->room($alpha, 'Alpha Hall');
        $this->room($bravo, 'Bravo Hall');

        $this->as($this->admin, 'admin')->get('/admin/coupons/create')->assertOk()
            ->assertSee('name="owner"', false)->assertDontSee('name="code"', false);

        $this->get('/admin/coupons/create?owner='.$alpha->id)->assertOk()
            ->assertSee('name="code"', false)->assertSee('Alpha Hall')->assertDontSee('Bravo Hall');
    }

    public function test_store_creates_for_the_chosen_business_with_the_owner_rules_and_audits(): void
    {
        $alpha = $this->owner('Alpha');
        $bravo = $this->owner('Bravo');
        $alphaRoom = $this->room($alpha);
        $bravoRoom = $this->room($bravo);
        $this->coupon($alpha, 'TAKEN');
        $this->as($this->admin, 'admin');

        // Another business's room is rejected (scoped to the chosen business).
        $this->post('/admin/coupons', $this->payload(['owner_id' => $alpha->id, 'room_scope' => 'specific', 'room_ids' => [$bravoRoom->id]]))
            ->assertSessionHasErrors('room_ids.0');
        // Code unique per business; same code elsewhere is fine.
        $this->post('/admin/coupons', $this->payload(['owner_id' => $alpha->id, 'code' => 'taken']))->assertSessionHasErrors('code');
        // Discount and date rules.
        $this->post('/admin/coupons', $this->payload(['owner_id' => $alpha->id, 'discount_value' => '150']))->assertSessionHasErrors('discount_value');
        $this->post('/admin/coupons', $this->payload(['owner_id' => $alpha->id, 'starts_at' => '2026-12-10', 'expires_at' => '2026-12-01']))->assertSessionHasErrors('expires_at');
        $this->post('/admin/coupons', $this->payload(['owner_id' => 999999]))->assertSessionHasErrors('owner_id');
        $this->assertSame(1, Coupon::count());

        $this->post('/admin/coupons', $this->payload(['owner_id' => $bravo->id, 'code' => 'taken']))->assertRedirect();
        $this->post('/admin/coupons', $this->payload(['owner_id' => $alpha->id, 'room_scope' => 'specific', 'room_ids' => [$alphaRoom->id], 'usage_limit' => '5']))
            ->assertRedirect();

        $created = Coupon::where('code', 'SUMMER20')->firstOrFail();
        $this->assertSame($alpha->id, $created->owner_id);
        $this->assertSame([$alphaRoom->id], $created->rooms()->pluck('rooms.id')->all());
        $this->assertSame(5, $created->usage_limit);
        $this->assertTrue(AdminAuditLog::where('action', 'coupon.created')->where('owner_id', $alpha->id)->exists());
    }

    public function test_update_keeps_the_business_and_validates_against_it(): void
    {
        $alpha = $this->owner('Alpha');
        $bravo = $this->owner('Bravo');
        $bravoRoom = $this->room($bravo);
        $coupon = $this->coupon($alpha, 'EDITME');
        $this->as($this->admin, 'admin');

        $this->put("/admin/coupons/{$coupon->id}", $this->payload(['code' => 'editme', 'room_scope' => 'specific', 'room_ids' => [$bravoRoom->id]]))
            ->assertSessionHasErrors('room_ids.0');

        $this->put("/admin/coupons/{$coupon->id}", $this->payload(['code' => 'editme', 'discount_value' => '35', 'owner_id' => $bravo->id]))
            ->assertRedirect(route('admin.coupons.show', $coupon->id));

        $coupon->refresh();
        $this->assertSame($alpha->id, $coupon->owner_id);            // owner_id in the request is ignored
        $this->assertSame('35.00', (string) $coupon->discount_value);
        $this->assertTrue(AdminAuditLog::where('action', 'coupon.updated')->exists());
    }

    public function test_toggle_and_delete_follow_the_existing_rules(): void
    {
        $alpha = $this->owner('Alpha');
        $used = $this->coupon($alpha, 'USED');
        $unused = $this->coupon($alpha, 'UNUSED');
        CouponUsage::create(['coupon_id' => $used->id, 'owner_id' => $alpha->id, 'original_amount' => 100, 'discount_amount' => 20,
            'final_amount' => 80, 'used_at' => now()]);
        $this->as($this->admin, 'admin');

        $this->post("/admin/coupons/{$used->id}/toggle")->assertRedirect();
        $this->assertFalse($used->fresh()->is_active);

        $this->delete("/admin/coupons/{$used->id}")->assertRedirect()->assertSessionHas('error', __('app.coupons.delete_blocked'));
        $this->assertNotNull($used->fresh());

        $this->delete("/admin/coupons/{$unused->id}")->assertRedirect(route('admin.coupons.index'));
        $this->assertNull($unused->fresh());
        $this->assertSame(['coupon.deleted', 'coupon.toggled'], AdminAuditLog::orderBy('action')->pluck('action')->all());
    }

    // ------------------------------------------------------------------ owner card

    public function test_owner_coupon_page_shows_coupon_cards_for_own_coupons_only(): void
    {
        $alpha = $this->owner('Alpha');
        $bravo = $this->owner('Bravo');
        $this->coupon($alpha, 'MINE20', ['minimum_spend' => 100, 'per_customer_limit' => 1]);
        $this->coupon($bravo, 'THEIRS20');

        $this->as($alpha, 'owner')->get('/coupons')->assertOk()
            ->assertSee('class="ls-coupon ', false)
            ->assertSee('data-coupon-copy="MINE20"', false)
            ->assertSee(__('app.coupons.card.min_spend'))
            ->assertDontSee('THEIRS20');
    }
}
