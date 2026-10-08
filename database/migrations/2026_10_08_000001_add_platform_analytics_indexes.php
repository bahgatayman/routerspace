<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Super Admin analytics query across ALL businesses by date. Every
     * existing index on these tables leads with owner_id, which those
     * cross-tenant range scans can't use — these lead with the filtered
     * columns instead.
     */
    public function up(): void
    {
        Schema::table('subscriptions', fn (Blueprint $t) => $t->index('created_at', 'subscriptions_created_at_index'));
        Schema::table('bookings', fn (Blueprint $t) => $t->index(['status', 'booking_date'], 'bookings_status_date_index'));
        Schema::table('sales', fn (Blueprint $t) => $t->index(['status', 'sold_at'], 'sales_status_sold_at_index'));
        Schema::table('owners', fn (Blueprint $t) => $t->index('created_at', 'owners_created_at_index'));
    }

    public function down(): void
    {
        Schema::table('owners', fn (Blueprint $t) => $t->dropIndex('owners_created_at_index'));
        Schema::table('sales', fn (Blueprint $t) => $t->dropIndex('sales_status_sold_at_index'));
        Schema::table('bookings', fn (Blueprint $t) => $t->dropIndex('bookings_status_date_index'));
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropIndex('subscriptions_created_at_index'));
    }
};
