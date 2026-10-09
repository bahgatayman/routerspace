<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\Sale;
use App\Models\Workspace;
use App\Services\AnalyticsPeriod;
use App\Services\CustomerAnalyticsService;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private CustomerAnalyticsService $customers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FeatureSeeder::class);
        $this->customers = app(CustomerAnalyticsService::class);
    }

    private function owner(): Owner
    {
        $plan = Plan::create([
            'name' => 'Test', 'slug' => 'test-'.uniqid(), 'max_members' => 100,
            'price_per_month' => 0, 'is_active' => true, 'sort_order' => 1,
            'features' => ['hotspot'],
            'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0,
        ]);

        return Owner::create([
            'name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123',
            'business_name' => 'Space', 'plan_id' => $plan->id, 'is_active' => true,
            'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth(),
        ]);
    }

    private function memberCreatedAt(Owner $owner, string $createdAt): HotspotUser
    {
        $member = HotspotUser::create([
            'owner_id' => $owner->id, 'name' => 'Member', 'phone' => '010'.rand(10000000, 99999999),
            'password' => 'pass1234',
        ]);

        // Bypasses Eloquent's auto-timestamping — a plain query-builder
        // update writes the literal value, so the record can be backdated
        // for period tests.
        HotspotUser::where('id', $member->id)->update(['created_at' => $createdAt]);

        return $member->fresh();
    }

    private function room(Owner $owner): Room
    {
        $workspace = Workspace::create(['owner_id' => $owner->id, 'name' => 'Main']);

        return Room::create([
            'owner_id' => $owner->id, 'workspace_id' => $workspace->id, 'name' => 'Room',
            'type' => 'meeting', 'capacity' => 4, 'price_per_hour' => 50,
        ]);
    }

    private function booking(Owner $owner, Room $room, HotspotUser $member, string $date, float $amount = 100.0): Booking
    {
        return Booking::create([
            'owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'party_size' => 1, 'booking_date' => $date, 'start_time' => '09:00', 'end_time' => '11:00',
            'price_per_hour' => $amount / 2, 'total_hours' => 2, 'total_price' => $amount,
            'amount_paid' => $amount, 'payment_status' => 'paid', 'status' => 'completed',
        ]);
    }

    private function sale(Owner $owner, HotspotUser $member, string $soldAt, float $total): Sale
    {
        return Sale::create([
            'owner_id' => $owner->id, 'hotspot_user_id' => $member->id, 'status' => 'completed',
            'subtotal' => $total, 'total' => $total, 'sold_at' => $soldAt,
        ]);
    }

    public function test_total_customers_is_scoped_to_the_owner(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $this->memberCreatedAt($owner, '2026-08-01 10:00:00');
        $this->memberCreatedAt($owner, '2026-08-05 10:00:00');
        $this->memberCreatedAt($other, '2026-08-05 10:00:00');

        $this->assertSame(2, $this->customers->totalCustomers($owner));
        $this->assertSame(1, $this->customers->totalCustomers($other));
    }

    public function test_new_customers_counts_only_those_created_within_the_period(): void
    {
        $owner = $this->owner();
        $this->memberCreatedAt($owner, '2026-07-31 23:59:59'); // just before
        $this->memberCreatedAt($owner, '2026-08-01 00:00:00'); // in period
        $this->memberCreatedAt($owner, '2026-08-15 12:00:00'); // in period
        $this->memberCreatedAt($owner, '2026-09-01 00:00:01'); // just after

        $period = AnalyticsPeriod::custom(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $this->assertSame(2, $this->customers->newCustomers($owner, $period));
    }

    public function test_returning_customer_rate_is_null_below_minimum_customers(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $period = AnalyticsPeriod::custom(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        // Only 4 active customers — below MIN_CUSTOMERS_FOR_RATE (5).
        for ($i = 0; $i < 4; $i++) {
            $this->booking($owner, $room, $this->memberCreatedAt($owner, '2026-07-01'), '2026-08-05');
        }

        $this->assertNull($this->customers->returningCustomerRate($owner, $period));
    }

    public function test_returning_customer_rate_identifies_returning_vs_new(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $period = AnalyticsPeriod::custom(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        // 2 customers who were also active before the period started.
        for ($i = 0; $i < 2; $i++) {
            $member = $this->memberCreatedAt($owner, '2026-06-01');
            $this->booking($owner, $room, $member, '2026-07-01'); // before period
            $this->booking($owner, $room, $member, '2026-08-05'); // within period
        }

        // 3 brand-new customers, active only within the period.
        for ($i = 0; $i < 3; $i++) {
            $this->booking($owner, $room, $this->memberCreatedAt($owner, '2026-08-01'), '2026-08-06');
        }

        $result = $this->customers->returningCustomerRate($owner, $period);

        $this->assertSame(['returning' => 2, 'total' => 5, 'percent' => 40.0], $result);
    }

    public function test_average_spend_per_customer_averages_booking_and_sale_revenue(): void
    {
        $owner = $this->owner();
        $room = $this->room($owner);
        $period = AnalyticsPeriod::custom(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $bookingCustomer = $this->memberCreatedAt($owner, '2026-08-01');
        $this->booking($owner, $room, $bookingCustomer, '2026-08-05', amount: 100.0);

        $saleCustomer = $this->memberCreatedAt($owner, '2026-08-01');
        $this->sale($owner, $saleCustomer, '2026-08-10 12:00:00', 50.0);

        // (100 + 50) / 2 active customers = 75.0
        $this->assertSame(75.0, $this->customers->averageSpendPerCustomer($owner, $period));
    }

    public function test_average_spend_per_customer_is_null_when_nobody_was_active(): void
    {
        $owner = $this->owner();
        $period = AnalyticsPeriod::custom(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $this->assertNull($this->customers->averageSpendPerCustomer($owner, $period));
    }
}
