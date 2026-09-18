<?php

namespace Tests\Unit;

use App\WebResearch\Providers\TavilyResearchProvider;
use App\WebResearch\ResearchAgent;
use App\WebResearch\ResearchOutcome;
use App\WebResearch\ResearchResult;
use App\WebResearch\WebResearchProvider;
use App\WebResearch\WebResearchService;
use App\WebResearch\WebResearchVerdict;
use Tests\TestCase;

/**
 * Web-research step unit tests (feature 010 updated).
 *
 * Focus: the contact-safe criteria built from an inquiry (only the person's
 * name + company context ever reach the provider — never email/phone) and the
 * verdict mapping of the AI research agent's result (accept / decline /
 * indeterminate fail-open).
 */
class WebResearchServiceTest extends TestCase
{
    private WebResearchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->service(new TavilyResearchProvider, $this->fakeAgent(
            ResearchResult::notFound('Nothing found.'),
        ));
    }

    private function service(WebResearchProvider $provider, ResearchAgent $agent): WebResearchService
    {
        return new WebResearchService($provider, $agent);
    }

    private function fakeAgent(ResearchResult $result): ResearchAgent
    {
        return new class($result) extends ResearchAgent
        {
            public function __construct(private ResearchResult $result) {}

            public function research(array $criteria, array $payload): ResearchResult
            {
                return $this->result;
            }
        };
    }

    /** @return array<string, mixed> */
    private function inquiry(array $overrides = []): array
    {
        return array_merge([
            'message' => 'We need a CRM built.',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone_number' => '+1 555 0132',
            'company_name' => 'Example Corp',
            'country_region' => 'United Kingdom',
        ], $overrides);
    }

    private function providerReturning(array $payload): WebResearchProvider
    {
        return new class($payload) implements WebResearchProvider
        {
            public function __construct(private array $payload) {}

            public function research(array $criteria): array
            {
                return $this->payload;
            }
        };
    }

    public function test_criteria_never_contains_email_or_phone(): void
    {
        $criteria = $this->service->criteria($this->inquiry());

        $encoded = json_encode($criteria);
        $this->assertNotContains('email', array_keys($criteria));
        $this->assertStringNotContainsString('jane@example.com', (string) $encoded);
        $this->assertStringNotContainsString('+1 555 0132', (string) $encoded);
    }

    public function test_criteria_carries_company_name_and_country(): void
    {
        $criteria = $this->service->criteria($this->inquiry());

        $this->assertSame('Example Corp', $criteria['company']['name']);
        $this->assertSame('United Kingdom', $criteria['company']['country_region']);
        $this->assertSame('Example Corp', $criteria['person']['company_context']);
        $this->assertSame('Jane', $criteria['person']['first_name']);
        $this->assertSame('Doe', $criteria['person']['last_name']);
    }

    public function test_criteria_omits_company_block_when_company_name_blank(): void
    {
        $criteria = $this->service->criteria($this->inquiry(['company_name' => '', 'country_region' => null]));

        $this->assertNull($criteria['company']);
        $this->assertArrayNotHasKey('company_context', $criteria['person']);
        $this->assertSame('Jane', $criteria['person']['first_name']);
    }

    public function test_run_accept_carries_the_agent_findings(): void
    {
        $findings = new ResearchResult(
            ResearchOutcome::Completed,
            'Example Corp is a UK fintech.',
            [['title' => 'About', 'url' => 'https://example.com/about']],
            null,
            audit: [
                'counts' => ['obtained' => 2, 'kept' => 1, 'rejected' => 1],
                'filter_outcome' => 'kept',
                'rescue_used' => false,
            ],
        );

        $service = $this->service($this->providerReturning(['company' => [], 'person' => []]), $this->fakeAgent($findings));

        $run = $service->run($this->inquiry());
        $verdict = $run['verdict'];

        $this->assertSame(WebResearchVerdict::ACCEPT, $verdict->outcome);
        $this->assertSame('completed', $verdict->research['outcome']);
        $this->assertSame('Example Corp is a UK fintech.', $verdict->research['summary']);
        $this->assertSame([['title' => 'About', 'url' => 'https://example.com/about']], $verdict->research['sources']);
        $this->assertArrayNotHasKey('company', $verdict->research);
        $this->assertArrayNotHasKey('person', $verdict->research);
        $this->assertSame(['obtained' => 2, 'kept' => 1, 'rejected' => 1], $run['audit']['counts']);
    }

    public function test_run_declines_when_provider_short_circuits(): void
    {
        $provider = $this->providerReturning([
            'company' => ['is_defunct' => true],
            'decline' => ['reason' => 'Defunct.', 'refusal' => 'We cannot help.'],
        ]);

        $verdict = $this->service($provider, $this->fakeAgent(ResearchResult::notFound('x')))->run($this->inquiry())['verdict'];

        $this->assertTrue($verdict->isDecline());
        $this->assertSame('Defunct.', $verdict->reason);
        $this->assertSame('We cannot help.', $verdict->refusal);
        $this->assertTrue($verdict->research['company']['is_defunct']);
    }

    public function test_run_fails_open_when_provider_throws(): void
    {
        $provider = new class implements WebResearchProvider
        {
            public function research(array $criteria): array
            {
                throw new \RuntimeException('down');
            }
        };

        $verdict = $this->service($provider, $this->fakeAgent(ResearchResult::notFound('x')))->run($this->inquiry())['verdict'];

        $this->assertSame(WebResearchVerdict::INDETERMINATE, $verdict->outcome);
        $this->assertSame([], $verdict->research);
    }

    public function test_run_fails_open_with_empty_audit_when_provider_throws(): void
    {
        $provider = new class implements WebResearchProvider
        {
            public function research(array $criteria): array
            {
                throw new \RuntimeException('down');
            }
        };

        $run = $this->service($provider, $this->fakeAgent(ResearchResult::notFound('x')))->run($this->inquiry());

        $this->assertSame(WebResearchVerdict::INDETERMINATE, $run['verdict']->outcome);
        $this->assertSame([], $run['audit']);
    }

    public function test_run_fails_open_when_agent_is_indeterminate(): void
    {
        $service = $this->service(
            $this->providerReturning(['company' => [], 'person' => []]),
            $this->fakeAgent(ResearchResult::indeterminate('Filter unavailable.')),
        );

        $verdict = $service->run($this->inquiry())['verdict'];

        $this->assertSame(WebResearchVerdict::INDETERMINATE, $verdict->outcome);
        $this->assertSame('Filter unavailable.', $verdict->reason);
    }
}
