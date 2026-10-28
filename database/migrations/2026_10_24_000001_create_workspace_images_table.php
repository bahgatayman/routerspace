<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photos of a workspace itself (separate from room photos). Same shape as
 * room_images: files live on the `public` disk under workspace-images/ (the row
 * records each file's path);
 * exactly one row per workspace is primary while it has any images.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('owners')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('path');
            $table->string('thumbnail_path')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'sort_order']);
            $table->index(['workspace_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_images');
    }
};
