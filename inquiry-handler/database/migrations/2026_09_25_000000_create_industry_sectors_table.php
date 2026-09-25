<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalog of industry sectors the triage engine can match an inquiry to
     * (feature 012, data-model.md). The 24 seed rows carry the curated ratings,
     * inserted idempotently so the migration is safe on a store that already
     * holds the catalog (CI runs migrate, never seed).
     */
    public function up(): void
    {
        Schema::create('industry_sectors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->integer('rating');
            $table->timestamps();
        });

        // The 0–100 rating invariant lives as a real DB CHECK on Postgres (the
        // deployed store). SQLite (test suite) has no ALTER ADD CONSTRAINT, so
        // there the invariant is enforced by the controller/service validation
        // that guards every write (data-model.md line 29).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE industry_sectors ADD CONSTRAINT industry_sectors_rating_check CHECK (rating >= 0 AND rating <= 100)');
        }

        DB::table('industry_sectors')->insertOrIgnore([
            ['name' => 'E-commerce & Retail', 'rating' => 92, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'SaaS & Software', 'rating' => 90, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Fintech', 'rating' => 88, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Healthcare', 'rating' => 85, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Logistics & Supply Chain', 'rating' => 82, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Biotech & Pharma', 'rating' => 80, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Govtech', 'rating' => 78, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Banking', 'rating' => 76, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Professional Services', 'rating' => 75, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Edtech', 'rating' => 74, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Real Estate / Proptech', 'rating' => 72, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Telecom', 'rating' => 70, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'FMCG / Food & Beverage', 'rating' => 68, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Manufacturing', 'rating' => 65, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Media & Entertainment', 'rating' => 62, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Hospitality & Travel', 'rating' => 60, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Insurance', 'rating' => 58, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Automotive', 'rating' => 57, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Energy & Utilities', 'rating' => 55, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Agriculture / Agritech', 'rating' => 52, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Public Sector', 'rating' => 50, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Construction & Engineering', 'rating' => 48, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Aviation & Maritime', 'rating' => 45, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Nonprofit / NGO', 'rating' => 40, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('industry_sectors');
    }
};