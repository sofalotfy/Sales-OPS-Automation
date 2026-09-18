<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive scope-gate columns on the append-only classification log
     * (data-model.md / FR-012): every screened inquiry carries its scope-check
     * outcome (accept|decline|indeterminate), the scope reasoning, and, for
     * declines only, the visitor-facing refusal copy. Less restrictive than
     * feature 006's table, so an existing store migrates cleanly.
     */
    public function up(): void
    {
        Schema::table('classification_results', function (Blueprint $table) {
            $table->string('scope_check_outcome', 20)->nullable();
            $table->text('scope_check_reason')->nullable();
            $table->text('refusal')->nullable();
        });

        // Postgres enforces the outcome value set at the store level; SQLite's
        // ALTER TABLE cannot add CHECK constraints (test store skips it, and
        // the application layer already restricts to the three values via
        // App\ScopeGate\ScopeVerdict).
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'alter table classification_results add constraint '
                .'classification_results_scope_check_outcome_in check '
                ."(scope_check_outcome is null or scope_check_outcome in ('accept', 'decline', 'indeterminate'))",
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'alter table classification_results drop constraint '
                .'classification_results_scope_check_outcome_in',
            );
        }

        Schema::table('classification_results', function (Blueprint $table) {
            $table->dropColumn(['scope_check_outcome', 'scope_check_reason', 'refusal']);
        });
    }
};
