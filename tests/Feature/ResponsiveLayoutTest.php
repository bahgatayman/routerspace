<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\Workspace;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Structural guards for the responsive shell.
 *
 * Wide tables must scroll inside their own container — otherwise they widen the
 * page itself and the whole layout scrolls sideways on a phone. And the app
 * shell must stay pinned to the viewport so the sidebar and top bar never
 * scroll away with the content.
 */
class ResponsiveLayoutTest extends TestCase
{
    use RefreshDatabase;

    private Owner $owner;
    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);

        $plan = Plan::create([
            'name' => 'Basic', 'slug' => 'basic', 'max_members' => 50,
            'price_per_month' => 100, 'is_active' => true, 'sort_order' => 1,
        ]);

        $owner = Owner::create([
            'name' => 'Owner', 'email' => 'r@t.local', 'password' => 'password',
            'business_name' => 'Nile Works', 'plan_id' => $plan->id, 'is_active' => true,
            'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
        ])->fresh();

        foreach (['hotspot', 'workspace', 'booking'] as $feature) {
            $owner->enableFeature($feature);
        }
        $this->owner = $owner->fresh();

        $ws   = Workspace::create(['owner_id' => $owner->id, 'name' => 'Hub', 'city' => 'Cairo', 'is_active' => true]);
        $room = Room::create([
            'owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Board A',
            'type' => 'meeting', 'capacity' => 8, 'price_per_hour' => 120, 'is_available' => true,
        ]);
        $member = HotspotUser::create([
            'owner_id' => $owner->id, 'name' => 'Sara Ahmed', 'phone' => '01000001', 'password' => '01000001',
            'speed_download' => '10M', 'speed_upload' => '5M', 'status' => 'active',
        ]);
        $this->booking = Booking::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'booking_date' => now()->addDay()->toDateString(), 'start_time' => '10:00', 'end_time' => '12:00',
            'price_per_hour' => 120, 'total_hours' => 2, 'total_price' => 240, 'status' => 'pending',
        ]);
    }

    /** Tables with no scrollable ancestor — these are what push the page sideways. */
    private function unwrappedTables(string $html): int
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        return (new \DOMXPath($doc))
            ->query("//table[not(ancestor::*[contains(@class,'overflow-x-auto')])]")
            ->length;
    }

    public static function ownerPages(): array
    {
        return [
            'members'   => ['/users'],
            'bookings'  => ['/bookings'],
            'workspaces' => ['/workspaces'],
            'plans'     => ['/subscription/plans'],
            'profile'   => ['/profile'],
            'dashboard' => ['/dashboard'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ownerPages')]
    public function test_owner_pages_keep_wide_tables_in_their_own_scroller(string $url): void
    {
        $html = $this->actingAs($this->owner, 'owner')->get($url)->assertOk()->getContent();

        $this->assertSame(0, $this->unwrappedTables($html), "Unwrapped <table> on {$url}");
    }

    public function test_booking_detail_keeps_its_items_table_wrapped(): void
    {
        // /bookings/{id} is a React page: its markup lives in the JSX, not the server HTML.
        $res = $this->actingAs($this->owner, 'owner')->get("/bookings/{$this->booking->id}")->assertOk();
        $this->assertSame('Bookings/Show', $res->inertiaPage()['component']);

        $this->assertSame(0, $this->unwrappedJsxTables('js/Pages/Bookings/Show.jsx'));
    }

    /** React pages (bookings list/calendar, dashboard): every <table> sits in its own overflow-x-auto scroller. */
    public function test_react_booking_and_dashboard_tables_are_wrapped(): void
    {
        foreach (['js/Pages/Bookings/Index.jsx', 'js/Pages/Bookings/Calendar.jsx', 'js/Pages/Dashboard/Index.jsx', 'js/Components/Dashboard/ProductsSection.jsx'] as $file) {
            $this->assertSame(0, $this->unwrappedJsxTables($file), "Unwrapped <table> in {$file}");
        }
    }

    /** Tables in a JSX file not opened directly inside an overflow-x-auto wrapper. */
    private function unwrappedJsxTables(string $file): int
    {
        $jsx = file_get_contents(resource_path($file));
        preg_match_all('/<table\b/', $jsx, $tables, PREG_OFFSET_CAPTURE);
        $unwrapped = 0;
        foreach ($tables[0] as [, $offset]) {
            // The wrapper must be the element right before the table.
            $before = substr($jsx, 0, $offset);
            $lastOpen = strrpos($before, '<');
            $wrapper = $lastOpen === false ? '' : substr($before, $lastOpen);
            if (! str_contains($wrapper, 'overflow-x-auto')) {
                $unwrapped++;
            }
        }

        return $unwrapped;
    }

    public function test_admin_financial_tables_are_wrapped(): void
    {
        $admin = Admin::create(['name' => 'Boss', 'email' => 'fin@t.local', 'password' => 'password']);

        $html = $this->actingAs($admin, 'admin')->get('/admin/financial')->assertOk()->getContent();

        $this->assertSame(0, $this->unwrappedTables($html));
    }

    public function test_the_app_shell_is_pinned_to_the_viewport(): void
    {
        // /dashboard is a React page: the shell markup is rendered by the persistent OwnerLayout,
        // and the pinning CSS comes from the Inertia root template's <head>.
        $html = $this->actingAs($this->owner, 'owner')->get('/dashboard')->assertOk()->getContent();
        $layout = file_get_contents(resource_path('js/Layouts/OwnerLayout.jsx'));

        // Fixed-height shell, not a min-height that grows with content.
        $this->assertStringContainsString('className="app-shell', $layout);
        $this->assertStringContainsString('height: 100dvh', $html);
        $this->assertStringContainsString('overflow: hidden', $html);

        // Only <main> scrolls, and it can shrink inside the flex column.
        $this->assertStringContainsString('flex-1 min-h-0 overflow-y-auto', $layout);

        // The old growing shell is gone.
        $this->assertStringNotContainsString('min-h-screen flex flex-col lg:flex-row', $layout);
        $this->assertStringNotContainsString('<div class="min-h-screen flex flex-col lg:flex-row">', $html);
    }

    public function test_admin_shell_is_pinned_too(): void
    {
        $admin = Admin::create(['name' => 'Boss', 'email' => 'shell@t.local', 'password' => 'password']);

        // /admin/dashboard is a React page: the shell markup is rendered by the persistent AdminLayout.
        $res = $this->actingAs($admin, 'admin')->get('/admin/dashboard')->assertOk();
        $this->assertSame('Admin/Dashboard/Index', $res->inertiaPage()['component']);
        $layout = file_get_contents(resource_path('js/Layouts/AdminLayout.jsx'));

        $this->assertStringContainsString('className="app-shell', $layout);
        $this->assertStringContainsString('flex-1 min-h-0 overflow-y-auto', $layout);
    }
}
