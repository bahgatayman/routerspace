<?php

namespace Tests\Feature;

use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Staff;
use App\Support\PhoneNumber;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Live "already registered" hint in the Add user pop-up: GET /users/phone-check. */
class MemberPhoneCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    private function owner(string $name): Owner
    {
        $plan = Plan::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'max_members' => 50, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['booking'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);
        $owner = Owner::create(['name' => $name, 'email' => strtolower($name).uniqid().'@t.local', 'password' => 'secret123', 'business_name' => $name,
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth()]);
        $owner->enableFeature('booking');

        return $owner->fresh();
    }

    private function member(Owner $owner, string $name, string $phone): HotspotUser
    {
        return HotspotUser::create(['owner_id' => $owner->id, 'name' => $name, 'phone' => $phone,
            'phone_normalized' => PhoneNumber::normalize($phone), 'password' => 'x']);
    }

    public function test_reports_an_existing_number_exactly_and_in_another_format(): void
    {
        $a = $this->owner('Alpha');
        $m = $this->member($a, 'Ahmed Hassan', '01012345678');

        $this->actingAs($a, 'owner')->getJson('/users/phone-check?phone=01012345678')
            ->assertOk()->assertJson(['exists' => true, 'name' => 'Ahmed Hassan', 'url' => "/users/{$m->id}"]);

        if (PhoneNumber::normalize('+201012345678') !== null) {
            $this->actingAs($a, 'owner')->getJson('/users/phone-check?phone='.urlencode('+201012345678'))
                ->assertOk()->assertJson(['exists' => true, 'name' => 'Ahmed Hassan']);
        }

        $this->actingAs($a, 'owner')->getJson('/users/phone-check?phone=01099999999')->assertOk()->assertExactJson(['exists' => false]);
        $this->actingAs($a, 'owner')->getJson('/users/phone-check?phone=010')->assertOk()->assertExactJson(['exists' => false]);
    }

    public function test_never_reveals_another_business_members(): void
    {
        $a = $this->owner('Alpha');
        $b = $this->owner('Bravo');
        $this->member($b, 'Bravo Secret', '01055554444');

        $this->actingAs($a, 'owner')->getJson('/users/phone-check?phone=01055554444')
            ->assertOk()->assertExactJson(['exists' => false]);
    }

    public function test_requires_the_add_member_permission(): void
    {
        $a = $this->owner('Alpha');
        $this->get('/users/phone-check?phone=01012345678')->assertRedirect();

        $staff = Staff::create(['owner_id' => $a->id, 'role_id' => null, 'name' => 'No Grants',
            'email' => 'ng@t.local', 'password' => 'secret123', 'is_active' => true]);
        $this->actingAs($staff, 'staff')->get('/users/phone-check?phone=01012345678')->assertRedirect()->assertSessionHas('permission_denied');
    }

    public function test_users_page_renders_the_popup_with_the_live_check(): void
    {
        $a = $this->owner('Alpha');
        $html = $this->actingAs($a, 'owner')->get('/users')->assertOk()->getContent();

        $this->assertStringContainsString('id="add-member-modal"', $html);
        $this->assertStringContainsString('data-phone-check', $html);
        $this->assertStringContainsString('/users/phone-check', $html);
        $this->assertStringNotContainsString('ls-meter', $html); // no plan bar in the pop-up
    }
}
