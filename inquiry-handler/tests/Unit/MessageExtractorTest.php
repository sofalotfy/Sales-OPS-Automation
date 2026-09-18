<?php

namespace Tests\Unit;

use App\Triage\Exceptions\MessageValidationException;
use App\Triage\MessageExtractor;
use Tests\TestCase;

class MessageExtractorTest extends TestCase
{
    private MessageExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new MessageExtractor();
        config()->set('app.message_max_length', 4000);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone_number' => '+1 555 0132',
            'company_name' => 'Example Corp',
            'country_region' => 'United Kingdom',
            'message' => 'Do you offer annual maintenance contracts?',
        ], $overrides);
    }

    public function test_extracts_all_seven_fields_trimmed(): void
    {
        $result = $this->extractor->extract($this->validPayload([
            'first_name' => '  Jane  ',
            'last_name' => '  Doe  ',
            'email' => '   jane@example.com  ',
            'phone_number' => '  +1 555 0132  ',
            'company_name' => '  Example Corp  ',
            'country_region' => '  United Kingdom  ',
            'message' => '  Do you offer annual maintenance contracts?  ',
        ]));

        $this->assertSame('Jane', $result['first_name']);
        $this->assertSame('Doe', $result['last_name']);
        $this->assertSame('jane@example.com', $result['email']);
        $this->assertSame('+1 555 0132', $result['phone_number']);
        $this->assertSame('Example Corp', $result['company_name']);
        $this->assertSame('United Kingdom', $result['country_region']);
        $this->assertSame('Do you offer annual maintenance contracts?', $result['message']);
    }

    public function test_blank_optional_fields_become_null(): void
    {
        $result = $this->extractor->extract($this->validPayload([
            'phone_number' => '',
            'company_name' => '   ',
            'country_region' => null,
        ]));

        $this->assertNull($result['phone_number']);
        $this->assertNull($result['company_name']);
        $this->assertNull($result['country_region']);
    }

    public function test_missing_first_name_is_rejected(): void
    {
        $this->expectException(MessageValidationException::class);
        $this->extractor->extract($this->validPayload(['first_name' => null]));
    }

    public function test_blank_first_name_is_rejected(): void
    {
        $this->expectException(MessageValidationException::class);
        $this->extractor->extract($this->validPayload(['first_name' => "   \n\t "]));
    }

    public function test_non_string_first_name_is_rejected(): void
    {
        $this->expectException(MessageValidationException::class);
        $this->extractor->extract($this->validPayload(['first_name' => 42]));
    }

    public function test_oversized_first_name_is_rejected(): void
    {
        $this->expectException(MessageValidationException::class);
        $this->extractor->extract($this->validPayload(['first_name' => str_repeat('n', 256)]));
    }

    public function test_missing_last_name_is_rejected(): void
    {
        $this->expectException(MessageValidationException::class);
        $this->extractor->extract($this->validPayload(['last_name' => null]));
    }

    public function test_blank_last_name_is_rejected(): void
    {
        $this->expectException(MessageValidationException::class);
        $this->extractor->extract($this->validPayload(['last_name' => ' ']));
    }

    public function test_oversized_last_name_is_rejected(): void
    {
        $this->expectException(MessageValidationException::class);
        $this->extractor->extract($this->validPayload(['last_name' => str_repeat('n', 256)]));
    }

    public function test_non_string_last_name_is_rejected(): void
    {
        $this->expectException(MessageValidationException::class);
        $this->extractor->extract($this->validPayload(['last_name' => 42]));
    }

    public function test_missing_email_is_rejected(): void
    {
        $this->expectException(MessageValidationException::class);
        $this->extractor->extract($this->validPayload(['email' => null]));
    }

    public function test_blank_email_is_rejected(): void
    {
        $this->expectException(MessageValidationException::class);
        $this->extractor->extract($this->validPayload(['email' => '   ']));
    }

    public function test_malformed_email_is_rejected(): void
    {
        $this->expectException(MessageValidationException::class);
        $this->extractor->extract($this->validPayload(['email' => 'user@']));
    }

    public function test_invalid_email_format_is_rejected(): void
    {
        $this->expectException(MessageValidationException::class);
        $this->extractor->extract($this->validPayload(['email' => 'not-an-email']));
    }

    public function test_oversized_email_is_rejected(): void
    {
        $this->expectException(MessageValidationException::class);
        $this->extractor->extract($this->validPayload(['email' => str_repeat('a', 256).'@example.com']));
    }

    public function test_non_string_optional_field_is_rejected(): void
    {
        $this->expectException(MessageValidationException::class);
        $this->extractor->extract($this->validPayload(['phone_number' => 42]));
    }

    public function test_oversized_optional_field_is_rejected(): void
    {
        $this->expectException(MessageValidationException::class);
        $this->extractor->extract($this->validPayload(['company_name' => str_repeat('c', 256)]));
    }

    public function test_oversized_message_is_rejected(): void
    {
        $this->expectException(MessageValidationException::class);
        $this->extractor->extract($this->validPayload(['message' => str_repeat('a', 4001)]));
    }

    public function test_message_at_limit_is_accepted(): void
    {
        $result = $this->extractor->extract($this->validPayload(['message' => str_repeat('a', 4000)]));
        $this->assertSame(4000, mb_strlen($result['message'] ?? ''));
    }

    public function test_whitespace_only_message_is_rejected(): void
    {
        $this->expectException(MessageValidationException::class);
        $this->extractor->extract($this->validPayload(['message' => "   \n\t "]));
    }

    public function test_missing_message_is_rejected(): void
    {
        $this->expectException(MessageValidationException::class);
        $this->extractor->extract($this->validPayload(['message' => null]));
    }
}