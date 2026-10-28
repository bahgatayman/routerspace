<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * workspaces.description was created as VARCHAR(255) while the create/edit
 * forms validate up to 1000 characters, so a long description failed to save
 * on MySQL. Widening to TEXT keeps every existing value as-is (VARCHAR → TEXT
 * is lossless). Forward-only fix: the original create migration is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->text('description')->nullable()->change();
        });
    }

    /**
     * Rolling back would truncate (or, on strict MySQL, fail on) descriptions
     * longer than 255 characters — refuse instead of silently losing data.
     */
    public function down(): void
    {
        $tooLong = DB::table('workspaces')->whereRaw('LENGTH(description) > 255')->count();
        if ($tooLong > 0) {
            throw new RuntimeException("Cannot roll back: {$tooLong} workspace description(s) are longer than 255 characters. Shorten them first.");
        }

        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('description')->nullable()->change();
        });
    }
};
