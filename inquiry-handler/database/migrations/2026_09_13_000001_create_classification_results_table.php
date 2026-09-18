<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only classification log (data-model.md / FR-009): one row per
     * classified inquiry, immutable after insert so the audit trail can
     * reconstruct how a decision was reached (research R10).
     */
    public function up(): void
    {
        Schema::create('classification_results', function (Blueprint $table) {
            $table->id();
            $table->text('inquiry_message');
            $table->string('name', 255)->nullable();
            $table->string('email', 255)->nullable();
            $table->json('retrieved_context');
            $table->json('factor_scores');
            $table->json('dropped_factors');
            $table->decimal('final_score', 5, 2);
            $table->string('classification', 20);
            $table->text('reasoning')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classification_results');
    }
};