<?php

namespace Tests\Feature;

use App\Models\Owner;
use App\Models\Plan;
use App\Models\Room;
use App\Models\Workspace;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * rooms.public_id: random UUID used by the public API instead of the internal
 * primary key. Generated automatically, unique, immutable, backfilled by the
 * migration for existing rooms, and protected on rollback.
 */
class RoomPublicIdTest extends TestCase
{
    use RefreshDatabase;

    /** Rolled back by path, not --step: later migrations exist after this one. */
    private const MIGRATION_PATH = 'database/migrations/2026_10_28_000001_add_public_id_to_rooms_table.php';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private function workspace(): Workspace
    {
        $this->seed(FeatureSeeder::class);
        $plan = Plan::create(['name' => 'Free', 'slug' => 'p-'.uniqid(), 'max_members' => 100, 'price_per_month' => 0, 'is_active' => true,
            'sort_order' => 1, 'features' => ['workspace'], 'max_workspaces' => 0, 'max_rooms' => 0, 'max_products' => 0]);
        $owner = Owner::create(['name' => 'Karim', 'email' => 'k'.uniqid().'@t.local', 'password' => 'secret123', 'business_name' => 'Biz',
            'plan_id' => $plan->id, 'is_active' => true, 'subscription_starts_at' => now(), 'subscription_expires_at' => now()->addMonth()]);
        $owner->enableFeature('workspace');

        return Workspace::create(['owner_id' => $owner->id, 'name' => 'Main', 'address' => '1 St', 'city' => 'Cairo']);
    }

    private function room(Workspace $ws, array $attrs = []): Room
    {
        return Room::create(array_merge(['owner_id' => $ws->owner_id, 'workspace_id' => $ws->id, 'name' => 'Room '.uniqid(),
            'type' => 'meeting', 'capacity' => 6, 'price_per_hour' => 100], $attrs));
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_28_000001_add_public_id_to_rooms_table.php');
    }

    public function test_column_is_unique_not_null_and_indexed(): void
    {
        $column = collect(Schema::getColumns('rooms'))->firstWhere('name', 'public_id');
        $this->assertNotNull($column);
        $this->assertFalse($column['nullable']);
        $this->assertTrue(collect(Schema::getIndexes('rooms'))->contains(fn ($i) => $i['columns'] === ['public_id'] && $i['unique']));
    }

    public function test_new_rooms_get_a_unique_uuid_independent_of_the_primary_key(): void
    {
        $ws = $this->workspace();
        $rooms = collect(range(1, 25))->map(fn () => $this->room($ws));

        foreach ($rooms as $room) {
            $this->assertMatchesRegularExpression(self::UUID, $room->public_id); // random v4, not derived from the id
        }
        $this->assertCount(25, $rooms->pluck('public_id')->unique());

        // Rooms created through the owner's real form get one too.
        $this->actingAs($ws->owner, 'owner')->post(route('rooms.store', $ws), ['name' => 'Via Form', 'type' => 'meeting', 'capacity' => 4, 'price_per_hour' => 80])
            ->assertSessionHasNoErrors();
        $this->assertMatchesRegularExpression(self::UUID, Room::where('name', 'Via Form')->value('public_id'));
    }

    public function test_public_id_cannot_be_mass_assigned_or_changed(): void
    {
        $ws = $this->workspace();
        $forged = '11111111-1111-4111-8111-111111111111';

        $room = $this->room($ws, ['public_id' => $forged]);
        $this->assertNotSame($forged, $room->public_id, 'Not mass-assignable.');
        $original = $room->public_id;

        $room->update(['public_id' => $forged, 'name' => 'Renamed']);
        $room->forceFill(['public_id' => $forged])->save();
        $room->refresh();
        $this->assertSame($original, $room->public_id, 'Immutable once set.');
        $this->assertSame('Renamed', $room->name);

        // The owner's edit form (rename, re-price, toggle) keeps it too.
        $this->actingAs($ws->owner, 'owner')->put(route('rooms.update', [$ws, $room]), [
            'name' => 'Board Room XL', 'type' => 'meeting', 'capacity' => 8, 'price_per_hour' => 150, 'public_id' => $forged,
        ])->assertSessionHasNoErrors();
        $this->actingAs($ws->owner, 'owner')->post(route('rooms.toggle', [$ws, $room]));
        $this->assertSame($original, $room->fresh()->public_id);
        $this->assertSame('Board Room XL', $room->fresh()->name);
    }

