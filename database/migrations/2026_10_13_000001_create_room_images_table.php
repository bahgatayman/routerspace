<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Room photo gallery: multiple images per room, stored on the public disk
     * (storage/app/public/room-images/{room_id}/...). One image per room may
     * be the cover (is_cover); sort_order drives gallery/thumbnail ordering.
     * Fully additive — no existing room/booking/session code reads this table.
     */
    public function up(): void
    {
        Schema::create('room_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('owners')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->string('path');
            $table->string('thumbnail_path')->nullable();
            $table->boolean('is_cover')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['room_id', 'sort_order']);
            $table->index(['room_id', 'is_cover']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_images');
    }
};
