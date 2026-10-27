<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingSeries;
use App\Models\HotspotUser;
use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\Workspace;
use Carbon\Carbon;
use Database\Seeders\FeatureSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Concurrent repeating bookings on MySQL/MariaDB only (row locks are a no-op
 * on SQLite). Two real child processes (tests/Concurrency/series_child.php)
 * confirm overlapping series on two USES of one physical room while this
 * test holds the physical room's row lock: both must queue on it, and every
 * date must end up with exactly one booking. Run against a throwaway DB:
 *
 *   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3399 DB_DATABASE=… php artisan test --group=mysql
 */
#[Group('mysql')]
class RecurringBookingConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    private Owner $owner;

    private Room $physical;

    private Room $use;

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Needs MySQL/MariaDB: row locks are a no-op on SQLite.');
        }
        $this->seed(FeatureSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $plan = Plan::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['workspace', 'booking'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);
        $this->owner = Owner::create(['name' => 'Owner', 'email' => 'o'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Space',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now()->subDay(), 'subscription_expires_at' => now()->addYear()]);
        foreach ($plan->features as $key) {
            $this->owner->enableFeature($key);
        }
        $ws = Workspace::create(['owner_id' => $this->owner->id, 'name' => 'Main']);
        $this->physical = Room::create(['owner_id' => $this->owner->id, 'workspace_id' => $ws->id, 'name' => 'Studio A', 'type' => 'meeting', 'capacity' => 8, 'price_per_hour' => 100]);
        $this->use = new Room(['owner_id' => $this->owner->id, 'workspace_id' => $ws->id, 'name' => 'Studio B', 'type' => 'studio', 'capacity' => 8, 'price_per_hour' => 150]);
        $this->use->physical_room_id = $this->physical->id;
        $this->use->save();
    }

    private function payload(Room $room, HotspotUser $member, string $start, string $end, array $dates): string
    {
        $file = tempnam(sys_get_temp_dir(), 'series');
        file_put_contents($file, json_encode([
            'room_id' => $room->id, 'hotspot_user_id' => $member->id,
            'booking_date' => $dates[0], 'start_time' => $start, 'end_time' => $end,
            'repeat_frequency' => 'weekly', 'repeat_weekdays' => [Carbon::parse($dates[0])->dayOfWeek],
            'repeat_end' => 'count', 'repeat_count' => count($dates),
            'occurrences' => array_map(fn ($d) => ['date' => $d, 'choice' => "{$start}-{$end}"], $dates),
            'request_token' => (string) Str::uuid(),
        ]));

        return $file;
    }

    private function startChild(string $payloadFile): array
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
        $process = proc_open([PHP_BINARY, base_path('tests/Concurrency/series_child.php'), (string) $this->owner->id, $payloadFile],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
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
        $this->assertSame(0, proc_close($process), "child failed: $err $out");
        $decoded = json_decode(trim(collect(explode("\n", trim($out)))->last()), true);
        $this->assertIsArray($decoded, "child output: $out $err");

        return $decoded;
    }

    public function test_two_overlapping_series_on_two_uses_queue_on_one_lock_and_each_date_is_booked_once(): void
    {
        $dates = array_map(fn ($w) => Carbon::tomorrow()->addWeeks($w)->toDateString(), [0, 1, 2]);
        // Members first: rows created inside the lock transaction would be invisible to the children.
        $a = $this->payload($this->physical, HotspotUser::create(['owner_id' => $this->owner->id, 'name' => 'A', 'phone' => '0101'.rand(1000000, 9999999), 'password' => 'pass1234']), '10:00', '12:00', $dates);
        $b = $this->payload($this->use, HotspotUser::create(['owner_id' => $this->owner->id, 'name' => 'B', 'phone' => '0102'.rand(1000000, 9999999), 'password' => 'pass1234']), '11:00', '13:00', $dates);

        $locker = DB::connection()->getPdo();
        $locker->beginTransaction();
        $locker->query('SELECT id FROM rooms WHERE id = '.(int) $this->physical->id.' FOR UPDATE')->fetchAll();

        $childA = $this->startChild($a);
        $childB = $this->startChild($b);
        usleep(3_000_000);
        $this->assertTrue(proc_get_status($childA[0])['running'], 'Series on the physical room waits for its lock.');
        $this->assertTrue(proc_get_status($childB[0])['running'], 'Series on ANOTHER use waits for the same lock.');

        $locker->commit();
        $results = [$this->finish($childA), $this->finish($childB)];

        foreach ($dates as $date) {
            $this->assertSame(1, Booking::whereIn('room_id', [$this->physical->id, $this->use->id])->whereDate('booking_date', $date)->count(),
                "Exactly one booking on $date: ".json_encode($results));
        }
        // The losing dates are reported, never dropped silently: listed on a kept
        // series, or — when a request booked no date at all — refused as a whole
        // (that series isn't kept).
        $refused = collect($results)->filter(fn ($r) => str_ends_with((string) $r['location'], '/bookings/create'))->count();
        $this->assertSame(2 - $refused, BookingSeries::count(), json_encode($results));
        $this->assertSame(3, BookingSeries::all()->sum(fn ($s) => count($s->unresolved ?? [])) + 3 * $refused, json_encode($results));
        $this->assertSame(0, BookingSeries::all()->filter(fn ($s) => Booking::where('booking_series_id', $s->id)->doesntExist())->count(), 'No empty series.');
        @unlink($a);
        @unlink($b);
    }
}
