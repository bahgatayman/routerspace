<?php
namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\Workspace;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClickableRowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_rows_are_clickable_and_view_link_is_gone(): void
    {
        $this->seed(FeatureSeeder::class);
        $plan = Plan::create(['name'=>'B','slug'=>'b','max_members'=>10,'price_per_month'=>0,'is_active'=>true,'sort_order'=>1]);
        $o = Owner::create(['name'=>'O','email'=>'b@t.local','password'=>'p','business_name'=>'B',
            'plan_id'=>$plan->id,'is_active'=>true,'subscription_starts_at'=>now(),
            'subscription_expires_at'=>now()->addMonth()])->fresh();
        $o->enableFeature('booking'); $o->enableFeature('workspace');
        $o = $o->fresh();

        $ws = Workspace::create(['owner_id'=>$o->id,'name'=>'Hub','is_active'=>true]);
        $room = Room::create(['owner_id'=>$o->id,'workspace_id'=>$ws->id,'name'=>'A','type'=>'meeting',
            'capacity'=>4,'price_per_hour'=>100,'is_available'=>true]);
        $member = HotspotUser::create(['owner_id'=>$o->id,'name'=>'Sara','phone'=>'0100','password'=>'0100',
            'speed_download'=>'10M','speed_upload'=>'5M','status'=>'active']);
        $pending = Booking::create(['owner_id'=>$o->id,'room_id'=>$room->id,'hotspot_user_id'=>$member->id,
            'booking_date'=>now()->addDay()->toDateString(),'start_time'=>'10:00','end_time'=>'12:00',
            'price_per_hour'=>100,'total_hours'=>2,'total_price'=>200,'status'=>'pending']);
        $done = Booking::create(['owner_id'=>$o->id,'room_id'=>$room->id,'hotspot_user_id'=>$member->id,
            'booking_date'=>now()->subDay()->toDateString(),'start_time'=>'10:00','end_time'=>'11:00',
            'price_per_hour'=>100,'total_hours'=>1,'total_price'=>100,'status'=>'completed']);

        // The bookings list is a React page: one row per booking, each knowing its id
        // (the row links to /bookings/{id}), with Edit only where still editable.
        $res = $this->actingAs($o,'owner')->get('/bookings')->assertOk();
        $this->assertSame('Bookings/Index', $res->inertiaPage()['component']);
        $rows = collect($res->inertiaProps('bookings.data'))->keyBy('id');
        $this->assertCount(2, $rows, 'one row-link per booking');
        $this->assertTrue($rows[$pending->id]['can_edit']);
        $this->assertFalse($rows[$done->id]['can_edit']);
        // The member link inside the row is kept.
        $this->assertSame($member->id, $rows[$pending->id]['customer_id']);

        $jsx = file_get_contents(resource_path('js/Pages/Bookings/Index.jsx'));
        // Rows carry the click target, and the date cell is still a real link.
        $this->assertStringContainsString('data-href={`/bookings/${b.id}`}', $jsx);
        $this->assertStringContainsString('className={`row-link', $jsx);
        $this->assertStringContainsString('<Link href={`/bookings/${b.id}`}', $jsx);
        // The redundant "View" action is gone; Edit is a plain link to the Blade form.
        $this->assertStringNotContainsString("t('common.view')", $jsx);
        $this->assertStringContainsString('href={`/bookings/${b.id}/edit`}', $jsx);
        // The row handler must not swallow the links inside the row.
        $this->assertStringContainsString("e.target.closest('a, button", $jsx);
    }

    public function test_users_page_still_gets_the_shared_handler(): void
    {
        $this->seed(FeatureSeeder::class);
        $plan = Plan::create(['name'=>'B','slug'=>'b2','max_members'=>10,'price_per_month'=>0,'is_active'=>true,'sort_order'=>1]);
        $o = Owner::create(['name'=>'O','email'=>'u@t.local','password'=>'p','business_name'=>'B',
            'plan_id'=>$plan->id,'is_active'=>true,'subscription_starts_at'=>now(),
            'subscription_expires_at'=>now()->addMonth()])->fresh();
        $o->enableFeature('booking');
        $o = $o->fresh();
        $member = HotspotUser::create(['owner_id'=>$o->id,'name'=>'Sara','phone'=>'0101','password'=>'0101',
            'speed_download'=>'10M','speed_upload'=>'5M','status'=>'active']);

        // The members list is a React page now: each row carries its id (the
        // row links to /users/{id}) and the page wires whole-row navigation itself.
        $this->actingAs($o,'owner')->get('/users')->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $p) => $p
                ->component('Users/Index')
                ->where('users.data.0.id', $member->id));

        $jsx = file_get_contents(resource_path('js/Pages/Users/Index.jsx'));
        $this->assertStringContainsString('className="row-link', $jsx);
        $this->assertStringContainsString('onClick={(e) => rowClick(e, href)}', $jsx);
    }
}
