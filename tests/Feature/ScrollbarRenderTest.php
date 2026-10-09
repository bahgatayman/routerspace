<?php
namespace Tests\Feature;
use App\Models\Admin;
use App\Models\Owner;
use App\Models\Plan;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScrollbarRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_scrollbar_css_reaches_every_layout(): void
    {
        $this->seed(FeatureSeeder::class);
        $plan  = Plan::create(['name'=>'Basic','slug'=>'basic','max_members'=>50,'price_per_month'=>0,'is_active'=>true,'sort_order'=>1]);
        $owner = Owner::create(['name'=>'O','email'=>'s@t.local','password'=>'password','business_name'=>'Nile','plan_id'=>$plan->id,'is_active'=>true,'subscription_starts_at'=>now(),'subscription_expires_at'=>now()->addMonth()])->fresh();
        $admin = Admin::create(['name'=>'B','email'=>'sa@t.local','password'=>'password']);

        $auth_html  = $this->get('/login')->assertOk()->getContent();
        $owner_html = $this->actingAs($owner,'owner')->get('/dashboard')->assertOk()->getContent();
        $admin_html = $this->actingAs($admin,'admin')->get('/admin/dashboard')->assertOk()->getContent();

        foreach (['owner'=>$owner_html,'admin'=>$admin_html,'auth'=>$auth_html] as $name => $html) {
            $this->assertStringContainsString('::-webkit-scrollbar-thumb', $html, "missing on {$name}");
            $this->assertStringContainsString('scrollbar-color: #b0c6e6 transparent', $html, "missing on {$name}");
        }

        // Dark sidebars opt into the inverted thumb.
        // The owner sidebar is rendered by the React OwnerLayout (/dashboard is an Inertia page).
        $this->assertStringContainsString('className="nav-scroll ', file_get_contents(resource_path('js/Layouts/OwnerLayout.jsx')));
        // The admin sidebar is rendered by the React AdminLayout (/admin/dashboard is an Inertia page).
        $this->assertStringContainsString('className="nav-scroll ', file_get_contents(resource_path('js/Layouts/AdminLayout.jsx')));
        $this->assertStringNotContainsString('class="nav-scroll ', $auth_html);
    }
}
