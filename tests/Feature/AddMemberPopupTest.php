<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** "Add user" opens a pop-up on the Users page (same POST /users, list refreshes in place). */
class AddMemberPopupTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): Owner
    {
        $this->seed(FeatureSeeder::class);
        $plan = Plan::create(['name' => 'Free', 'slug' => 'free-'.uniqid(), 'max_members' => 3, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['booking'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);
        $owner = Owner::create(['name' => 'O', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Space',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth()]);
        $owner->enableFeature('booking');

        return $owner->fresh();
    }

    public function test_users_page_carries_plan_usage_for_the_popup(): void
    {
        $owner = $this->owner();
        HotspotUser::create(['owner_id' => $owner->id, 'name' => 'A', 'phone' => '0100', 'password' => 'x']);

        $this->actingAs($owner, 'owner')->get('/users')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Users/Index')
            ->where('plan.members', 1)
            ->where('plan.max_members', 3)
            ->where('plan.remaining', 2));

        // Live search is a partial reload: the plan isn't recomputed.
        $partial = $this->actingAs($owner, 'owner')->get('/users?search=A', [
            'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Users/Index', 'X-Inertia-Partial-Data' => 'users,search',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        ]);
        $partial->assertOk();
        $this->assertArrayNotHasKey('plan', $partial->json('props'));
    }

    public function test_saving_from_the_popup_returns_to_the_list_and_validation_stays_on_the_server(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'owner')->from('/users')->post('/users', ['name' => '', 'phone' => ''])
            ->assertRedirect('/users')->assertSessionHasErrors(['name', 'phone']);

        $this->actingAs($owner, 'owner')->post('/users', ['name' => 'Popup Member', 'phone' => '01099998888'])
            ->assertRedirect('/users')->assertSessionHas('success');
        $this->assertSame(1, HotspotUser::where('owner_id', $owner->id)->where('phone', '01099998888')->count());

        $this->actingAs($owner, 'owner')->from('/users')->post('/users', ['name' => 'Dup', 'phone' => '01099998888'])
            ->assertSessionHasErrors('phone');
    }
}
