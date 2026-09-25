<?php

namespace Tests\Feature;

use App\Models\IndustrySector;
use App\Scoring\Exceptions\CannotClassifySectorException;
use App\Scoring\IndustrySectorFactor;
use App\Services\NotifyClientGrowthDirector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Support\UpstreamStubs as Stubs;
use Tests\TestCase;

/**
 * Industry-sector factor (feature 012, spec US1 + US2).
 *
 * US1: a confidently matched inquiry scores exactly the matched catalog
 * sector's stored rating; multi-sector matches pick the highest rating (ties
 * broken alphabetically, FR-003) and note the other candidates in reasoning;
 * identical runs are deterministic.
 *
 * US2: any inability to classify (empty catalog, no plausible sector, AI
 * unavailable/malformed output) notifies the Client Growth Director with the
 * review-decision payload (reason + candidates + an anonymized evidence
 * excerpt — never contact PII) and then throws `CannotClassifySectorException`,
 * which the engine records as a dropped factor while the response stays 200.
 */
class IndustrySectorFactorTest extends TestCase
{
    use RefreshDatabase;

    private const INQUIRY = [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
        'phone_number' => '+1 555 0132',
        'company_name' => 'Example Corp',
        'country_region' => 'United Kingdom',
        'message' => 'We are building a retail payments platform for online stores.',
    ];

    private const FINDINGS = [
        'outcome' => 'completed',
        'summary' => 'Example Corp operates a payments platform that processes online retail transactions.',
        'sources' => [
            ['title' => 'About Example Corp', 'url' => 'https://example.com/about'],
        ],
        'limitations' => null,
        'uncertain' => false,
    ];

    private function context(array $findings = self::FINDINGS): array
    {
        return [
            'inquiry' => self::INQUIRY,
            'web_research' => ['findings' => $findings],
        ];
    }

    private function makeFactor(): IndustrySectorFactor
    {
        return $this->app->make(IndustrySectorFactor::class);
    }

    private function fakeSectorOutput(array $sectors, array $considered = [], ?string $reasoning = null): void
    {
        Stubs::fakeSectorClassification($sectors, $considered, $reasoning);
    }

    private function bindNotifySpy(): SpyNotifyClientGrowthDirector
    {
        $spy = new SpyNotifyClientGrowthDirector;

        $this->app->instance(NotifyClientGrowthDirector::class, $spy);

        return $spy;
    }

    // =========================================================================
    // US1 — confident match scoring
    // =========================================================================

    public function test_name_is_industry_sector(): void
    {
        $this->assertSame('industry_sector', $this->makeFactor()->name());
    }

    public function test_matched_sector_scores_its_stored_rating(): void
    {
        // "Fintech" is seeded at rating 88.
        $this->fakeSectorOutput(['Fintech'], [], 'Fintech payments infrastructure fit.');

        $verdict = $this->makeFactor()->score(self::INQUIRY, $this->context());

        $this->assertSame(88, $verdict->score);
        $this->assertStringContainsString('Fintech', $verdict->reasoning);
        $this->assertSame('Fintech', $verdict->meta['sector']);
    }

    public function test_multi_sector_detection_picks_the_highest_rating_and_notes_others(): void
    {
        // Banking 76, Fintech 88, Healthcare 85 → Fintech wins; others noted.
        $this->fakeSectorOutput(['Banking', 'Fintech', 'Healthcare'], ['Fintech', 'Banking', 'Healthcare']);

        $verdict = $this->makeFactor()->score(self::INQUIRY, $this->context());

        $this->assertSame(88, $verdict->score);
        $this->assertSame('Fintech', $verdict->meta['sector']);
        $this->assertStringContainsString('Fintech', $verdict->reasoning);
        $this->assertStringContainsString('Banking', $verdict->reasoning);
        $this->assertStringContainsString('Healthcare', $verdict->reasoning);
    }