    public function test_migration_backfills_existing_rooms_without_touching_anything_else(): void
    {
        // Runs on its own temporary SQLite file through the real migrator (`migrate` /
        // `migrate:rollback`), outside this test's transaction — the same way production runs
        // it. (On SQLite a table rebuild inside an *outer* transaction can't switch foreign keys
        // off, which would cascade-delete dependent rows; the migrator path doesn't have that issue.)
        $file = tempnam(sys_get_temp_dir(), 'ls-probe');
        config(['database.connections.probe' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true]]);

        try {
            Artisan::call('migrate', ['--database' => 'probe', '--force' => true]);
            // Back to "before this migration" (no rooms yet, so the rollback guard allows it).
            Artisan::call('migrate:rollback', ['--database' => 'probe', '--path' => self::MIGRATION_PATH, '--force' => true]);
            $db = DB::connection('probe');
            $this->assertFalse($db->getSchemaBuilder()->hasColumn('rooms', 'public_id'));

            $now = now();
            $ownerId = $db->table('owners')->insertGetId(['name' => 'O', 'email' => 'o@probe.test', 'password' => 'x', 'business_name' => 'B', 'created_at' => $now, 'updated_at' => $now]);
            $wsId = $db->table('workspaces')->insertGetId(['owner_id' => $ownerId, 'name' => 'W', 'created_at' => $now, 'updated_at' => $now]);
            $roomA = $db->table('rooms')->insertGetId(['workspace_id' => $wsId, 'owner_id' => $ownerId, 'name' => 'A', 'type' => 'meeting', 'capacity' => 6, 'price_per_hour' => 120, 'is_available' => true, 'created_at' => $now, 'updated_at' => $now]);
            $db->table('rooms')->insert(['workspace_id' => $wsId, 'owner_id' => $ownerId, 'name' => 'B', 'type' => 'shared', 'capacity' => 10, 'price_per_hour' => 0, 'is_available' => false, 'created_at' => $now, 'updated_at' => $now]);
            $memberId = $db->table('hotspot_users')->insertGetId(['owner_id' => $ownerId, 'name' => 'M', 'phone' => '01012345678', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
            $db->table('bookings')->insert(['owner_id' => $ownerId, 'room_id' => $roomA, 'hotspot_user_id' => $memberId, 'booking_date' => $now->toDateString(),
                'start_time' => '10:00', 'end_time' => '11:00', 'total_hours' => 1, 'price_per_hour' => 120, 'total_price' => 120, 'status' => 'confirmed', 'created_at' => $now, 'updated_at' => $now]);
            $snapshot = fn () => $db->table('rooms')->orderBy('id')->get()->map(fn ($r) => collect((array) $r)->except(['public_id'])->all())->all();
            $before = $snapshot();

            Artisan::call('migrate', ['--database' => 'probe', '--force' => true]); // applies 2026_10_28 for real

            $ids = $db->table('rooms')->orderBy('id')->pluck('public_id');
            $this->assertCount(2, $ids->filter(fn ($id) => preg_match(self::UUID, (string) $id)), 'Every existing room got a UUID.');
            $this->assertCount(2, $ids->unique());
            $this->assertEquals($before, $snapshot(), 'Ownership, pricing and availability are unchanged.');
            $this->assertSame(1, $db->table('bookings')->where('room_id', $roomA)->count(), 'Bookings still point at the same room.');
            $this->assertSame([], $db->select('pragma foreign_key_check'), 'No foreign-key violations.');
        } finally {
            DB::purge('probe');
            @unlink($file);
        }
    }

    private const MIGRATION = '2026_10_28_000001_add_public_id_to_rooms_table';

    /**
     * Runs $test against a fresh temporary SQLite file, migrated to just before the public_id
     * migration and holding three rooms (plus a booking on the first). Same isolation as the
     * backfill test above: real migrator, never the development database.
     */
    private function onProbeBeforeMigration(callable $test): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ls-probe');
        config(['database.connections.probe' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true]]);
        // The migrator only switches its default connection back after a *successful* run.
        $default = DB::getDefaultConnection();

