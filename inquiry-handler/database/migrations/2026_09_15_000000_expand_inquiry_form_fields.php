<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive/removal change for the inquiry form fields (feature 008):
 * drop the single `name` column and add the split contact columns.
 *
 * The new columns are DB-nullable on purpose: this is an append-only
 * production log whose historical rows have no first/last name, and the
 * spec explicitly excludes migrating existing data. New rows always carry
 * the required fields because MessageExtractor rejects submissions that
 * lack them (the application layer enforces FR-002).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classification_results', function (Blueprint $table) {
            $table->dropColumn('name');
        });

        Schema::table('classification_results', function (Blueprint $table) {
            $table->string('first_name', 255)->nullable();
            $table->string('last_name', 255)->nullable();
            $table->string('phone_number', 255)->nullable();
            $table->string('company_name', 255)->nullable();
            $table->string('country_region', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('classification_results', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'phone_number', 'company_name', 'country_region']);
        });

        Schema::table('classification_results', function (Blueprint $table) {
            $table->string('name', 255)->nullable();
        });
    }
};