<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Feedback;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Staff;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Public /feedback page + Super Admin → Feedback. */
class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        Cache::flush();
    }

    /** A human-paced submission: render stamp from a minute ago, empty honeypot. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'rating' => 4,
            'category' => 'general',
            'product_area' => 'bookings',
            'message' => 'The booking calendar is great, but '.uniqid().' would help.',
            'contact_name' => '',
            'contact_email' => '',
            'website' => '',
            'started_at' => Crypt::encryptString((string) now()->subMinute()->timestamp),
        ], $overrides);
    }

    private function owner(string $name = 'Alpha'): Owner
    {
        $plan = Plan::create(['name' => 'P', 'slug' => 'p-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['workspace', 'booking'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);

        return Owner::create(['name' => $name.' Owner', 'email' => strtolower($name).uniqid().'@t.local', 'password' => 'secret123', 'business_name' => $name,
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth()]);
    }

    private function admin(): Admin
    {
        return Admin::create(['name' => 'Root', 'email' => 'root'.uniqid().'@t.local', 'password' => bcrypt('secret123')]);
    }

    private function make(array $attrs = []): Feedback
    {
        $fb = Feedback::create(array_merge(['rating' => 3, 'category' => 'general', 'message' => 'Some feedback text here', 'locale' => 'en'], $attrs));
        if (isset($attrs['created_at'])) {
            $fb->forceFill(['created_at' => $attrs['created_at']])->save();
        }

        return $fb;
    }

    // ------------------------------------------------------------- public page

    public function test_page_renders_in_english_and_arabic_and_legacy_urls_redirect(): void
    {
        $this->get('/feedback')->assertOk()->assertSee(__('app.feedback.title'))->assertSee('dir="ltr"', false)
            ->assertSee('name="started_at"', false)->assertSee('name="website"', false);

        $this->withSession(['locale' => 'ar'])->get('/feedback')->assertOk()
            ->assertSee('dir="rtl"', false)->assertSee('رأيك يصنع تجربة أفضل!')->assertSee('إرسال الرأي');

        // Shareable language link: remembers the choice, then a clean URL.
        $this->get('/feedback?lang=ar')->assertRedirect('/feedback')->assertSessionHas('locale', 'ar');
        $this->get('/feedback?lang=xx')->assertOk();

        // First visit with an Arabic browser and no chosen language.
        $this->withHeader('Accept-Language', 'ar-EG,ar;q=0.9')->get('/feedback')->assertSee('dir="rtl"', false);

        $this->get('/submityourfeesback')->assertStatus(301)->assertRedirect('/feedback');
        $this->get('/submityourfeedback')->assertStatus(301)->assertRedirect('/feedback');
    }

    public function test_anonymous_submission_is_stored_and_shows_thank_you(): void
    {
        $this->post('/feedback', $this->payload([
            'category' => 'bug', 'details' => 'Clicked save, nothing happened.', 'contact_name' => ' Mona ', 'contact_email' => 'Mona@Example.com',
        ]))->assertRedirect('/feedback')->assertSessionHas('feedback_sent');

        $fb = Feedback::sole();
        $this->assertSame(4, $fb->rating);
        $this->assertSame('bug', $fb->category);
        $this->assertSame('bookings', $fb->product_area);
        $this->assertSame('Clicked save, nothing happened.', $fb->details);
        $this->assertSame('Mona', $fb->contact_name);
        $this->assertSame('mona@example.com', $fb->contact_email);
        $this->assertSame('new', $fb->status);
        $this->assertNull($fb->owner_id);
        $this->assertNull($fb->staff_id);

        $this->withSession(['feedback_sent' => true])->get('/feedback')->assertSee(__('app.feedback.thanks.title'))->assertSee(__('app.feedback.thanks.again'));
    }

    public function test_details_are_dropped_for_categories_without_the_follow_up_question(): void
    {
        $this->post('/feedback', $this->payload(['category' => 'complaint', 'details' => 'smuggled']))->assertRedirect('/feedback');
        $this->assertNull(Feedback::sole()->details);
    }

    public function test_signed_in_owner_and_staff_are_linked_from_the_session_never_from_the_form(): void
    {
        $alpha = $this->owner('Alpha');
        $bravo = $this->owner('Bravo');

        $this->actingAs($alpha, 'owner')->post('/feedback', $this->payload(['owner_id' => $bravo->id, 'staff_id' => 999]))->assertRedirect('/feedback');
        $fb = Feedback::sole();
        $this->assertSame($alpha->id, $fb->owner_id);
        $this->assertNull($fb->staff_id);

        $staff = Staff::create(['owner_id' => $bravo->id, 'name' => 'Desk', 'email' => 'd'.uniqid().'@t.local', 'password' => 'secret123', 'is_active' => true]);
        auth('owner')->logout();
        $this->actingAs($staff, 'staff')->post('/feedback', $this->payload())->assertRedirect('/feedback');
        $fb2 = Feedback::latest('id')->first();
        $this->assertSame($bravo->id, $fb2->owner_id);
        $this->assertSame($staff->id, $fb2->staff_id);

        auth('staff')->logout();
        $this->flushSession();
        $this->actingAs($alpha, 'owner')->get('/feedback')->assertSee('Alpha Owner')->assertSee('Alpha');
    }

    public function test_required_fields_and_allowed_values_are_validated_server_side(): void
    {
        $this->from('/feedback')->post('/feedback', $this->payload(['rating' => '', 'category' => '', 'message' => '']))
            ->assertRedirect('/feedback')->assertSessionHasErrors(['rating', 'category', 'message']);

        foreach ([0, 6, 'five', 3.5] as $bad) {
            $this->post('/feedback', $this->payload(['rating' => $bad]))->assertSessionHasErrors('rating');
        }
        $this->post('/feedback', $this->payload(['category' => 'praise']))->assertSessionHasErrors('category');
        $this->post('/feedback', $this->payload(['product_area' => 'billing-secret']))->assertSessionHasErrors('product_area');
        $this->post('/feedback', $this->payload(['message' => 'short']))->assertSessionHasErrors('message');
        $this->post('/feedback', $this->payload(['message' => str_repeat('a', Feedback::MESSAGE_MAX + 1)]))->assertSessionHasErrors('message');
        $this->post('/feedback', $this->payload(['contact_email' => 'not-an-email']))->assertSessionHasErrors('contact_email');

        $this->assertSame(0, Feedback::count());
    }

    public function test_validation_messages_are_arabic_on_the_arabic_page_and_input_is_kept(): void
    {
        $this->withSession(['locale' => 'ar'])->from('/feedback')
            ->post('/feedback', $this->payload(['rating' => '', 'message' => 'Kept text']))
            ->assertSessionHasErrors(['rating' => 'من فضلك اختر تقييمًا من 1 إلى 5 نجوم.'])
            ->assertSessionHasInput('message', 'Kept text');
    }

    public function test_bots_are_silently_dropped(): void
    {
        $this->post('/feedback', $this->payload(['website' => 'http://spam.example']))->assertRedirect('/feedback')->assertSessionHas('feedback_sent');
        $this->post('/feedback', $this->payload(['started_at' => Crypt::encryptString((string) now()->timestamp)]))->assertRedirect('/feedback');
        $this->post('/feedback', $this->payload(['started_at' => 'forged']))->assertRedirect('/feedback');
        $this->post('/feedback', $this->payload(['started_at' => null]))->assertRedirect('/feedback');

        $this->assertSame(0, Feedback::count());
    }

    public function test_duplicate_submission_is_stored_once(): void
    {
        $same = $this->payload(['message' => 'Exactly the same message twice']);
        $this->post('/feedback', $same)->assertRedirect('/feedback');
        $this->post('/feedback', array_merge($same, ['message' => '  exactly the SAME   message twice ']))->assertRedirect('/feedback')->assertSessionHas('feedback_sent');

        $this->assertSame(1, Feedback::count());
    }

    public function test_submissions_are_rate_limited_per_address(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/feedback', $this->payload())->assertSessionHasNoErrors();
        }
        $this->from('/feedback')->post('/feedback', $this->payload())->assertSessionHasErrors('form');
        $this->assertSame(5, Feedback::count());

        RateLimiter::clear('feedback:'.sha1('127.0.0.1').':10m');
        $this->post('/feedback', $this->payload())->assertSessionHasNoErrors();
        $this->assertSame(6, Feedback::count());
    }

    public function test_message_is_escaped_on_the_admin_pages(): void
    {
        $fb = $this->make(['message' => '<script>alert(1)</script> hello there', 'contact_name' => '<b>x</b>']);
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get('/admin/feedback')->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
        $this->actingAs($admin, 'admin')->get('/admin/feedback/'.$fb->id)->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<b>x</b>', false);
    }

    // ------------------------------------------------------------- super admin

    public function test_only_super_admins_can_reach_feedback_management(): void
    {
        $fb = $this->make();
        $owner = $this->owner();
        $staff = Staff::create(['owner_id' => $owner->id, 'name' => 'S', 'email' => 's'.uniqid().'@t.local', 'password' => 'secret123', 'is_active' => true]);

        foreach (['/admin/feedback', '/admin/feedback/'.$fb->id] as $url) {
            $this->get($url)->assertRedirect('/login');
            $this->actingAs($owner, 'owner')->get($url)->assertRedirect('/login');
            $this->actingAs($staff, 'staff')->get($url)->assertRedirect('/login');
        }
        $this->actingAs($owner, 'owner')->put('/admin/feedback/'.$fb->id, ['status' => 'rejected', 'admin_notes' => 'x'])->assertRedirect('/login');
        $this->assertSame('new', $fb->fresh()->status);
        $this->assertNull($fb->fresh()->admin_notes);
    }

    public function test_metrics_are_correct_for_empty_and_populated_data(): void
    {
        $admin = $this->admin();

        $res = $this->actingAs($admin, 'admin')->get('/admin/feedback')->assertOk()->assertSee(__('app.feedback.admin.empty'));
        $this->assertSame(['total' => 0, 'avg' => null, 'unreviewed' => 0, 'features' => 0, 'problems' => 0], $res->viewData('stats'));
        $this->assertSame([], $res->viewData('areas'));
        $this->assertSame(0, $res->viewData('distribution')[5]['count']);

        $this->make(['rating' => 5, 'category' => 'feature', 'product_area' => 'bookings']);
        $this->make(['rating' => 4, 'category' => 'feature', 'product_area' => 'bookings']);
        $this->make(['rating' => 1, 'category' => 'bug', 'product_area' => 'sales']);
        $this->make(['rating' => 2, 'category' => 'complaint', 'product_area' => 'sales']);
        $reviewed = $this->make(['rating' => 3, 'category' => 'general']);
        $reviewed->forceFill(['status' => 'planned'])->save();

        $res = $this->actingAs($admin, 'admin')->get('/admin/feedback')->assertOk();
        $this->assertSame(['total' => 5, 'avg' => 3.0, 'unreviewed' => 4, 'features' => 2, 'problems' => 2], $res->viewData('stats'));

        $dist = $res->viewData('distribution');
        $this->assertSame([5, 4, 3, 2, 1], array_keys($dist));
        $this->assertSame(1, $dist[1]['count']);
        $this->assertEquals(20.0, $dist[1]['pct']);

        // Lowest-rated area first; "not specified" kept as its own row.
        $areas = collect($res->viewData('areas'));
        $this->assertSame('sales', $areas->first()['area']);
        $this->assertEquals(1.5, $areas->first()['avg']);
        $this->assertSame(2, $areas->first()['problems']);
        $this->assertSame(2, $areas->first()['low']);
        $this->assertSame(2, $areas->firstWhere('area', 'bookings')['features']);
        $this->assertNotNull($areas->first(fn ($a) => $a['area'] === null));

        // This month's bucket is the last one in both charts.
        $charts = $res->viewData('charts');
        $this->assertSame(2, end($charts['volume']['datasets'][0]['data']));  // feature requests
        $this->assertSame(2, end($charts['volume']['datasets'][1]['data']));  // bugs + complaints
        $this->assertEquals(3.0, end($charts['rating']['datasets'][0]['data']));
    }

    public function test_list_filters_sorts_and_paginates(): void
    {
        $admin = $this->admin();
        $this->make(['rating' => 5, 'category' => 'feature', 'product_area' => 'bookings', 'message' => 'Please add recurring bookings', 'contact_email' => 'lina@cafe.test']);
        $this->make(['rating' => 1, 'category' => 'bug', 'product_area' => 'sales', 'message' => 'Receipt printer crashes', 'created_at' => now()->subDays(40)]);
        for ($i = 0; $i < 22; $i++) {
            $this->make(['rating' => 3, 'message' => 'Filler feedback number '.$i]);
        }

        $get = fn (string $q) => $this->actingAs($admin, 'admin')->get('/admin/feedback'.$q)->assertOk()->viewData('items');

        $this->assertSame(20, $get('')->count());
        $this->assertSame(24, $get('')->total());
        $this->assertSame(4, $get('?page=2')->count());

        $this->assertSame(['Please add recurring bookings'], $get('?q=recurring')->pluck('message')->all());
        $this->assertSame(['Please add recurring bookings'], $get('?q=lina@cafe')->pluck('message')->all());
        $this->assertSame(['Receipt printer crashes'], $get('?rating=1')->pluck('message')->all());
        $this->assertSame(['Please add recurring bookings'], $get('?category=feature')->pluck('message')->all());
        $this->assertSame(['Receipt printer crashes'], $get('?area=sales')->pluck('message')->all());
        $this->assertSame(['Receipt printer crashes'], $get('?to='.now()->subDays(30)->toDateString())->pluck('message')->all());
        $this->assertSame(23, $get('?from='.now()->subDays(5)->toDateString())->total());

        $this->assertSame(1, $get('?sort=rating_low')->first()->rating);
        $this->assertSame(5, $get('?sort=rating_high')->first()->rating);
        $this->assertSame('Receipt printer crashes', $get('?sort=oldest')->first()->message);

        // Junk filters are ignored, not errors.
        $this->assertSame(24, $get('?rating=9&category=x&area=y&status=z&from=nope&sort=DROP')->total());
    }

    public function test_review_updates_status_and_private_notes_without_touching_the_submission(): void
    {
        $admin = $this->admin();
        $fb = $this->make(['rating' => 2, 'category' => 'complaint', 'message' => 'Original words', 'contact_email' => 'a@b.test']);

        $this->actingAs($admin, 'admin')->put('/admin/feedback/'.$fb->id, [
            'status' => 'planned', 'admin_notes' => 'Add to Q1 roadmap', 'message' => 'tampered', 'rating' => 5,
        ])->assertRedirect(route('admin.feedback.show', $fb));

        $fb->refresh();
        $this->assertSame('planned', $fb->status);
        $this->assertSame('Add to Q1 roadmap', $fb->admin_notes);
        $this->assertSame($admin->id, $fb->reviewed_by);
        $this->assertNotNull($fb->reviewed_at);
        $this->assertSame('Original words', $fb->message);
        $this->assertSame(2, $fb->rating);

        $this->actingAs($admin, 'admin')->put('/admin/feedback/'.$fb->id, ['status' => 'archived'])->assertSessionHasErrors('status');
        $this->assertSame('planned', $fb->fresh()->status);

        // Notes are internal: never on the public page.
        $this->get('/feedback')->assertDontSee('Add to Q1 roadmap');
        $this->actingAs($admin, 'admin')->get('/admin/feedback/'.$fb->id)->assertSee('Add to Q1 roadmap')->assertSee('a@b.test');
    }

    public function test_feedback_is_not_visible_to_business_owners_and_admin_nav_shows_new_count(): void
    {
        $owner = $this->owner();
        $this->make(['message' => 'Platform-wide secret opinion']);

        $this->actingAs($owner, 'owner')->get('/dashboard')->assertDontSee('Platform-wide secret opinion');
        $this->actingAs($this->admin(), 'admin')->get('/admin/dashboard')->assertOk()->assertSee(__('app.feedback.admin.nav'));
    }
}