    public function test_rating_tie_breaks_alphabetically_to_the_first_name(): void
    {
        IndustrySector::query()->create(['name' => 'Zeta Imaging', 'rating' => 66]);
        IndustrySector::query()->create(['name' => 'Alpha Imaging', 'rating' => 66]);

        $this->fakeSectorOutput(['Zeta Imaging', 'Alpha Imaging']);

        $verdict = $this->makeFactor()->score(self::INQUIRY, $this->context());

        $this->assertSame(66, $verdict->score);
        $this->assertSame('Alpha Imaging', $verdict->meta['sector']);
    }

    public function test_identical_runs_are_deterministic(): void
    {
        $this->fakeSectorOutput(['Fintech', 'Banking'], ['Fintech', 'Banking'], 'Fit.');

        $first = $this->makeFactor()->score(self::INQUIRY, $this->context());
        $second = $this->makeFactor()->score(self::INQUIRY, $this->context());

        $this->assertSame($first->score, $second->score);
        $this->assertSame($first->reasoning, $second->reasoning);
        $this->assertSame($first->meta, $second->meta);
    }

    public function test_prompts_never_contain_contact_pii(): void
    {
        $this->fakeSectorOutput(['Fintech']);

        $this->makeFactor()->score(self::INQUIRY, $this->context());

        Http::assertSent(function (Request $request) {
            if ($request->url() !== Stubs::aiUrl()) {
                return true;
            }

            $prompts = json_encode([
                $request->data()['messages'][0]['content'] ?? '',
                $request->data()['messages'][1]['content'] ?? '',
            ], JSON_THROW_ON_ERROR);

            foreach (['Jane', 'Doe', 'jane@example.com', '+1 555 0132'] as $pii) {
                $this->assertStringNotContainsString(
                    $pii,
                    $prompts,
                    "The sector-classifier prompts leaked a contact field ({$pii}).",
                );
            }

            return true;
        });
    }

    public function test_prompt_lists_sectors_with_their_descriptions(): void
    {
        $this->fakeSectorOutput(['Fintech']);

        $this->makeFactor()->score(self::INQUIRY, $this->context());

        Http::assertSent(function (Request $request) {
            if ($request->url() !== Stubs::aiUrl()) {
                return true;
            }

            $prompt = (string) ($request->data()['messages'][1]['content'] ?? '');

            $this->assertStringContainsString(
                'wealth-tech',
                $prompt,
                'The classifier prompt must expose each sector description so it can reason about fit.',
            );
            $this->assertStringContainsString('Online stores', $prompt, 'Seeded E-commerce description missing from the prompt.');

            return true;
        });
    }

    // =========================================================================
    // US2 — unclassifiable inquiry → notify + throw
    // =========================================================================

    public function test_no_plausible_sector_notifies_and_throws(): void
    {
        $spy = $this->bindNotifySpy();
        $this->fakeSectorOutput(
            [],
            ['Media & Entertainment', 'Hospitality & Travel'],
            'No catalog sector is a plausible fit for this inquiry.',
        );

        try {
            $this->makeFactor()->score(self::INQUIRY, $this->context());
            $this->fail('Expected CannotClassifySectorException.');
        } catch (CannotClassifySectorException $e) {
            $this->assertSame('classification failed (no sector in catalog plausible)', $e->getMessage());
        }

        $this->assertCount(1, $spy->calls);
        $payload = $spy->calls[0]['context'];
        $this->assertSame('classification failed (no sector in catalog plausible)', $spy->calls[0]['reason']);
        $this->assertSame(['Media & Entertainment', 'Hospitality & Travel'], $payload['candidates']);
        $this->assertNotEmpty($payload['evidence_excerpt']);

        foreach (['jane@example.com', '+1 555 0132', '"Jane"', '"Doe"'] as $leak) {
            $this->assertStringNotContainsString($leak, json_encode($spy->calls, JSON_THROW_ON_ERROR), 'Notification payload leaked a contact field.');
        }
    }

