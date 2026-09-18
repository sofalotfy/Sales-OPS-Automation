<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive web-research columns on the append-only classification log:
     * every screened inquiry carries the web-research step's outcome
     * (accept|decline|indeterminate), its reason, and a JSON payload holding
     * the lookup criteria + the company/person findings the provider returned.
     * Less restrictive than feature 006's table, so an existing store
     * migrates cleanly.
     */
    public function up(): void
    {
        Schema::table('classification_results', function (Blueprint $table) {
            $table->string('web_research_outcome', 20)->nullable();
            $table->text('web_research_reason')->nullable();
            $table->json('web_research')->nullable();
        });

        // Postgres enforces the outcome value set at the store level; SQLite's
        // ALTER TABLE cannot add CHECK constraints (test store skips it, and
        // the application layer already restricts to the three values via
        // App\WebResearch\WebResearchVerdict).
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'alter table classification_results add constraint '
                .'classification_results_web_research_outcome_in check '
                ."(web_research_outcome is null or web_research_outcome in ('accept', 'decline', 'indeterminate'))",
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'alter table classification_results drop constraint '
                .'classification_results_web_research_outcome_in',
            );
        }

        Schema::table('classification_results', function (Blueprint $table) {
            $table->dropColumn(['web_research_outcome', 'web_research_reason', 'web_research']);
        });
    }
};