<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Staff;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP-level coverage for the Expenses feature: CRUD, category safe-delete,
 * tenant isolation, permission gating, period filtering by expense_date, and
 * that the Financials page's new Net figure is correct and doesn't disturb
 * any existing Financials view data.
 */
class ExpensesModuleTest extends TestCase
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

    // --- CRUD ---

    public function test_owner_can_create_an_expense(): void
    {
        $owner = $this->owner();
        $category = ExpenseCategory::create(['owner_id' => $owner->id, 'name' => 'Electricity']);

        $response = $this->asGuard($owner, 'owner')->post('/expenses', [
            'amount' => 1500,
            'expense_category_id' => $category->id,
            'expense_date' => '2026-09-28',
            'note' => 'September electricity bill',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('expenses', [
            'owner_id' => $owner->id, 'amount' => 1500.00, 'note' => 'September electricity bill',
        ]);
    }

    public function test_owner_can_update_and_delete_an_expense(): void
    {
        $owner = $this->owner();
        $expense = Expense::create([
            'owner_id' => $owner->id, 'amount' => 100, 'expense_date' => '2026-09-01',
        ]);

        $this->asGuard($owner, 'owner')
            ->put("/expenses/{$expense->id}", ['amount' => 200, 'expense_date' => '2026-09-02'])
            ->assertRedirect();

        $this->assertSame(200.0, (float) $expense->fresh()->amount);

        $this->asGuard($owner, 'owner')->delete("/expenses/{$expense->id}")->assertRedirect();
        $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
    }

    // --- Category safe-delete ---

    public function test_deleting_a_category_in_use_is_blocked_with_a_count_message(): void
    {
        $owner = $this->owner();
        $category = ExpenseCategory::create(['owner_id' => $owner->id, 'name' => 'Rent']);
        Expense::create(['owner_id' => $owner->id, 'expense_category_id' => $category->id, 'amount' => 50, 'expense_date' => '2026-09-01']);

        $response = $this->asGuard($owner, 'owner')->delete("/expense-categories/{$category->id}");

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertDatabaseHas('expense_categories', ['id' => $category->id]);
    }

    public function test_category_can_be_deleted_once_no_expenses_reference_it(): void
    {
        $owner = $this->owner();
        $category = ExpenseCategory::create(['owner_id' => $owner->id, 'name' => 'Rent']);
        $expense = Expense::create(['owner_id' => $owner->id, 'expense_category_id' => $category->id, 'amount' => 50, 'expense_date' => '2026-09-01']);
        $expense->delete();

        $this->asGuard($owner, 'owner')->delete("/expense-categories/{$category->id}")->assertRedirect();

        $this->assertDatabaseMissing('expense_categories', ['id' => $category->id]);
    }

    // --- Tenant isolation ---

    public function test_owner_cannot_reach_another_owners_expense(): void
    {
        $owner = $this->owner();
        $intruderOwner = $this->owner();
        $foreignExpense = Expense::create(['owner_id' => $intruderOwner->id, 'amount' => 100, 'expense_date' => '2026-09-01']);

        $this->asGuard($owner, 'owner')->get("/expenses/{$foreignExpense->id}/edit")->assertNotFound();
        $this->asGuard($owner, 'owner')->delete("/expenses/{$foreignExpense->id}")->assertNotFound();
        $this->assertDatabaseHas('expenses', ['id' => $foreignExpense->id]);
    }

    public function test_owner_cannot_reach_another_owners_category(): void
    {
        $owner = $this->owner();
        $intruderOwner = $this->owner();
        $foreignCategory = ExpenseCategory::create(['owner_id' => $intruderOwner->id, 'name' => 'Rent']);

        $this->asGuard($owner, 'owner')->delete("/expense-categories/{$foreignCategory->id}")->assertNotFound();
        $this->assertDatabaseHas('expense_categories', ['id' => $foreignCategory->id]);
    }

    // --- Permission gating ---

    public function test_staff_without_expenses_view_is_denied(): void
    {
        $owner = $this->owner();
        $staff = $this->staff($owner, 'receptionist'); // does not include expenses.view

        $response = $this->asGuard($staff, 'staff')->get('/expenses');

        $response->assertRedirect();
        $response->assertSessionHas('permission_denied');
    }

    public function test_manager_role_can_manage_expenses_by_default(): void
    {
        $owner = $this->owner();
        $staff = $this->staff($owner, 'manager'); // RoleSeeder grants all expenses.* keys

        $this->asGuard($staff, 'staff')->get('/expenses')->assertOk();

        $this->asGuard($staff, 'staff')->post('/expenses', [
            'amount' => 10, 'expense_date' => '2026-09-01',
        ])->assertRedirect();
    }

    // --- Period filtering by expense_date, not created_at ---

    public function test_index_filters_by_expense_date_within_the_selected_period(): void
    {
        $owner = $this->owner();
        $inRange = Expense::create(['owner_id' => $owner->id, 'amount' => 100, 'expense_date' => '2026-08-15']);
        $outOfRange = Expense::create(['owner_id' => $owner->id, 'amount' => 50, 'expense_date' => '2026-07-01']);

        $response = $this->asGuard($owner, 'owner')
            ->get('/expenses?period=custom&start=2026-08-01&end=2026-08-31');

        $response->assertOk();
        $ids = collect($response->inertiaProps('expenses.data'))->pluck('id');
        $this->assertTrue($ids->contains($inRange->id));
        $this->assertFalse($ids->contains($outOfRange->id));
    }

    // --- Financials integration: Net = Revenue - Expenses, existing data untouched ---

    public function test_financials_overview_exposes_correct_net_without_disturbing_existing_data(): void
    {
        $owner = $this->owner();

        Sale::create([
            'owner_id' => $owner->id, 'status' => 'completed',
            'subtotal' => 25000, 'total' => 25000, 'sold_at' => today(),
        ]);
        Expense::create(['owner_id' => $owner->id, 'amount' => 7500, 'expense_date' => today()->toDateString()]);

        $response = $this->asGuard($owner, 'owner')->get('/financials?period=today');

        $response->assertOk();
        $this->assertEquals(25000.0, $response->inertiaProps('comparison.current'));
        $this->assertEquals(7500.0, $response->inertiaProps('totalExpenses'));
        $this->assertEquals(17500.0, $response->inertiaProps('netTotal'));
    }
}
