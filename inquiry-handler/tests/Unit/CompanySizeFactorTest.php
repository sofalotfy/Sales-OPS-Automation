<?php

namespace Tests\Unit;

use App\Scoring\CompanySizeFactor;
use App\Scoring\FactorVerdict;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Company-size factor (feature 009): the first registered factor, scored by an
 * AI call whose input is the web-research findings (summary + sources).
 *
 * The scoring engine (tests/Unit/ScoringEngineTest.php) covers clamping and
 * weighted combination; this test pins the factor's own input handling,
 * no-fabrication rules (FR-003/FR-005) and privacy guarantee (FR-004/SC-003).
 */
class CompanySizeFactorTest extends TestCase
{
    private const INQUIRY = [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
        'phone_number' => '+1 555 0132',
        'company_name' => 'Example Corp',
        'country_region' => 'United Kingdom',
        'message' => 'Do you build enterprise web applications?',
    ];

    private const FINDINGS = [
        'outcome' => 'completed',
        'summary' => 'Example Corp is a UK fintech with roughly 2,500 employees and offices in London and New York.',
        'sources' => [
            ['title' => 'About Example Corp', 'url' => 'https://example.com/about'],
            ['title' => 'Example Corp - Careers', 'url' => 'https://careers.example.com'],
        ],
        'limitations' => null,
        'uncertain' => false,
    ];

    private function context(array $findings = self::FINDINGS, bool $includeFindings = true): array
    {
        return [
            'inquiry' => self::INQUIRY,
            'web_research' => $includeFindings ? ['findings' => $findings] : [],
        ];
    }

    private function factor(): CompanySizeFactor
    {
        return app(CompanySizeFactor::class);
    }

    public function test_name_is_company_size(): void
    {
        $this->assertSame('company_size', $this->factor()->name());
    }

    public function test_missing_company_name_returns_zero_without_ai_call(): void
    {
        $inquiry = array_merge(self::INQUIRY, ['company_name' => null]);

        $verdict = $this->factor()->score($inquiry, $this->context());

        $this->assertZeroVerdict($verdict, 'no company name was provided');
        Http::assertNothingSent();
    }

    public function test_blank_company_name_returns_zero_without_ai_call(): void
    {
        $inquiry = array_merge(self::INQUIRY, ['company_name' => '   ']);

        $verdict = $this->factor()->score($inquiry, $this->context());

        $this->assertZeroVerdict($verdict, 'no company name was provided');
        Http::assertNothingSent();
    }

    public function test_missing_research_findings_returns_zero_without_ai_call(): void
    {
        $verdict = $this->factor()->score(self::INQUIRY, $this->context(includeFindings: false));

        $this->assertZeroVerdict($verdict, 'no web-research findings were available');
        Http::assertNothingSent();
    }

    public function test_not_found_outcome_returns_zero_without_ai_call(): void
    {
        $findings = ['outcome' => 'not_found', 'summary' => 'No public information found.', 'sources' => [], 'limitations' => null, 'uncertain' => false];

        $verdict = $this->factor()->score(self::INQUIRY, $this->context($findings));

        $this->assertZeroVerdict($verdict, 'no public information');
        Http::assertNothingSent();
    }

    public function test_ambiguous_outcome_returns_zero_without_ai_call(): void
    {
        $findings = ['outcome' => 'ambiguous', 'summary' => 'Name matches several entities.', 'sources' => [], 'limitations' => null, 'uncertain' => false];

        $verdict = $this->factor()->score(self::INQUIRY, $this->context($findings));

        $this->assertZeroVerdict($verdict, 'several distinct entities');
        Http::assertNothingSent();
    }

    public function test_empty_content_returns_zero_without_ai_call(): void
    {
        $findings = ['outcome' => 'completed', 'summary' => '  ', 'sources' => [], 'limitations' => null, 'uncertain' => false];

        $verdict = $this->factor()->score(self::INQUIRY, $this->context($findings));

        $this->assertZeroVerdict($verdict, 'no usable content');
        Http::assertNothingSent();
    }

