<?php

declare(strict_types=1);

namespace App\Platform\Operators;

use Cbox\Id\Platform\Models\PlatformOperator;
use Illuminate\Contracts\Cache\Repository;

/**
 * BREAK-GLASS PASSKEY ENROLMENT LINKS — how an operator who can no longer open the
 * operator plane gets a passkey without the plane being made weaker for everybody.
 *
 * Issued only from the command line (`cbox-id:operator:passkey-link`), by somebody who
 * already holds the deployment's shell, for one named, active operator. The link carries a
 * random token; only its SHA-256 is kept, with the operator it is for, until it is used or
 * it expires. Used once: the passkey ceremony that succeeds spends it.
 *
 * What the link grants is one passkey on that operator's account, and nothing else: no
 * session, no password change. The operator then signs in with it at `/login/direct`.
 */
final readonly class OperatorPasskeyLinks
{
    public const int MAX_MINUTES = 60;

    public function __construct(private Repository $cache) {}

    /**
     * @return array{token: string, expires_at: int}
     */
    public function issue(PlatformOperator $operator, int $minutes): array
    {
        $minutes = max(1, min(self::MAX_MINUTES, $minutes));
        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes($minutes)->getTimestamp();

        $this->cache->put(self::key($token), [
            'operator' => (string) $operator->id,
            'subject' => (string) $operator->subject_id,
            'email' => $operator->email,
            'expires_at' => $expiresAt,
        ], now()->addMinutes($minutes));

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * Who a live link is for, without spending it.
     *
     * @return array{operator: string, subject: string, email: string, expires_at: int}|null
     */
    public function peek(string $token): ?array
    {
        return self::read($this->cache->get(self::key($token)));
    }

    /**
     * Spend the link. Null when it was already used or has expired: of two requests racing
     * for it, one gets the grant.
     *
     * @return array{operator: string, subject: string, email: string, expires_at: int}|null
     */
    public function spend(string $token): ?array
    {
        return self::read($this->cache->pull(self::key($token)));
    }

    /**
     * Put back a link whose ceremony failed after it was spent, for what is left of its
     * life, so a cancelled browser prompt does not cost the operator their way back in.
     *
     * @param  array{operator: string, subject: string, email: string, expires_at: int}  $grant
     */
    public function restore(string $token, array $grant): void
    {
        $left = $grant['expires_at'] - now()->getTimestamp();

        if ($left > 0) {
            $this->cache->put(self::key($token), $grant, $left);
        }
    }

    /**
     * @return array{operator: string, subject: string, email: string, expires_at: int}|null
     */
    private static function read(mixed $stored): ?array
    {
        if (! is_array($stored)
            || ! is_string($stored['operator'] ?? null)
            || ! is_string($stored['subject'] ?? null) || $stored['subject'] === ''
            || ! is_string($stored['email'] ?? null)
            || ! is_int($stored['expires_at'] ?? null)
            || $stored['expires_at'] < now()->getTimestamp()) {
            return null;
        }

        return ['operator' => $stored['operator'], 'subject' => $stored['subject'], 'email' => $stored['email'], 'expires_at' => $stored['expires_at']];
    }

    private static function key(string $token): string
    {
        return 'operator-passkey-link:'.hash('sha256', $token);
    }
}
