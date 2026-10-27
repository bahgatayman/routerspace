<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\SharedSession;
use App\Models\Workspace;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Concurrency of bookings across different uses of ONE physical room, on
 * MySQL/MariaDB only — row locks are a no-op on SQLite, so the suite's normal
 * SQLite run skips this class. Run against a THROWAWAY database, e.g.:
 *
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3399 DB_DATABASE=linkspace_concurrency_test \
 *   DB_USERNAME=root DB_PASSWORD= php vendor/bin/phpunit --group mysql
 *
 * DatabaseTruncation (not RefreshDatabase): rows must be committed so that the
 * child processes — real, separate PHP processes sending real booking requests
 * (tests/Concurrency/book_child.php) — can see them.
 *
 * Method: this test holds the physical room's row lock (SELECT … FOR UPDATE)
 * while two children try to book two DIFFERENT uses for overlapping times.
 * Both must wait on that one lock (proving every use of a physical room is
 * serialized through the same row), and once it is released exactly one may
 * succeed.
 */
#[Group('mysql')]
class RoomUsesConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private Owner $owner;

    private Room $physical;

    private Room $training;

    private string $day;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql' && DB::connection()->getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Needs MySQL/MariaDB: row locks are a no-op on SQLite.');
        }

        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->day = Carbon::tomorrow()->toDateString();

        $plan = Plan::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['workspace', 'booking'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);
        $this->owner = Owner::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Space',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now()->subDay(), 'subscription_expires_at' => now()->addMonth()]);
        foreach ($plan->features as $key) {
            $this->owner->enableFeature($key);
        }
        $ws = Workspace::create(['owner_id' => $this->owner->id, 'name' => 'Main']);
        $this->physical = Room::create(['owner_id' => $this->owner->id, 'workspace_id' => $ws->id, 'name' => 'Meeting Room', 'type' => 'meeting', 'capacity' => 8, 'price_per_hour' => 150]);
        $this->training = new Room(['owner_id' => $this->owner->id, 'workspace_id' => $ws->id, 'name' => 'Training Room', 'type' => 'training', 'capacity' => 10, 'price_per_hour' => 400]);
        $this->training->physical_room_id = $this->physical->id;
        $this->training->save();
    }

    private function member(): HotspotUser
    {
        return HotspotUser::create(['owner_id' => $this->owner->id, 'name' => 'M '.uniqid(), 'phone' => '010'.rand(10000000, 99999999), 'password' => 'pass1234']);
    }

    /** Start one child process sending a real booking request. */
    private function startChild(Room $room, string $start, string $end, HotspotUser $member, ?string $date = null): array
    {
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => config('database.default'),
            'DB_HOST' => (string) config('database.connections.'.config('database.default').'.host'),
            'DB_PORT' => (string) config('database.connections.'.config('database.default').'.port'),
            'DB_DATABASE' => (string) config('database.connections.'.config('database.default').'.database'),
            'DB_USERNAME' => (string) config('database.connections.'.config('database.default').'.username'),
            'DB_PASSWORD' => (string) config('database.connections.'.config('database.default').'.password'),
            'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
        ]);
        $cmd = [PHP_BINARY, base_path('tests/Concurrency/book_child.php'), (string) $this->owner->id, (string) $room->id,
            (string) $member->id, $date ?? $this->day, $start, $end];
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
        $this->assertIsResource($process);

        return [$process, $pipes];
    }

    private function finish(array $child): array
    {
        [$process, $pipes] = $child;
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $this->assertSame(0, $code, "child failed: $err $out");

        $decoded = json_decode(trim(collect(explode("\n", trim($out)))->last()), true);
        $this->assertIsArray($decoded, "child output: $out $err");

        return $decoded;
    }

    private function running(array $child): bool
    {
        return proc_get_status($child[0])['running'];
    }

    public function test_two_uses_of_one_physical_room_queue_on_one_lock_and_only_one_overlapping_booking_wins(): void
    {
        // Members first: rows created inside the lock transaction below would be invisible to the children.
        [$m1, $m2] = [$this->member(), $this->member()];

        // Hold the physical room's row lock.
        $locker = DB::connection()->getPdo();
        $locker->beginTransaction();
        $locker->query('SELECT id FROM rooms WHERE id = '.(int) $this->physical->id.' FOR UPDATE')->fetchAll();

        $a = $this->startChild($this->physical, '14:00', '16:00', $m1);  // the physical room's own use
        $b = $this->startChild($this->training, '15:00', '17:00', $m2);  // another use, overlapping

        // Both must be waiting on the physical room's lock — neither can finish.
        usleep(2_500_000);
        $this->assertTrue($this->running($a), 'Booking the physical room waits for its lock.');
        $this->assertTrue($this->running($b), 'Booking ANOTHER use waits for the same physical-room lock.');

        $locker->commit(); // release

        $results = [$this->finish($a), $this->finish($b)];
        $succeeded = collect($results)->filter(fn ($r) => $r['error'] === null && $r['status'] === 302)->count();
        $refused = collect($results)->filter(fn ($r) => $r['error'] !== null)->count();

        $this->assertSame(1, $succeeded, 'Exactly one overlapping booking succeeds: '.json_encode($results));
        $this->assertSame(1, $refused, 'The other is refused: '.json_encode($results));
        $this->assertSame(1, Booking::whereIn('room_id', [$this->physical->id, $this->training->id])->whereDate('booking_date', $this->day)->count());
    }

    public function test_a_different_physical_room_is_not_blocked_by_this_rooms_lock(): void
    {
        $elsewhere = Room::create(['owner_id' => $this->owner->id, 'workspace_id' => $this->physical->workspace_id, 'name' => 'Other Room', 'type' => 'meeting', 'capacity' => 4, 'price_per_hour' => 100]);

        $m = $this->member();
        $locker = DB::connection()->getPdo();
        $locker->beginTransaction();
        $locker->query('SELECT id FROM rooms WHERE id = '.(int) $this->physical->id.' FOR UPDATE')->fetchAll();

        $c = $this->startChild($elsewhere, '14:00', '16:00', $m);
        $result = $this->finish($c); // completes while the other room is still locked
        $locker->commit();

        $this->assertNull($result['error'], json_encode($result));
        $this->assertSame(1, Booking::where('room_id', $elsewhere->id)->count(), json_encode($result));
    }

    public function test_a_walk_in_seated_while_a_booking_waits_on_the_lock_is_counted_by_that_booking(): void
    {
        // Real "now" in the child: needs a later window still inside today.
        if (now()->gte(now()->setTime(21, 0))) {
            $this->markTestSkipped('Needs two free hours left today.');
        }
        $today = now()->toDateString();
        $start = now()->addHour()->format('H:00');
        $end = now()->addHours(2)->format('H:00');

        $desks = Room::create(['owner_id' => $this->owner->id, 'workspace_id' => $this->physical->workspace_id, 'name' => 'Hot Desks', 'type' => 'shared', 'capacity' => 2, 'price_per_hour' => 50]);
        [$m1, $m2] = [$this->member(), $this->member()];

        $locker = DB::connection()->getPdo();
        $locker->beginTransaction();
        $locker->query('SELECT id FROM rooms WHERE id = '.(int) $desks->id.' FOR UPDATE')->fetchAll();

        $child = $this->startChild($desks, $start, $end, $m1, $today);
        usleep(2_000_000);
        $this->assertTrue($this->running($child), 'The booking waits for the room lock.');

        // While it waits: two walk-ins sit down (no end time → hold seats for the rest of today).
        SharedSession::create(['owner_id' => $this->owner->id, 'room_id' => $desks->id, 'hotspot_user_id' => $m2->id, 'party_size' => 2,
            'session_date' => $today, 'start_time' => now()->format('H:i'), 'opened_at' => now(), 'status' => 'open']);
        $locker->commit();

        $result = $this->finish($child);
        $this->assertNotNull($result['error'], 'The booking sees the walk-ins committed before it got the lock: '.json_encode($result));
        $this->assertSame(0, Booking::where('room_id', $desks->id)->count());
    }

    public function test_adjacent_bookings_on_two_uses_both_succeed_under_concurrency(): void
    {
        [$m1, $m2] = [$this->member(), $this->member()];
        $locker = DB::connection()->getPdo();
        $locker->beginTransaction();
        $locker->query('SELECT id FROM rooms WHERE id = '.(int) $this->physical->id.' FOR UPDATE')->fetchAll();

        $a = $this->startChild($this->physical, '14:00', '16:00', $m1);
        $b = $this->startChild($this->training, '16:00', '17:00', $m2);
        usleep(1_500_000);
        $locker->commit();

        $results = [$this->finish($a), $this->finish($b)];
        $this->assertSame([null, null], array_column($results, 'error'), json_encode($results));
        $this->assertSame(2, Booking::whereIn('room_id', [$this->physical->id, $this->training->id])->count());
    }
}