    public function test_ai_estimate_is_parsed_into_the_verdict(): void
    {
        Http::fake([
            trim((string) config('services.zai.url')) => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'score' => 80,
                    'size_band' => 'large',
                    'employee_count' => 2500,
                    'reasoning' => 'Around 2,500 staff with a multi-country footprint.',
                ])]]],
            ], 200),
        ]);

        $verdict = $this->factor()->score(self::INQUIRY, $this->context());

        $this->assertSame(80, $verdict->score);
        $this->assertSame('Around 2,500 staff with a multi-country footprint.', $verdict->reasoning);
        $this->assertSame('large', $verdict->meta['size_band']);
        $this->assertSame(2500, $verdict->meta['employee_count']);
    }

    public function test_zero_ai_estimate_is_passed_through_honestly(): void
    {
        Http::fake([
            trim((string) config('services.zai.url')) => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'score' => 0,
                    'size_band' => null,
                    'employee_count' => null,
                    'reasoning' => 'Findings name the company but give no headcount/revenue signal.',
                ])]]],
            ], 200),
        ]);

        $verdict = $this->factor()->score(self::INQUIRY, $this->context());

        $this->assertSame(0, $verdict->score);
        $this->assertSame('Findings name the company but give no headcount/revenue signal.', $verdict->reasoning);
    }

    public function test_ai_failure_degrades_to_zero_without_throwing(): void
    {
        Http::fake([
            trim((string) config('services.zai.url')) => Http::response(['error' => ['message' => 'provider down']], 500),
        ]);

        $verdict = $this->factor()->score(self::INQUIRY, $this->context());

        $this->assertZeroVerdict($verdict, 'estimate was unavailable');
    }

    public function test_unparseable_ai_output_degrades_to_zero(): void
    {
        Http::fake([
            trim((string) config('services.zai.url')) => Http::response([
                'choices' => [['message' => ['content' => 'definitely not json']]],
            ], 200),
        ]);

        $verdict = $this->factor()->score(self::INQUIRY, $this->context());

        $this->assertZeroVerdict($verdict, 'estimate was unavailable');
    }

    public function test_out_of_range_ai_score_degrades_to_zero(): void
    {
        Http::fake([
            trim((string) config('services.zai.url')) => Http::response([
                'choices' => [['message' => ['content' => json_encode(['score' => -5, 'reasoning' => 'n/a'])]]],
            ], 200),
        ]);

        $this->assertZeroVerdict($this->factor()->score(self::INQUIRY, $this->context()), 'outside 0-100');
    }

    public function test_ai_call_sends_only_company_and_findings_not_contacts(): void
    {
        Http::fake([
            trim((string) config('services.zai.url')) => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'score' => 65,
                    'size_band' => 'mid',
                    'employee_count' => 500,
                    'reasoning' => 'Mid-size with 500 staff.',
                ])]]],
            ], 200),
        ]);

        $this->factor()->score(self::INQUIRY, $this->context());

        Http::assertSent(function (Request $request) {
            $payload = json_encode($request->data());

            $this->assertTrue(is_string($payload));

            foreach (['jane@example.com', '+1 555 0132', '"Jane"', '"Doe"', 'first_name', 'last_name', 'phone_number', 'email'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $payload, 'AI payload leaked a contact field.');
            }

            $this->assertStringContainsString('Example Corp', $payload);
            $this->assertStringContainsString('United Kingdom', $payload);
            $this->assertStringContainsString('roughly 2,500 employees', $payload);
            $this->assertStringContainsString('example.com', $payload);

            return true;
        });
    }

    private function assertZeroVerdict(FactorVerdict $verdict, string $needle): void
    {
        $this->assertSame(0, $verdict->score);
        $this->assertStringContainsString($needle, $verdict->reasoning);
    }
}