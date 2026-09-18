<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Singleton factor-settings row (data-model.md): the factor NAMES live in
     * code (FactorRegistry, FR-004); this row stores only their runtime weights.
     * One row (id=1) seeded here so reads never need to fabricate it.
     */
    public function up(): void
    {
        Schema::create('factor_settings', function (Blueprint $table) {
            $table->id();
            $table->json('weights')->default('{}');
            $table->timestamps();
        });

        DB::table('factor_settings')->insert([
            'id' => 1,
            'weights' => json_encode(new \stdClass),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('factor_settings');
    }
};