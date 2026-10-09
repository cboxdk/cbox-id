<?php

declare(strict_types=1);

namespace App\Platform\Radar;

use App\Platform\RiskTrail;
use Cbox\Id\Kernel\Tenancy\Concerns\ResolvesEnvironment;

/**
 * Every identifier Radar keeps, keyed and domain-separated — never the raw value.
 *
 * The address and the IP are hashed exactly as the decision trail hashes them
 * ({@see RiskTrail}), so an operator filtering the explorer by an address they already know
 * finds the rows the trail wrote. Everything Radar REMEMBERS about an account (its devices,
 * its last location, its counters) is keyed under the ENVIRONMENT as well: the secret is
 * `app.key`, the same for every environment of a deployment, and the address is supplied
 * pre-authentication — without the environment in the key, an attacker on their own trial
 * tenant could write into the history of a victim's account on another.
 */
final readonly class RadarPseudonyms
{
    use ResolvesEnvironment;

    public function ip(?string $ip): string
    {
        return $this->hmac('ip', (string) $ip);
    }

    public function email(string $email): string
    {
        return $this->hmac('email', self::canonicalEmail($email));
    }

    /** The account an attempt names, within this environment. */
    public function subject(string $email): string
    {
        return $this->hmac('radar-subject', $this->environment().'|'.self::canonicalEmail($email));
    }

    /** A device cookie's identifier, within this environment. */
    public function device(string $cookieId): string
    {
        return $this->hmac('radar-device', $this->environment().'|'.$cookieId);
    }

    /** A browser's normalised user-agent and client hints, within this environment. */
    public function fingerprint(string $material): string
    {
        return $this->hmac('radar-fingerprint', $this->environment().'|'.$material);
    }

    /** A cache bucket for a counter, within this environment. */
    public function counter(string $kind, string $value): string
    {
        return $this->hmac('radar-counter', $this->environment().'|'.$kind.'|'.$value);
    }

    public function environment(): string
    {
        return $this->environments()->current()?->environmentKey() ?? 'no-env';
    }

    public static function canonicalEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function hmac(string $kind, string $value): string
    {
        $key = config('app.key');

        return hash_hmac('sha256', $kind.':'.$value, is_string($key) ? $key : '');
    }
}
