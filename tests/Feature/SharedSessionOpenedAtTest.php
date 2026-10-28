<?php

namespace Tests\Feature;

use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\SharedSession;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * MySQL/MariaDB only. With explicit_defaults_for_timestamp = OFF the server
 * gave shared_sessions.opened_at ON UPDATE CURRENT_TIMESTAMP, so closing a
 * session reset its start and billed 0 minutes. Run against a throwaway
 * server started with --explicit-defaults-for-timestamp=0 to reproduce:
 *
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3399 DB_DATABASE=… php artisan test --group=mysql
 */
#[Group('mysql')]
class SharedSessionOpenedAtTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL/MariaDB only: SQLite never auto-updates a timestamp.');
        }
    }

    public function test_closing_a_session_never_moves_when_it_opened(): void
    {
        $extra = DB::selectOne("SELECT EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shared_sessions' AND COLUMN_NAME = 'opened_at'")->extra;
        $this->assertStringNotContainsStringIgnoringCase('on update', (string) $extra, 'opened_at must not follow row updates.');

        $plan = Plan::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0, 'is_active' => true, 'sort_order' => 1,
            'features' => ['workspace', 'booking'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);
        $owner = Owner::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Space',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now()->subDay(), 'subscription_expires_at' => now()->addYear()]);
        $ws = Workspace::create(['owner_id' => $owner->id, 'name' => 'Main']);
        $room = Room::create(['owner_id' => $owner->id, 'workspace_id' => $ws->id, 'name' => 'Hall', 'type' => 'shared', 'capacity' => 6, 'price_per_hour' => 60]);
        $member = HotspotUser::create(['owner_id' => $owner->id, 'name' => 'M', 'phone' => '010'.rand(10000000, 99999999), 'password' => 'pass1234']);

        $openedAt = now()->subHours(2)->startOfMinute();
        $session = SharedSession::create(['owner_id' => $owner->id, 'room_id' => $room->id, 'hotspot_user_id' => $member->id, 'party_size' => 1,
            'session_date' => $openedAt->toDateString(), 'start_time' => $openedAt->format('H:i'), 'opened_at' => $openedAt, 'status' => 'open']);

        // The same write SharedSessionController::close() makes.
        SharedSession::whereKey($session->id)->update(['status' => 'closed', 'closed_at' => now()]);

        $this->assertSame($openedAt->toDateTimeString(), $session->fresh()->opened_at->toDateTimeString());
    }
}
