<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Onboarding state, per owner (the tenant):
 *  - onboarding_welcomed_at: when the first-time Welcome Wizard was finished or
 *    closed. NULL = not seen yet → it opens once on the dashboard.
 *  - onboarding_checklist_hidden_at: when the owner hid the dashboard setup
 *    checklist (they can reopen it; progress is never stored here — it is always
 *    derived from real data by App\Services\OnboardingService).
 *
 * Existing owners are back-filled as "already welcomed" with the checklist
 * hidden, so deploying this never launches onboarding for current customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            $table->timestamp('onboarding_welcomed_at')->nullable();
            $table->timestamp('onboarding_checklist_hidden_at')->nullable();
        });

        DB::table('owners')->update([
            'onboarding_welcomed_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)'),
            'onboarding_checklist_hidden_at' => DB::raw('CURRENT_TIMESTAMP'),
        ]);
    }

    public function down(): void
    {
        Schema::table('owners', function (Blueprint $table) {
            $table->dropColumn(['onboarding_welcomed_at', 'onboarding_checklist_hidden_at']);
        });
    }
};
