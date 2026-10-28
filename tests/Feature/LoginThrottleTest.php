<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Staff;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * POST /login brute-force guard (LoginController): 5 failed attempts per
 * email + IP and 30 per IP within 60 s, counted on wrong credentials only,
 * cleared for the email on success. All three account types still sign in.
 */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        Plan::create(['name' => 'Basic', 'slug' => 'basic', 'max_members' => 100, 'price_per_month' => 0, 'is_active' => true, 'sort_order' => 1]);
    }

    private function owner(string $email = 'owner@t.local'): Owner
    {
        return Owner::create([
            'name' => 'Tenant', 'email' => $email, 'password' => 'secret123', 'business_name' => 'Space',
            'plan_id' => Plan::first()->id, 'is_active' => true,
            'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
        ]);
    }

    private function staff(Owner $owner, bool $active = true): Staff
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $role = Role::whereNull('owner_id')->firstOrFail();
        $staff = Staff::create(['owner_id' => $owner->id, 'role_id' => $role->id, 'name' => 'S', 'email' => 'staff@t.local', 'password' => 'secret123', 'is_active' => $active]);
        $staff->syncPermissionsFromRole();

        return $staff;
    }

    private function failLogins(string $email, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->post('/login', ['email' => $email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        }
    }

    public function test_owner_staff_and_admin_still_sign_in(): void
    {
        $owner = $this->owner();
        $this->staff($owner);
        Admin::create(['name' => 'Op', 'email' => 'admin@t.local', 'password' => Hash::make('secret123')]);

        $this->post('/login', ['email' => 'admin@t.local', 'password' => 'secret123'])->assertRedirect('/admin/dashboard');
        $this->post('/logout');
        $this->post('/login', ['email' => 'owner@t.local', 'password' => 'secret123', 'remember' => 'on'])->assertRedirect('/dashboard');
        $this->assertTrue(auth('owner')->check());
        $this->post('/logout');
        $this->post('/login', ['email' => 'staff@t.local', 'password' => 'secret123'])->assertRedirect('/dashboard');
        $this->assertTrue(auth('staff')->check());
    }

    public function test_five_failures_lock_the_email_even_for_the_right_password(): void
    {
        $this->owner();
        $this->failLogins('owner@t.local', 5);

        $response = $this->post('/login', ['email' => 'owner@t.local', 'password' => 'secret123']);

        $response->assertRedirect()->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many sign-in attempts', session('errors')->first('email'));
        $this->assertFalse(auth('owner')->check(), 'No guard is tried while locked out.');
    }

    public function test_the_lockout_expires(): void
    {
        $this->owner();
        $this->failLogins('owner@t.local', 5);

        $this->travel(61)->seconds();

        $this->post('/login', ['email' => 'owner@t.local', 'password' => 'secret123'])->assertRedirect('/dashboard');
    }

    public function test_the_email_is_normalised_for_the_count(): void
    {
        $this->owner();
        $this->failLogins('Owner@T.local', 3);
        $this->failLogins('owner@t.local', 2);

        $this->post('/login', ['email' => 'owner@t.local', 'password' => 'secret123'])->assertSessionHasErrors('email');
        $this->assertFalse(auth('owner')->check());
    }

    public function test_success_clears_the_email_counter(): void
    {
        $this->owner();
        $this->failLogins('owner@t.local', 4);
        $this->post('/login', ['email' => 'owner@t.local', 'password' => 'secret123'])->assertRedirect('/dashboard');
        $this->post('/logout');

        $this->failLogins('owner@t.local', 4);
        $this->post('/login', ['email' => 'owner@t.local', 'password' => 'secret123'])->assertRedirect('/dashboard');
    }

    public function test_another_email_from_the_same_ip_is_not_locked_by_one_users_failures(): void
    {
        $this->owner();
        $this->owner('second@t.local');
        $this->failLogins('owner@t.local', 5);

        $this->post('/login', ['email' => 'second@t.local', 'password' => 'secret123'])->assertRedirect('/dashboard');
    }

    public function test_thirty_failures_from_one_ip_lock_that_ip(): void
    {
        $this->owner('victim@t.local');
        for ($i = 0; $i < 30; $i++) {
            $this->post('/login', ['email' => "guess{$i}@t.local", 'password' => 'x'])->assertSessionHasErrors('email');
        }

        $this->post('/login', ['email' => 'victim@t.local', 'password' => 'secret123'])->assertSessionHasErrors('email');
        $this->assertFalse(auth('owner')->check());

        // A different IP is unaffected.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
            ->post('/login', ['email' => 'victim@t.local', 'password' => 'secret123'])->assertRedirect('/dashboard');
    }

    public function test_validation_errors_and_the_login_page_are_not_counted(): void
    {
        $this->owner();
        for ($i = 0; $i < 10; $i++) {
            $this->post('/login', ['email' => 'owner@t.local'])->assertSessionHasErrors('password');
            $this->get('/login')->assertOk();
        }

        $this->post('/login', ['email' => 'owner@t.local', 'password' => 'secret123'])->assertRedirect('/dashboard');
    }

    public function test_disabled_staff_keeps_its_message_and_is_not_counted_as_a_guess(): void
    {
        $this->staff($this->owner(), active: false);

        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', ['email' => 'staff@t.local', 'password' => 'secret123'])
                ->assertSessionHasErrors(['email' => 'Your account has been disabled. Contact your manager.']);
        }
        $this->assertFalse(auth('staff')->check());
    }

    public function test_the_lockout_message_is_translated(): void
    {
        $this->freezeTime();
        $this->owner();
        $this->failLogins('owner@t.local', 5);

        $this->withSession(['locale' => 'ar'])->post('/login', ['email' => 'owner@t.local', 'password' => 'secret123'])
            ->assertSessionHasErrors(['email' => __('app.auth.throttle', ['seconds' => 60], 'ar')]);
    }
}
