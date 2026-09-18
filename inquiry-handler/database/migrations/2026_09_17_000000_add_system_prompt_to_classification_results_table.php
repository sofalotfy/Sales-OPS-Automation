<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive system-prompt column on the append-only classification log:
     * records the classification system prompt used (the fixed company/scope
     * context that replaced RAG retrieval) so admins can audit exactly what
     * context each inquiry was judged against.
     */
    public function up(): void
    {
        Schema::table('classification_results', function (Blueprint $table) {
            $table->text('system_prompt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('classification_results', function (Blueprint $table) {
            $table->dropColumn('system_prompt');
        });
    }
};