    public function test_empty_catalog_notifies_and_throws(): void
    {
        $spy = $this->bindNotifySpy();
        IndustrySector::query()->delete();

        try {
            $this->makeFactor()->score(self::INQUIRY, $this->context());
            $this->fail('Expected CannotClassifySectorException.');
        } catch (CannotClassifySectorException $e) {
            $this->assertSame('no sectors configured', $e->getMessage());
        }

        $this->assertCount(1, $spy->calls);
        $payload = $spy->calls[0]['context'];
        $this->assertSame('no sectors configured', $spy->calls[0]['reason']);
        $this->assertSame([], $payload['candidates']);
        $this->assertNotEmpty($payload['evidence_excerpt']);

        // No AI call must happen with an empty catalog.
        Http::assertNothingSent();
    }

    public function test_ai_failure_notifies_and_throws(): void
    {
        $spy = $this->bindNotifySpy();
        Stubs::fakeAiJson('definitely not json');

        try {
            $this->makeFactor()->score(self::INQUIRY, $this->context());
            $this->fail('Expected CannotClassifySectorException.');
        } catch (CannotClassifySectorException $e) {
            $this->assertSame('classification failed (sector estimate unavailable)', $e->getMessage());
        }

        $this->assertCount(1, $spy->calls);
        $this->assertSame('classification failed (sector estimate unavailable)', $spy->calls[0]['reason']);
    }

    public function test_fabricated_sector_name_notifies_and_throws(): void
    {
        $spy = $this->bindNotifySpy();
        $this->fakeSectorOutput(['Made Up Sector'], ['Made Up Sector']);

        try {
            $this->makeFactor()->score(self::INQUIRY, $this->context());
            $this->fail('Expected CannotClassifySectorException.');
        } catch (CannotClassifySectorException $e) {
            $this->assertSame('classification failed (no sector in catalog plausible)', $e->getMessage());
        }

        $this->assertCount(1, $spy->calls);
    }

    public function test_unparseable_sector_list_notifies_and_throws(): void
    {
        $spy = $this->bindNotifySpy();
        Stubs::fakeSectorClassification([new \stdClass()]);

        try {
            $this->makeFactor()->score(self::INQUIRY, $this->context());
            $this->fail('Expected CannotClassifySectorException.');
        } catch (CannotClassifySectorException $e) {
            $this->assertSame('classification failed (sector estimate unavailable)', $e->getMessage());
        }

        $this->assertCount(1, $spy->calls);
    }

    // =========================================================================
    // US2 — triage run records the drop and stays 200
    // =========================================================================

    public function test_triage_run_with_drop_stays_200_and_records_the_drop(): void
    {
        $spy = $this->bindNotifySpy();

        Stubs::fakeLoginOk();
        Stubs::fakeRagQuery([Stubs::ragResult()]);
        Stubs::fakeSectorClassification([], ['Media & Entertainment'], 'No plausible catalog sector.');

        $response = $this->postJson('/inquiry/triage', [
            'message' => 'We are building a retail payments platform for online stores.',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('classification', 'disqualify')
            ->assertJsonMissingPath('factor_scores.industry_sector');

        $drop = collect($response->json('dropped_factors'))
            ->firstWhere('name', 'industry_sector');
        $this->assertNotNull($drop, 'industry_sector should appear in dropped_factors.');
        $this->assertNotEmpty($drop['reason']);

        $this->assertCount(1, $spy->calls);
        $this->assertSame('classification failed (no sector in catalog plausible)', $spy->calls[0]['reason']);
    }
}

/**
 * Records notify() invocations so tests can assert the review-decision payload
 * (US2) without a real alert channel (ASS-06).
 */
class SpyNotifyClientGrowthDirector extends NotifyClientGrowthDirector
{
    /** @var array<int, array{reason: string, context: array<string, mixed>}> */
    public array $calls = [];

    public function notify(string $reason, array $context): void
    {
        $this->calls[] = ['reason' => $reason, 'context' => $context];
    }
}