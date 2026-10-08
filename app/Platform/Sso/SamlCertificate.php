<?php

declare(strict_types=1);

namespace App\Platform\Sso;

use Carbon\CarbonImmutable;
use OpenSSLCertificate;

/**
 * An identity provider's SAML signing certificate, read — what an administrator needs to
 * know about one without reading PEM: whose it is, which one it is, and when it stops
 * working.
 *
 * Built from whatever an administrator pastes or a metadata document carries: a PEM block,
 * or the bare base64 body metadata puts inside `<ds:X509Certificate>`. Anything OpenSSL
 * cannot read is not a certificate, and {@see parse()} answers null rather than a guess —
 * a connection keeps working on what the framework accepts, and the console simply cannot
 * say when that expires.
 */
final readonly class SamlCertificate
{
    private function __construct(
        public string $pem,
        public string $fingerprint,
        public ?string $subject,
        public ?string $issuer,
        public CarbonImmutable $notBefore,
        public CarbonImmutable $notAfter,
        public ?int $keyBits,
        public ?string $keyType,
    ) {}

    public static function parse(string $value): ?self
    {
        $pem = self::normalize($value);

        if ($pem === null) {
            return null;
        }

        $certificate = @openssl_x509_read($pem);

        if (! $certificate instanceof OpenSSLCertificate) {
            return null;
        }

        $info = openssl_x509_parse($certificate);
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');

        if (! is_array($info) || ! is_string($fingerprint) || ! is_int($info['validFrom_time_t'] ?? null) || ! is_int($info['validTo_time_t'] ?? null)) {
            return null;
        }

        [$bits, $type] = self::key($certificate);

        return new self(
            pem: $pem,
            fingerprint: strtoupper(implode(':', str_split($fingerprint, 2))),
            subject: self::commonName($info['subject'] ?? null),
            issuer: self::commonName($info['issuer'] ?? null),
            notBefore: CarbonImmutable::createFromTimestampUTC($info['validFrom_time_t']),
            notAfter: CarbonImmutable::createFromTimestampUTC($info['validTo_time_t']),
            keyBits: $bits,
            keyType: $type,
        );
    }

    /**
     * Whole days until it expires — negative once it has, zero on its last day.
     */
    public function daysRemaining(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();

        return (int) floor(($this->notAfter->getTimestamp() - $now->getTimestamp()) / 86400);
    }

    public function isExpired(?CarbonImmutable $now = null): bool
    {
        return $this->notAfter->lessThanOrEqualTo($now ?? CarbonImmutable::now());
    }

    /** Whether a PEM or bare body is this very certificate. */
    public function matches(string $value): bool
    {
        return self::parse($value)?->fingerprint === $this->fingerprint;
    }

    /**
     * A PEM block from either form, wrapped at 64 columns; null when there is no base64
     * body to wrap at all.
     */
    public static function normalize(string $value): ?string
    {
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $value);

        if (! is_string($body) || $body === '' || preg_match('/^[A-Za-z0-9+\/=]+$/', $body) !== 1) {
            return null;
        }

        return "-----BEGIN CERTIFICATE-----\n".chunk_split($body, 64, "\n")."-----END CERTIFICATE-----\n";
    }

    /**
     * @return array{0: ?int, 1: ?string}
     */
    private static function key(OpenSSLCertificate $certificate): array
    {
        $key = openssl_pkey_get_public($certificate);

        if ($key === false) {
            return [null, null];
        }

        $details = openssl_pkey_get_details($key);

        if (! is_array($details)) {
            return [null, null];
        }

        $bits = is_int($details['bits'] ?? null) ? $details['bits'] : null;

        $type = match ($details['type'] ?? null) {
            OPENSSL_KEYTYPE_RSA => 'RSA',
            OPENSSL_KEYTYPE_EC => 'EC',
            default => null,
        };

        return [$bits, $type];
    }

    private static function commonName(mixed $name): ?string
    {
        if (! is_array($name)) {
            return null;
        }

        $cn = $name['CN'] ?? $name['O'] ?? null;

        if (is_array($cn)) {
            $cn = $cn[0] ?? null;
        }

        return is_string($cn) && $cn !== '' ? $cn : null;
    }
}
