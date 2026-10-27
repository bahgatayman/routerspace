<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring bookings: a booking_series holds the repeat rule (daily, or
 * weekly on chosen weekdays; until a date or for N dates) and what every
 * occurrence books (room/use, customer, time, party, pricing option). Each
 * occurrence stays a real `bookings` row — status, payment, price and history
 * are its own — linked back by bookings.booking_series_id (nullable: one-time
 * bookings never have a series).
 *
 * Deleting a series never deletes bookings (null on delete); series are
 * stopped, not deleted, in the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('booking_series')) {
            Schema::create('booking_series', function (Blueprint $table) {
                $table->id();
                $table->foreignId('owner_id')->constrained()->cascadeOnDelete();
                $table->foreignId('room_id')->constrained()->cascadeOnDelete();
                $table->foreignId('hotspot_user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('room_pricing_profile_id')->nullable()->constrained()->nullOnDelete();

                $table->string('frequency', 16);            // daily | weekly
                $table->json('weekdays')->nullable();       // weekly: [0..6], 0 = Sunday (Carbon dayOfWeek)
                $table->date('starts_on');
                $table->date('ends_on')->nullable();        // end by date …
                $table->unsignedInteger('occurrence_limit')->nullable(); // … or after N dates
                $table->time('start_time');
                $table->time('end_time');
                $table->unsignedInteger('party_size')->default(1);
                $table->unsignedInteger('guest_count')->nullable();
                $table->string('notes', 500)->nullable();

                // The price agreed when the series was made (RoomPricingService
                // quote for one date): every later date is booked at it, so a
                // change to the room's prices never reprices the series. Only
                // an explicit series change replaces it.
                $table->decimal('price_per_hour', 10, 2)->nullable();
                $table->decimal('occurrence_price', 10, 2)->nullable();
                $table->string('pricing_note', 255)->nullable();
                // upfront: the customer owes the whole series; per_session: each date on its own.
                // Payments themselves are always recorded on the dates (bookings.amount_paid).
                $table->string('payment_plan', 16)->default('per_session');
                // The hour package the owner chose to cover dates with (whole dates, while it lasts).
                $table->foreignId('member_package_id')->nullable()->constrained('member_packages')->nullOnDelete();

                $table->string('status', 16)->default('active'); // active | stopped
                $table->timestamp('stopped_at')->nullable();
                // Rule dates up to here have been handled (booked, skipped or
                // reported); later ones are added in batches (bookings:extend-series).
                $table->date('generated_through')->nullable();
                // Dates that could not be booked (conflicts at confirmation or
                // when a later batch was added), kept until resolved/dismissed.
                $table->json('unresolved')->nullable();
                // One per review screen: a second submit of the same review
                // (double click, two tabs) can never create a second series.
                $table->uuid('request_token')->nullable()->unique();
                $table->timestamps();

                $table->index(['owner_id', 'status']);
            });
        }

        if (! Schema::hasColumn('bookings', 'booking_series_id')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->foreignId('booking_series_id')->nullable()->after('room_id')
                    ->constrained('booking_series')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bookings', 'booking_series_id')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropConstrainedForeignId('booking_series_id');
            });
        }
        Schema::dropIfExists('booking_series');
    }
};
