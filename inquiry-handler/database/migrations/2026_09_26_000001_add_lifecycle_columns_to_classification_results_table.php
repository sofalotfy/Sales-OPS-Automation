<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lifecycle columns on the classification log (feature 013, data-model.md):
     *
     * - `campaign_id` + `lead_id` + `UNIQUE(campaign_id, lead_id)` make the row
     *   the idempotency guard for CRM re-submissions (R7). Postgres treats NULLs
     *   as distinct, so sync-path rows (no campaign pair) never collide with
     *   queued rows.
     * - `status` (queued|processing|succeeded|failed) + `error` give the row a
     *   lifecycle. Existing and sync rows are `succeeded` (backfilled here).
     * - The result columns are relaxed to nullable so a queued row can exist
     *   before the worker writes its completion fields (R5). They are still
     *   written exactly once, at completion.
     */
    public function up(): void
    {
        Schema::table('classification_results', function (Blueprint $table) {
            $table->json('retrieved_context')->nullable()->change();
            $table->json('factor_scores')->nullable()->change();
            $table->json('dropped_factors')->nullable()->change();
            $table->decimal('final_score', 5, 2)->nullable()->change();
            $table->string('classification', 20)->nullable()->change();

            $table->string('campaign_id', 255)->nullable()->after('classification');
            $table->string('lead_id', 255)->nullable()->after('campaign_id');
            $table->string('status', 20)->nullable()->after('lead_id');
            $table->text('error')->nullable()->after('status');
        });

        // Backfill pre-existing (sync-path) rows as completed runs so they keep
        // appearing in the dashboard's finished list after the feature ships.
        DB::table('classification_results')->whereNull('status')->update(['status' => 'succeeded']);

        // Idempotency guard for the CRM ingest (R7). NULLs are distinct in
        // Postgres, so sync rows (both NULL) never conflict with each other.
        Schema::table('classification_results', function (Blueprint $table) {
            $table->unique(['campaign_id', 'lead_id']);
        });
    }

    public function down(): void
    {
        Schema::table('classification_results', function (Blueprint $table) {
            $table->dropUnique(['campaign_id', 'lead_id']);
            $table->dropColumn(['campaign_id', 'lead_id', 'status', 'error']);

            $table->json('retrieved_context')->nullable(false)->change();
            $table->json('factor_scores')->nullable(false)->change();
            $table->json('dropped_factors')->nullable(false)->change();
            $table->decimal('final_score', 5, 2)->nullable(false)->change();
            $table->string('classification', 20)->nullable(false)->change();
        });
    }
};
