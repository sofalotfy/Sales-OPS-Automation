<?php

namespace App\Support;

use DateTimeImmutable;
use Illuminate\Http\Request;

/**
 * An inclusive range of calendar days, as `Y-m-d` bounds.
 *
 * Shared by the dashboard home page and the classification log so both read the
 * same `from`/`to` query parameters and agree on what a bound means. The handler
 * applies the range in UTC, which is the app timezone, so a day selected here is
 * the same day it filters on there.
 *
 * A bound that is not a real calendar day is dropped rather than rejected,
 * matching how an unrecognised status or classification filter degrades: the
 * page still renders, and the parameter is not forwarded as a value the handler
 * would answer 422 for. A range whose start is after its end is *not* dropped,
 * because that is a coherent request with no answer rather than a typo — see
 * `isReversed()`.
 */
final readonly class CalendarRange
{
    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
    ) {}

    /**
     * Reads `from`/`to` off the query string, keeping only real calendar days.
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            self::readBound($request, 'from'),
            self::readBound($request, 'to'),
        );
    }

    private static function readBound(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        // createFromFormat alone accepts `2026-13-01` and `2026-02-31` by rolling
        // them over into the next month, so the round-trip check is what makes
        // this reject a day that does not exist.
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }

    public function isEmpty(): bool
    {
        return $this->from === null && $this->to === null;
    }

    public function isReversed(): bool
    {
        return $this->from !== null && $this->to !== null && $this->from > $this->to;
    }

    /**
     * Query parameters for the upstream call, with absent bounds omitted.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'from' => $this->from,
            'to' => $this->to,
        ], fn (?string $value): bool => $value !== null);
    }
}
