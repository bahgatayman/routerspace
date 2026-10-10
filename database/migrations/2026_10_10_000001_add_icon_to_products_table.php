<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // A predefined icon key (see Product::ICONS), never arbitrary markup —
            // validated against that whitelist in ProductController. Null means
            // "no icon chosen yet"; callers fall back via Product::iconName().
            $table->string('icon', 32)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};
