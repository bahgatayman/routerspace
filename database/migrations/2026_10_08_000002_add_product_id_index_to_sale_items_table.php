<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ProductAnalyticsService groups every query by sale_items.product_id (on
 * top of the existing join through sale_id) — a composite index keeps the
 * existing sale_id join-order usefulness (still relied on by
 * SalesService::addItem()/removeItem()) while giving the GROUP BY a
 * product_id-ordered range to scan instead of a full sort once a catalog's
 * sale history grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->index(['sale_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropIndex(['sale_id', 'product_id']);
        });
    }
};
