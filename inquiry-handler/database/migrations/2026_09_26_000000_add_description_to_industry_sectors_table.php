<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give every sector a short, AI-facing description (feature 012 follow-up):
     * the `industry_sector` factor lists each sector with its description so the
     * classifier can reason about fit instead of matching on the bare name.
     *
     * The column is nullable at the DB layer (pre-existing rows), but the admin
     * API requires a description on create/update, and the seed is backfilled so
     * CI's `migrate` — which never seeds — still produces a fully described
     * catalog.
     *
     * @var array<string, string>  sector name → description (≤500 chars)
     */
    private const DESCRIPTIONS = [
        'E-commerce & Retail' => 'Online stores, marketplaces and retail brands selling products or services directly to consumers; includes checkout, storefront and catalog infrastructure.',
        'SaaS & Software' => 'Companies building subscription or licensed software products, platforms and developer tools, from seed-stage startups to large ISVs.',
        'Fintech' => 'Financial technology: payments, neobanks, wealth-tech, lending, crypto and insurtech platforms that modernise money movement and finance.',
        'Healthcare' => 'Hospitals, clinics, care networks, health insurers and digital-health companies; delivery, operations and patient-facing services.',
        'Logistics & Supply Chain' => 'Freight, couriers, fulfilment, warehousing and shipment-tracking operators, plus supply-chain planning software and marketplaces.',
        'Biotech & Pharma' => 'Drug discovery, clinical research organisations, pharmaceutical manufacturers, labs and life-science instrumentation and data platforms.',
        'Govtech' => 'Government platforms and public-services digitisation: citizen portals, permit/policy workflows, civic data and procurement software.',
        'Banking' => 'Retail, corporate and central banks: deposit and lending products, payments rails, onboarding, core modernisation and treasury services.',
        'Professional Services' => 'Consultancies, agencies, legal, accounting and advisory firms selling expertise, managed services and client-delivery work.',
        'Edtech' => 'Education technology: school and university platforms, learning management systems, courses, assessment and student-success tools.',
        'Real Estate / Proptech' => 'Property developers, brokers, landlords, facility managers and proptech platforms spanning listings, leasing and building operations.',
        'Telecom' => 'Telecom operators, ISPs, tower and data-centre owners and connectivity software spanning billing, networks and mobile services.',
        'FMCG / Food & Beverage' => 'Fast-moving consumer goods, food and drink manufacturers, distributors, D2C food brands and grocery retail operations.',
        'Manufacturing' => 'Industrial and consumer-goods manufacturers, OEMs and factory operators spanning production, supply and plant software.',
        'Media & Entertainment' => 'Publishers, streaming, broadcasters, studios, gaming publishers and content platforms monetising audiences and IP.',
        'Hospitality & Travel' => 'Hotels, restaurants, airlines, cruise lines, tour operators, OTAs and booking platforms serving travellers and venues.',
        'Insurance' => 'General, life, health and Re-insurers, MGAs, brokers and insurtech carriers modernising underwriting, claims and distribution.',
        'Automotive' => 'Car, truck, EV and parts manufacturers, dealerships, fleet operators and mobility platforms across sales, service and manufacturing.',
        'Energy & Utilities' => 'Power producers, renewables, utilities, grid operators, oil and gas companies and energy-trading platforms.',
        'Agriculture / Agritech' => 'Farms, agri-food producers, cooperatives, agricultural-equipment makers and precision-agriculture and agri-commerce platforms.',
        'Public Sector' => 'National and local public bodies, agencies and state-owned enterprises modernising citizen services, operations and policy systems.',
        'Construction & Engineering' => 'Builders, developers, civil-engineering firms, architects and construction-tech spanning project, site and operations management.',
        'Aviation & Maritime' => 'Airlines, airports, shipping lines, ports, freight-forwarders, MROs and marine/aviation logistics and fleet software.',
        'Nonprofit / NGO' => 'Charities, foundations, NGOs and social enterprises running donor management, programmes, volunteering and impact measurement.',
    ];

    public function up(): void
    {
        Schema::table('industry_sectors', function (Blueprint $table) {
            $table->text('description')->nullable()->after('rating');
        });

        foreach (self::DESCRIPTIONS as $name => $description) {
            DB::table('industry_sectors')
                ->where('name', $name)
                ->update(['description' => $description]);
        }
    }

    public function down(): void
    {
        Schema::table('industry_sectors', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};