        try {
            Artisan::call('migrate', ['--database' => 'probe', '--force' => true]);
            Artisan::call('migrate:rollback', ['--database' => 'probe', '--path' => self::MIGRATION_PATH, '--force' => true]);
            $db = DB::connection('probe');
            $now = now();
            $ownerId = $db->table('owners')->insertGetId(['name' => 'O', 'email' => 'o@probe.test', 'password' => 'x', 'business_name' => 'B', 'created_at' => $now, 'updated_at' => $now]);
            $wsId = $db->table('workspaces')->insertGetId(['owner_id' => $ownerId, 'name' => 'W', 'created_at' => $now, 'updated_at' => $now]);
            $rooms = collect(['A', 'B', 'C'])->map(fn ($name) => $db->table('rooms')->insertGetId(['workspace_id' => $wsId, 'owner_id' => $ownerId, 'name' => $name,
                'type' => 'meeting', 'capacity' => 4, 'price_per_hour' => 100, 'is_available' => true, 'created_at' => $now, 'updated_at' => $now]));
            $memberId = $db->table('hotspot_users')->insertGetId(['owner_id' => $ownerId, 'name' => 'M', 'phone' => '01012345678', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
            $db->table('bookings')->insert(['owner_id' => $ownerId, 'room_id' => $rooms[0], 'hotspot_user_id' => $memberId, 'booking_date' => $now->toDateString(),
                'start_time' => '10:00', 'end_time' => '11:00', 'total_hours' => 1, 'price_per_hour' => 100, 'total_price' => 100, 'status' => 'confirmed', 'created_at' => $now, 'updated_at' => $now]);

            $test($db, $rooms->all());
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('probe');
            @unlink($file);
        }
    }

    private function migrateProbe(): void
    {
        Artisan::call('migrate', ['--database' => 'probe', '--force' => true]);
    }

    private function assertFinalState($db): void
    {
        $column = collect($db->getSchemaBuilder()->getColumns('rooms'))->firstWhere('name', 'public_id');
        $this->assertFalse($column['nullable'], 'NOT NULL enforced.');
        $unique = collect($db->getSchemaBuilder()->getIndexes('rooms'))->filter(fn ($i) => $i['unique'] && $i['columns'] === ['public_id']);
        $this->assertCount(1, $unique, 'Exactly one unique index on public_id.');
        $this->assertTrue($db->table('migrations')->where('migration', self::MIGRATION)->exists(), 'Migration recorded.');
        $ids = $db->table('rooms')->pluck('public_id');
        $this->assertCount(3, $ids->filter(fn ($id) => preg_match(self::UUID, (string) $id))->unique());
        $this->assertSame(1, $db->table('bookings')->count(), 'Bookings untouched.');
        $this->assertSame([], $db->select('pragma foreign_key_check'));
    }

    public function test_rerun_after_a_stop_mid_backfill_keeps_already_issued_ids(): void
    {
        $this->onProbeBeforeMigration(function ($db, array $rooms) {
            // State: column added, first room already backfilled, others not; nothing else done.
            $db->getSchemaBuilder()->table('rooms', fn ($t) => $t->string('public_id', 36)->nullable());
            $issued = '0b7a2f4e-6c1d-4e8a-9f3b-2d5c7e9a1b4f';
            $db->table('rooms')->where('id', $rooms[0])->update(['public_id' => $issued]);

            $this->migrateProbe();

            $this->assertSame($issued, $db->table('rooms')->where('id', $rooms[0])->value('public_id'), 'An already-issued id is never replaced.');
            $this->assertFinalState($db);
        });
    }

    public function test_rerun_when_everything_but_the_migrations_row_was_applied(): void
    {
        $this->onProbeBeforeMigration(function ($db) {
            $this->migrateProbe();
            $before = $db->table('rooms')->orderBy('id')->pluck('public_id', 'id')->all();
            // State: DDL completed, but the run died before the migration was recorded.
            $db->table('migrations')->where('migration', self::MIGRATION)->delete();

            $this->migrateProbe(); // previously failed here with "index already exists"

            $this->assertSame($before, $db->table('rooms')->orderBy('id')->pluck('public_id', 'id')->all(), 'Ids unchanged by the retry.');
            $this->assertFinalState($db);

            // And a third run is a no-op.
            $this->migrateProbe();
            $this->assertSame($before, $db->table('rooms')->orderBy('id')->pluck('public_id', 'id')->all());
        });
    }

    public function test_rerun_accepts_an_existing_unique_index_under_another_name(): void
    {
        $this->onProbeBeforeMigration(function ($db, array $rooms) {
            // State: column filled and a unique index added by hand during a recovery, still nullable.
            $db->getSchemaBuilder()->table('rooms', fn ($t) => $t->string('public_id', 36)->nullable());
            foreach ($rooms as $id) {
                $db->table('rooms')->where('id', $id)->update(['public_id' => (string) Str::uuid()]);
            }
            $db->getSchemaBuilder()->table('rooms', fn ($t) => $t->unique('public_id', 'rooms_public_id_recovered'));
            $before = $db->table('rooms')->orderBy('id')->pluck('public_id', 'id')->all();

            $this->migrateProbe();

            $this->assertSame($before, $db->table('rooms')->orderBy('id')->pluck('public_id', 'id')->all());
            $this->assertFinalState($db);
        });
    }

    public function test_duplicate_ids_stop_the_migration_without_regenerating_them(): void
    {
        $this->onProbeBeforeMigration(function ($db, array $rooms) {
            $db->getSchemaBuilder()->table('rooms', fn ($t) => $t->string('public_id', 36)->nullable());
            $dup = '5e1c9a3b-7d2f-4b6e-8a4c-1f3e5d7b9c2a';
            $db->table('rooms')->whereIn('id', [$rooms[0], $rooms[1]])->update(['public_id' => $dup]);

            try {
                $this->migrateProbe();
                $this->fail('Duplicates must stop the migration.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('duplicate', $e->getMessage());
            }

            $this->assertSame(2, $db->table('rooms')->where('public_id', $dup)->count(), 'Duplicates left for a human to resolve, not regenerated.');
            $this->assertFalse($db->table('migrations')->where('migration', self::MIGRATION)->exists(), 'Not recorded as done.');
            $this->assertFalse(collect($db->getSchemaBuilder()->getIndexes('rooms'))->contains(fn ($i) => $i['columns'] === ['public_id']));

            // After a manual fix, the retry completes and keeps the surviving id.
            $db->table('rooms')->where('id', $rooms[1])->update(['public_id' => null]);
            $this->migrateProbe();
            $this->assertSame($dup, $db->table('rooms')->where('id', $rooms[0])->value('public_id'));
            $this->assertFinalState($db);
        });
    }

    public function test_confirmed_rollback_works_with_a_differently_named_index(): void
    {
        $this->onProbeBeforeMigration(function ($db, array $rooms) {
            $db->getSchemaBuilder()->table('rooms', fn ($t) => $t->string('public_id', 36)->nullable());
            foreach ($rooms as $id) {
                $db->table('rooms')->where('id', $id)->update(['public_id' => (string) Str::uuid()]);
            }
            $db->getSchemaBuilder()->table('rooms', fn ($t) => $t->unique('public_id', 'rooms_public_id_recovered'));
            $this->migrateProbe();

            putenv('ALLOW_DROP_ROOM_PUBLIC_IDS=1');
            try {
                Artisan::call('migrate:rollback', ['--database' => 'probe', '--path' => self::MIGRATION_PATH, '--force' => true]);
            } finally {
                putenv('ALLOW_DROP_ROOM_PUBLIC_IDS');
            }

            $this->assertFalse($db->getSchemaBuilder()->hasColumn('rooms', 'public_id'));
            $this->assertSame(3, $db->table('rooms')->count(), 'Rooms kept.');
            $this->assertSame(1, $db->table('bookings')->count(), 'Bookings kept.');
        });
    }

    public function test_rollback_refuses_to_discard_identifiers_unless_confirmed(): void
    {
        $ws = $this->workspace();
        $this->room($ws);

        try {
            $this->migration()->down(); // ALLOW_DROP_ROOM_PUBLIC_IDS is not set
            $this->fail('Rollback should refuse while rooms have public ids.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ALLOW_DROP_ROOM_PUBLIC_IDS=1', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('rooms', 'public_id'), 'Nothing was dropped.');
    }
}
