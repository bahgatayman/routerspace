<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Open Session bookings for exclusive rooms: a booking that starts now and
 * has no end time until checkout. Requires end_time to be nullable, plus the
 * same billing_unit/billing_buffer_minutes/pricing_snapshot snapshot columns
 * shared_sessions already has, so RoomPricingService::quoteOpenBooking() can
 * price an open booking the same way quoteSession() prices an open session —
 * frozen at creation, never re-read live from the room at checkout.
 *
 * No doctrine/dbal is installed, so a plain ->nullable()->change() isn't
 * available on SQLite. Same table-rebuild technique as
 * 2026_08_18_000001_convert_booking_status_to_string.php: named-column
 * INSERT (not SELECT *, since SQLite never honors after() on an ALTER-added
 * column — the live column order doesn't match declaration order across
 * this table's migration history), PRAGMA foreign_keys off/on since
 * shared_sessions.booking_id and sales.booking_id both reference this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE bookings MODIFY COLUMN end_time TIME NULL');
            DB::statement('ALTER TABLE bookings ADD COLUMN billing_unit VARCHAR(255) NULL AFTER payment_method');
            DB::statement('ALTER TABLE bookings ADD COLUMN billing_buffer_minutes SMALLINT UNSIGNED NULL AFTER billing_unit');
            DB::statement('ALTER TABLE bookings ADD COLUMN pricing_snapshot JSON NULL AFTER billing_buffer_minutes');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');

            Schema::create('bookings_new', function (Blueprint $table) {
                $table->id();
                $table->foreignId('owner_id')->constrained('owners')->cascadeOnDelete();
                $table->foreignId('hotspot_user_id')->nullable()->constrained('hotspot_users')->nullOnDelete();
                $table->unsignedInteger('party_size')->default(1);
                $table->unsignedInteger('checked_in_party_size')->nullable();
                $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
                $table->foreignId('room_plan_id')->nullable()->constrained('room_plans')->nullOnDelete();
                $table->foreignId('room_pricing_profile_id')->nullable()->constrained('room_pricing_profiles')->nullOnDelete();
                $table->string('pricing_profile_name', 60)->nullable();
                $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
                $table->decimal('discount_total', 10, 2)->default(0);
                $table->unsignedInteger('guest_count')->nullable();
                $table->string('pricing_note')->nullable();
                $table->date('booking_date');
                $table->time('start_time');
                $table->time('end_time')->nullable();
                $table->decimal('price_per_hour', 8, 2);
                $table->decimal('total_hours', 5, 2);
                $table->decimal('total_price', 10, 2);
                $table->decimal('amount_paid', 10, 2)->default(0);
                $table->string('payment_status', 16)->default('unpaid');
                $table->string('payment_method', 16)->default('cash');
                $table->foreignId('member_package_id')->nullable()->constrained('member_packages')->nullOnDelete();
                $table->string('status')->default('pending');
                $table->string('notes')->nullable();
                // New for Open Session — frozen at creation, mirroring shared_sessions' own columns.
                $table->string('billing_unit')->nullable();
                $table->unsignedSmallInteger('billing_buffer_minutes')->nullable();
                $table->json('pricing_snapshot')->nullable();
                $table->timestamps();

                // Explicit names: the live `bookings` table's own indexes are
                // still physically named bookings_new_*_index (SQLite index
                // names are global and a RENAME TABLE never renames them) —
                // a leftover from 2026_08_18_000001's own rebuild. Reusing
                // the auto-generated name here would collide with those
                // still-live names, so this rebuild (and any future one)
                // uses distinct names that don't contain "_new_".
                $table->index(['room_id', 'booking_date'], 'bookings_room_booking_date_idx');
                $table->index(['owner_id', 'booking_date'], 'bookings_owner_booking_date_idx');
            });

            DB::statement('
                INSERT INTO bookings_new (
                    id, owner_id, hotspot_user_id, party_size, checked_in_party_size, room_id,
                    room_plan_id, room_pricing_profile_id, pricing_profile_name, coupon_id,
                    discount_total, guest_count, pricing_note, booking_date, start_time, end_time,
                    price_per_hour, total_hours, total_price, amount_paid, payment_status,
                    payment_method, member_package_id, status, notes, created_at, updated_at
                )
                SELECT
                    id, owner_id, hotspot_user_id, party_size, checked_in_party_size, room_id,
                    room_plan_id, room_pricing_profile_id, pricing_profile_name, coupon_id,
                    discount_total, guest_count, pricing_note, booking_date, start_time, end_time,
                    price_per_hour, total_hours, total_price, amount_paid, payment_status,
                    payment_method, member_package_id, status, notes, created_at, updated_at
                FROM bookings
            ');
            Schema::drop('bookings');
            Schema::rename('bookings_new', 'bookings');

            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * Only safe to roll back while no row holds billing_unit/
     * billing_buffer_minutes/pricing_snapshot or a null end_time yet — same
     * assumption the template migration's down() makes about its own values.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE bookings DROP COLUMN pricing_snapshot');
            DB::statement('ALTER TABLE bookings DROP COLUMN billing_buffer_minutes');
            DB::statement('ALTER TABLE bookings DROP COLUMN billing_unit');
            DB::statement('ALTER TABLE bookings MODIFY COLUMN end_time TIME NOT NULL');

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');

            Schema::create('bookings_old', function (Blueprint $table) {
                $table->id();
                $table->foreignId('owner_id')->constrained('owners')->cascadeOnDelete();
                $table->foreignId('hotspot_user_id')->nullable()->constrained('hotspot_users')->nullOnDelete();
                $table->unsignedInteger('party_size')->default(1);
                $table->unsignedInteger('checked_in_party_size')->nullable();
                $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
                $table->foreignId('room_plan_id')->nullable()->constrained('room_plans')->nullOnDelete();
                $table->foreignId('room_pricing_profile_id')->nullable()->constrained('room_pricing_profiles')->nullOnDelete();
                $table->string('pricing_profile_name', 60)->nullable();
                $table->foreignId('coupon_id')->nullable()->constrained('coupons')->nullOnDelete();
                $table->decimal('discount_total', 10, 2)->default(0);
                $table->unsignedInteger('guest_count')->nullable();
                $table->string('pricing_note')->nullable();
                $table->date('booking_date');
                $table->time('start_time');
                $table->time('end_time');
                $table->decimal('price_per_hour', 8, 2);
                $table->decimal('total_hours', 5, 2);
                $table->decimal('total_price', 10, 2);
                $table->decimal('amount_paid', 10, 2)->default(0);
                $table->string('payment_status', 16)->default('unpaid');
                $table->string('payment_method', 16)->default('cash');
                $table->foreignId('member_package_id')->nullable()->constrained('member_packages')->nullOnDelete();
                $table->string('status')->default('pending');
                $table->string('notes')->nullable();
                $table->timestamps();

                $table->index(['room_id', 'booking_date'], 'bookings_room_booking_date_idx_rb');
                $table->index(['owner_id', 'booking_date'], 'bookings_owner_booking_date_idx_rb');
            });

            DB::statement('
                INSERT INTO bookings_old (
                    id, owner_id, hotspot_user_id, party_size, checked_in_party_size, room_id,
                    room_plan_id, room_pricing_profile_id, pricing_profile_name, coupon_id,
                    discount_total, guest_count, pricing_note, booking_date, start_time, end_time,
                    price_per_hour, total_hours, total_price, amount_paid, payment_status,
                    payment_method, member_package_id, status, notes, created_at, updated_at
                )
                SELECT
                    id, owner_id, hotspot_user_id, party_size, checked_in_party_size, room_id,
                    room_plan_id, room_pricing_profile_id, pricing_profile_name, coupon_id,
                    discount_total, guest_count, pricing_note, booking_date, start_time, end_time,
                    price_per_hour, total_hours, total_price, amount_paid, payment_status,
                    payment_method, member_package_id, status, notes, created_at, updated_at
                FROM bookings
            ');
            Schema::drop('bookings');
            Schema::rename('bookings_old', 'bookings');

            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};
