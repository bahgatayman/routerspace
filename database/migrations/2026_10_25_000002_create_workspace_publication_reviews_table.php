<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only history of a workspace's publication lifecycle: every submit,
 * withdraw, approve, reject and unpublish, with who did it and why. Rows are
 * never updated or deleted on resubmission. Actor names are snapshotted so the
 * history stays readable if an account is later removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_publication_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('owners')->cascadeOnDelete();
            $table->string('action', 20);           // submitted | withdrawn | approved | rejected | unpublished
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->string('actor_type', 10);       // owner | admin
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 160)->nullable();
            $table->text('reason')->nullable();     // required for rejected / unpublished
            $table->json('readiness')->nullable();  // missing requirement keys at the time of the action
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_publication_reviews');
    }
};
