<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform feedback from the public /feedback page. Anonymous by default:
 * owner_id / staff_id are only set from a verified session (never from the
 * form), contact fields are optional. Review fields belong to Super Admins
 * and never overwrite what the visitor submitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('rating');
            $table->string('category', 20);
            $table->string('product_area', 30)->nullable();
            $table->text('message');
            $table->text('details')->nullable();          // feature: problem it solves · bug: what happened vs expected
            $table->string('contact_name', 100)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('locale', 5)->default('en');   // language it was written in

            $table->foreignId('owner_id')->nullable()->constrained('owners')->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();

            $table->string('status', 20)->default('new');
            $table->text('admin_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['category', 'created_at']);
            $table->index('product_area');
            $table->index('rating');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
