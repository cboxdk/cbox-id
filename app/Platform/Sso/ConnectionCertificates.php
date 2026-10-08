<?php

declare(strict_types=1);

namespace App\Platform\Sso;

use App\Actions\Sso\SsoFields;
use Carbon\CarbonImmutable;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;

/**
 * A SAML connection's signing certificates — the one it trusts now, and any staged beside
 * it for a rollover — read, staged and promoted.
 *
 * THE ROLLOVER, which is the whole reason for staging. An identity provider rotates its
 * signing key on its own schedule; the day it starts signing with the new one, a connection
 * that only knows the old one refuses everybody. The framework already verifies an assertion
 * against the primary certificate AND every `idp_x509cert_extra`, so renewal is three moves
 * with no outage between them:
 *
 *  1. STAGE the new certificate ({@see stage()}) — now both are trusted;
 *  2. switch the identity provider over to signing with it;
 *  3. PROMOTE it ({@see promote()}) — it becomes the primary, and the old one stops being
 *     trusted.
 *
 * The certificates are sealed in the connection's config with every other secret, and are
 * re-sealed here the same way the connection's own update seals them. Nothing here ever
 * returns a certificate's PEM to a caller that did not just send it; what it returns is
 * {@see SamlCertificate}'s description.
 */
final readonly class ConnectionCertificates
{
    public function __construct(private SecretBox $secretBox) {}

    /**
     * Every signing certificate on file, primary first. `certificate` is null for one this
     * cannot read — kept and trusted by the framework all the same.
     *
     * @return list<array{role: 'primary'|'staged', certificate: ?SamlCertificate, raw: string}>
     */
    public function all(Connection $connection): array
    {
        if ($connection->type !== ConnectionType::Saml) {
            return [];
        }

        $config = SsoFields::config($connection);
        $certificates = [];

        $primary = $config['idp_x509cert'] ?? null;

        if (is_string($primary) && $primary !== '') {
            $certificates[] = ['role' => 'primary', 'certificate' => SamlCertificate::parse($primary), 'raw' => $primary];
        }

        foreach (self::extras($config) as $extra) {
            $certificates[] = ['role' => 'staged', 'certificate' => SamlCertificate::parse($extra), 'raw' => $extra];
        }

        return $certificates;
    }

    /**
     * When the connection stops working if nothing is done: the LATEST expiry among the
     * certificates it trusts, because any one of them verifies an assertion — a renewal
     * staged in time means the primary's expiry is no longer the connection's. Null when
     * none can be read.
     */
    public function effectiveExpiry(Connection $connection): ?SamlCertificate
    {
        $latest = null;

        foreach ($this->all($connection) as $entry) {
            $certificate = $entry['certificate'];

            if ($certificate !== null && ($latest === null || $certificate->notAfter->greaterThan($latest->notAfter))) {
                $latest = $certificate;
            }
        }

        return $latest;
    }

    /**
     * Trust $certificate alongside the current one. False when it is already on file —
     * staging it twice would list it twice — or when the sealed config cannot be opened,
     * which a write here would otherwise replace with nothing but the certificate.
     */
    public function stage(Connection $connection, SamlCertificate $certificate): bool
    {
        $config = SsoFields::config($connection);

        if ($config === []) {
            return false;
        }

        foreach ($this->all($connection) as $entry) {
            if ($entry['certificate']?->fingerprint === $certificate->fingerprint) {
                return false;
            }
        }

        $config['idp_x509cert_extra'] = [...self::extras($config), $certificate->pem];

        $this->seal($connection, $config);

        return true;
    }

    /**
     * Make the staged certificate with $fingerprint the primary, and stop trusting the
     * primary it replaces. Other staged certificates stay staged. False when no staged
     * certificate has that fingerprint.
     */
    public function promote(Connection $connection, string $fingerprint): bool
    {
        $config = SsoFields::config($connection);

        if ($config === []) {
            return false;
        }
        $wanted = strtoupper(trim($fingerprint));
        $promoted = null;
        $remaining = [];

        foreach (self::extras($config) as $extra) {
            if ($promoted === null && SamlCertificate::parse($extra)?->fingerprint === $wanted) {
                $promoted = $extra;

                continue;
            }

            $remaining[] = $extra;
        }

        if ($promoted === null) {
            return false;
        }

        $config['idp_x509cert'] = $promoted;
        $config['idp_x509cert_extra'] = $remaining;

        if ($remaining === []) {
            unset($config['idp_x509cert_extra']);
        }

        $this->seal($connection, $config);

        return true;
    }

    /**
     * The certificates a list of them describes, for an answer: never the PEM.
     *
     * @param  list<array{role: 'primary'|'staged', certificate: ?SamlCertificate, raw: string}>  $entries
     * @return list<array<string, mixed>>
     */
    public static function present(array $entries, ?CarbonImmutable $now = null): array
    {
        return array_map(static fn (array $entry): array => self::describe($entry['certificate'], $entry['role'], $now), $entries);
    }

    /**
     * @return array<string, mixed>
     */
    public static function describe(?SamlCertificate $certificate, string $role, ?CarbonImmutable $now = null): array
    {
        return [
            'role' => $role,
            'readable' => $certificate !== null,
            'fingerprint_sha256' => $certificate?->fingerprint,
            'subject' => $certificate?->subject,
            'issuer' => $certificate?->issuer,
            'not_before' => $certificate?->notBefore->toIso8601ZuluString(),
            'not_after' => $certificate?->notAfter->toIso8601ZuluString(),
            'days_remaining' => $certificate?->daysRemaining($now),
            'expired' => $certificate?->isExpired($now),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function extras(array $config): array
    {
        $extras = $config['idp_x509cert_extra'] ?? null;

        return is_array($extras)
            ? array_values(array_filter($extras, static fn (mixed $value): bool => is_string($value) && $value !== ''))
            : [];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function seal(Connection $connection, array $config): void
    {
        $connection->config_encrypted = $this->secretBox->seal(json_encode($config, JSON_THROW_ON_ERROR), $connection->secretContext());
        $connection->save();
    }
}
