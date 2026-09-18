<?php

namespace Tests\Unit;

use App\Triage\Disposition;
use App\Triage\PromptBuilder;
use PHPUnit\Framework\TestCase;

class PromptBuilderTest extends TestCase
{
    private const DEFAULT_SCOPE = 'a B2B HVAC service company';

    private PromptBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new PromptBuilder();
    }

    private function context(int $count = 1): array
    {
        return [
            'result_count' => $count,
            'results' => [
                [
                    'rank' => 1,
                    'title' => 'Maintenance Plans',
                    'source' => 'playbooks',
                    'text' => 'Annual maintenance plans cover heating boiler servicing.',
                    'similarity_score' => 0.72,
                    'document_id' => 'doc-1',
                    'chunk_index' => 4,
                ],
            ],
        ];
    }

    public function test_system_prompt_is_constant_except_for_scope_and_disposition_injections(): void
    {
        $prompt = $this->builder->build('Question here', $this->context());

        $expected = strtr(PromptBuilder::SYSTEM_PROMPT, [
            '{company_scope}' => self::DEFAULT_SCOPE,
            '{disposition_decline}' => Disposition::Decline->value,
            '{disposition_escalate}' => Disposition::Escalate->value,
            '{disposition_booking}' => Disposition::Booking->value,
        ]);
        $this->assertSame($expected, $prompt['system']);
    }

    public function test_company_scope_from_config_is_injected_into_system_prompt(): void
    {
        $builder = new PromptBuilder('a company selling maritime navigation hardware');

        $prompt = $builder->build('Question here', $this->context());

        $this->assertStringContainsString('a company selling maritime navigation hardware', $prompt['system']);
        $this->assertStringNotContainsString('{company_scope}', $prompt['system']);
    }

    public function test_user_inquiry_and_documents_are_delimited_data_blocks(): void
    {
        $prompt = $this->builder->build('Do you fix boilers?', $this->context());

        $this->assertStringContainsString("[USER INQUIRY]\nDo you fix boilers?\n[/USER INQUIRY]", $prompt['user']);
        $this->assertStringContainsString('[RETRIEVED DOCUMENTS]', $prompt['user']);
        $this->assertStringContainsString('[/RETRIEVED DOCUMENTS]', $prompt['user']);
    }

    public function test_retrieved_document_text_is_present_in_user_block(): void
    {
        $prompt = $this->builder->build('Do you fix boilers?', $this->context());

        $this->assertStringContainsString('Maintenance Plans', $prompt['user']);
        $this->assertStringContainsString('playbooks', $prompt['user']);
        $this->assertStringContainsString('Annual maintenance plans cover heating boiler servicing.', $prompt['user']);
    }

    public function test_inquiry_and_documents_never_leak_into_system_prompt(): void
    {
        $inquiry = 'Do you fix boilers?';
        $docText = 'Annual maintenance plans cover heating boiler servicing.';

        $prompt = $this->builder->build($inquiry, $this->context());

        $this->assertStringNotContainsString($inquiry, $prompt['system']);
        $this->assertStringNotContainsString($docText, $prompt['system']);
        $this->assertStringNotContainsString('[USER INQUIRY]', $prompt['system']);
        // Note: the FIXED system prompt is allowed to name the [RETRIEVED
        // DOCUMENTS] block in its groundedness rule; what matters is that no
        // request-derived text or blocks ever enter the system role.
        $this->assertStringNotContainsString(PHP_EOL.'[RETRIEVED DOCUMENTS]'.PHP_EOL, $prompt['system']);
    }

    public function test_no_documents_block_instructions_escalate_instead_of_inventing(): void
    {
        $prompt = $this->builder->build('Question', ['result_count' => 0, 'results' => []]);

        $this->assertStringContainsString('No retrieved documents.', $prompt['user']);
        $this->assertStringContainsString('escalate', strtolower($prompt['system']));
    }

    public function test_contact_fields_are_not_part_of_the_prompt_surface(): void
    {
        // The builder deliberately accepts only the message + retrieved context;
        // there is no parameter for name/email anywhere in the assembly path.
        $reflection = new \ReflectionMethod(PromptBuilder::class, 'build');
        $parameters = array_map(fn ($p) => $p->getName(), $reflection->getParameters());

        $this->assertNotContains('name', $parameters);
        $this->assertNotContains('email', $parameters);
    }
}