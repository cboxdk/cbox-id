<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\ValueObjects;

use Cbox\Id\Devices\Enums\ApprovalKind;

/**
 * Where a request for approval came from, as the person's browser that filed it told us.
 *
 * Coarse by design: a city, region and country from the edge, the user agent (shown only
 * as "Chrome on macOS"), the web host that asked. Never the address and never coordinates
 * — this is shown on a lock screen and returned to the phone. A request a server filed (a
 * CIBA request, a key's held action) has a kind and nothing else: where a server sits says
 * nothing about where the person is.
 */
final readonly class ApprovalOrigin
{
    public function __construct(
        public ApprovalKind $kind,
        /** The app being signed in to, or asking; null when there is nothing honest to name. */
        public ?string $app = null,
        public ?string $city = null,
        /** ISO 3166-1 alpha-2, upper case. */
        public ?string $countryCode = null,
        public ?string $region = null,
        public ?string $userAgent = null,
        /** The web host that asked; browser flows only. */
        public ?string $host = null,
    ) {}

    /** Whether anything at all is known beyond the kind. */
    public function isEmpty(): bool
    {
        return $this->app === null && $this->countryCode === null && $this->userAgent === null && $this->host === null;
    }

    /**
     * The columns on the approval request row.
     *
     * @return array<string, string|null>
     */
    public function toColumns(): array
    {
        $cut = static fn (?string $value, int $max): ?string => $value === null ? null : mb_substr($value, 0, $max);

        return [
            'origin_kind' => $this->kind->value,
            'origin_app' => $cut($this->app, 160),
            'origin_country_code' => $this->countryCode,
            'origin_city' => $this->countryCode === null ? null : $cut($this->city, 160),
            'origin_region' => $this->countryCode === null ? null : $cut($this->region, 160),
            'origin_user_agent' => $cut($this->userAgent, 512),
            'origin_host' => $this->kind->fromBrowser() ? $cut($this->host, 253) : null,
        ];
    }

    /**
     * Read back from the row, or null when nothing was recorded (a CIBA request, or one
     * filed before these columns existed).
     *
     * @param  callable(string): mixed  $column
     */
    public static function fromColumns(callable $column): ?self
    {
        $text = static fn (string $key): ?string => is_string($value = $column($key)) && $value !== '' ? $value : null;
        $kind = ApprovalKind::tryFrom($text('origin_kind') ?? '');

        if ($kind === null) {
            return null;
        }

        $country = $text('origin_country_code');
        $country = $country !== null && preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;

        return new self(
            kind: $kind,
            app: $text('origin_app'),
            // A city without its country is never shown: it reads as the wrong place.
            city: $country === null ? null : $text('origin_city'),
            countryCode: $country,
            region: $country === null ? null : $text('origin_region'),
            userAgent: $text('origin_user_agent'),
            host: $kind->fromBrowser() ? $text('origin_host') : null,
        );
    }
}
