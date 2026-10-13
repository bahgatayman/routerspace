<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Plan;
use App\Models\Product;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Add product" opens a pop-up on the Products page instead of /products/create. */
class AddProductPopupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    private function owner(int $maxProducts = 0): Owner
    {
        $plan = Plan::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'max_members' => 50, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['sales'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => $maxProducts]);
        $owner = Owner::create(['name' => 'O', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Space',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth()]);
        $owner->enableFeature('sales');

        return $owner->fresh();
    }

    public function test_products_page_renders_the_add_product_popup(): void
    {
        $html = $this->actingAs($this->owner(), 'owner')->get('/products')->assertOk()->getContent();

        $this->assertStringContainsString('id="add-product-modal"', $html);
        $this->assertStringContainsString('data-ls-open="add-product-modal"', $html);
        $this->assertStringContainsString('id="add-product-form"', $html);
        $this->assertStringNotContainsString("LS.open('add-product-modal')", $html); // closed by default
    }

    public function test_saving_from_the_popup_returns_to_the_list(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'owner')->from('/products')
            ->post('/products', ['name' => 'Cola', 'type' => 'product', 'price' => '15', 'is_active' => '1'])
            ->assertRedirect('/products')->assertSessionHas('success');

        $this->assertSame(1, Product::where('owner_id', $owner->id)->where('name', 'Cola')->count());
    }

    public function test_validation_errors_reopen_the_popup_with_typed_values(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'owner')->from('/products')
            ->post('/products', ['name' => 'Tea', 'type' => 'product', 'price' => ''])
            ->assertRedirect('/products')->assertSessionHasErrors('price');

        $html = $this->actingAs($owner, 'owner')->withSession(['_old_input' => ['name' => 'Tea']])
            ->get('/products')->getContent();
        $this->assertStringContainsString('value="Tea"', $html);
    }

    public function test_catalog_search_matches_name_or_sku_within_the_business_only(): void
    {
        $a = $this->owner();
        $b = $this->owner();
        Product::create(['owner_id' => $a->id, 'name' => 'Espresso', 'sku' => 'ESP-01', 'type' => 'product', 'price' => 35, 'is_active' => true]);
        Product::create(['owner_id' => $a->id, 'name' => 'Mineral Water', 'sku' => 'WAT-9', 'type' => 'product', 'price' => 10, 'is_active' => true]);
        Product::create(['owner_id' => $b->id, 'name' => 'Espresso Bravo', 'type' => 'product', 'price' => 30, 'is_active' => true]);

        $this->actingAs($a, 'owner')->get('/products?q=esp')->assertOk()
            ->assertSee('Espresso')->assertDontSee('Mineral Water')->assertDontSee('Espresso Bravo');
        $this->actingAs($a, 'owner')->get('/products?q=WAT-9')->assertOk()
            ->assertSee('Mineral Water')->assertDontSee('ESP-01');
        // LIKE wildcards are literal, so "%" doesn't list everything.
        $this->actingAs($a, 'owner')->get('/products?q=%25')->assertOk()
            ->assertDontSee('Mineral Water')->assertSee(__('app.inventory.no_results', ['term' => '%']), false);
    }

    public function test_cards_show_margin_stock_state_and_a_more_actions_menu(): void
    {
        $owner = $this->owner();
        $p = Product::create(['owner_id' => $owner->id, 'name' => 'Cola', 'type' => 'product', 'price' => 15, 'purchase_price' => 9,
            'track_stock' => true, 'stock_quantity' => 0, 'is_active' => true]);

        $html = $this->actingAs($owner, 'owner')->get('/products')->assertOk()->getContent();

        $this->assertStringContainsString('40% '.__('app.inventory.margin_short'), $html);
        $this->assertStringContainsString(__('app.inventory.status.out'), $html);
        $this->assertStringContainsString('data-ls-menu="product-'.$p->id.'-menu"', $html);
        $this->assertStringContainsString(route('products.destroy', $p), $html);
        $this->assertStringContainsString(route('products.toggle', $p), $html);
    }

    public function test_new_product_form_starts_with_empty_price_cost_and_stock(): void
    {
        $owner = $this->owner();
        $html = $this->actingAs($owner, 'owner')->get('/products')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="purchase_price"[^>]*value=""/', $html);
        $this->assertMatchesRegularExpression('/name="stock_quantity"[^>]*value=""/', $html);
        $this->assertMatchesRegularExpression('/name="price"[^>]*value=""/', $html);

        // Left empty, cost saves as 0 and a tracked product opens with 0 units.
        $this->actingAs($owner, 'owner')->from('/products')->post('/products', [
            'name' => 'Tea', 'type' => 'product', 'price' => '10', 'purchase_price' => '', 'track_stock' => '1', 'stock_quantity' => '', 'is_active' => '1',
        ])->assertRedirect('/products')->assertSessionHasNoErrors();

        $tea = Product::where('owner_id', $owner->id)->where('name', 'Tea')->firstOrFail();
        $this->assertSame(0.0, (float) $tea->purchase_price);
        $this->assertSame(0, (int) $tea->stock_quantity);
    }

    public function test_full_create_page_still_works_as_fallback(): void
    {
        $this->actingAs($this->owner(), 'owner')->get('/products/create')->assertOk();
    }
}
