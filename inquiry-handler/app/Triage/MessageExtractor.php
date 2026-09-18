<?php

namespace App\Triage;

use App\Triage\Exceptions\MessageValidationException;

/**
 * Normalizes an inbound payload into the canonical inquiry the triage flow
 * reasons about (feature 008): the plain-text message plus the six contact
 * fields the form collects.
 *
 * The returned array is
 * `['first_name' => string, 'last_name' => string, 'email' => string,
 *   'phone_number' => ?string, 'company_name' => ?string,
 *   'country_region' => ?string, 'message' => string]`.
 *
 * First name, last name, email, and message are required (FR-002); phone
 * number, company name, and country/region are optional and become null when
 * blank (FR-003). The required email must be well-formed (FR-004), every text
 * field is capped at 255 characters (FR-005), and the message at 4000 (FR-006).
 * Contact fields are passed through for later use; they are NOT sent to the AI.
 * Validation messages humanize the field key (`first_name` → `first name`) so
 * the 422 detail matches the form labels (FR-010).
 */
class MessageExtractor
{
    public const MAX_TEXT = 255;

    /**
     * @param  array<mixed>  $payload
     * @return array{first_name: string, last_name: string, email: string, phone_number: string|null, company_name: string|null, country_region: string|null, message: string}
     *
     * @throws MessageValidationException
     */
    public function extract(array $payload): array
    {
        $payload = $this->normalizeShape($payload);

        return [
            'first_name' => $this->requiredText($payload, 'first_name'),
            'last_name' => $this->requiredText($payload, 'last_name'),
            'email' => $this->email($payload),
            'phone_number' => $this->optionalText($payload, 'phone_number'),
            'company_name' => $this->optionalText($payload, 'company_name'),
            'country_region' => $this->optionalText($payload, 'country_region'),
            'message' => $this->message($payload),
        ];
    }

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private function normalizeShape(array $payload): array
    {
        // Future external-app schema branch: map its envelope onto the
        // canonical keys here so triage behaves identically regardless of
        // envelope. No speculative adapter.
        return $payload;
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function label(string $key): string
    {
        return str_replace('_', ' ', $key);
    }

    private function requiredText(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value)) {
            throw new MessageValidationException("The {$this->label($key)} field is required.");
        }

        $value = trim($value);

        if ($value === '') {
            throw new MessageValidationException("The {$this->label($key)} field is required.");
        }

        if (mb_strlen($value) > self::MAX_TEXT) {
            throw new MessageValidationException("The {$key} is too long (max ".self::MAX_TEXT.' characters).');
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function optionalText(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new MessageValidationException("The {$key} must be a string.");
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > self::MAX_TEXT) {
            throw new MessageValidationException("The {$key} is too long (max ".self::MAX_TEXT.' characters).');
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function email(array $payload): string
    {
        $email = $payload['email'] ?? null;

        if (! is_string($email)) {
            throw new MessageValidationException('The email field is required.');
        }

        $email = trim($email);

        if ($email === '') {
            throw new MessageValidationException('The email field is required.');
        }

        if (mb_strlen($email) > self::MAX_TEXT || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new MessageValidationException('The email must be a valid email address.');
        }

        return $email;
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function message(array $payload): string
    {
        $message = $payload['message'] ?? null;

        if (! is_string($message)) {
            throw new MessageValidationException('The message field is required.');
        }

        $message = trim($message);

        if ($message === '') {
            throw new MessageValidationException('The message must not be blank.');
        }

        $max = (int) config('app.message_max_length', 4000);
        if (mb_strlen($message) > $max) {
            throw new MessageValidationException("The message is too long (max {$max} characters).");
        }

        return $message;
    }
}