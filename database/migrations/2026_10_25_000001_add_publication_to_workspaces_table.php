<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public-website publication state for a workspace. Deliberately separate from
 * the operational `is_active` flag: publication only controls visibility on the
 * future public site. Existing workspaces start as `draft` — never published.
 *
 * slug is assigned once (on first submission) and kept when the name changes,
 * so public URLs stay stable. It is an address, never an authorization key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('slug', 140)->nullable()->unique();
            $table->string('publication_status', 20)->default('draft')->index();
            $table->timestamp('published_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropIndex(['publication_status']);
            $table->dropColumn(['slug', 'publication_status', 'published_at']);
        });
    }
};
