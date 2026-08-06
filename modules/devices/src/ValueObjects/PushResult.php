<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\ValueObjects;

/**
 * What a transport reports back about one send attempt.
 *
 * Four outcomes, and the distinctions between them all cost something real if collapsed:
 *
 *  - DELIVERED         accepted by the provider.
 *  - TRANSIENT failure the token may still be good; retry with backoff.
 *  - PERMANENT failure the provider says this token is dead. Retire it NOW rather than
 *                      spending twelve retries over eleven hours on a handset that was
 *                      wiped six months ago.
 *  - NOT CONFIGURED    there is no transport at all. Distinct from a permanent failure
 *                      because it says nothing about the DEVICE: treating it as one
 *                      would retire every handset in the estate the first time anyone
 *                      pushed without FCM credentials set, which is exactly what an
 *                      unconfigured install does.
 */
final readonly class PushResult
{
    private function __construct(
        public bool $delivered,
        public bool $permanent,
        public bool $configured,
        public ?int $code = null,
        public ?string $error = null,
    ) {}

    public static function delivered(?int $code = null): self
    {
        return new self(delivered: true, permanent: false, configured: true, code: $code);
    }

    /**
     * The send failed but the token may still be good — retry with backoff.
     */
    public static function transientFailure(string $error, ?int $code = null): self
    {
        return new self(delivered: false, permanent: false, configured: true, code: $code, error: $error);
    }

    /**
     * The provider says this token will never work again. Retire it; do not retry.
     */
    public static function permanentFailure(string $error, ?int $code = null): self
    {
        return new self(delivered: false, permanent: true, configured: true, code: $code, error: $error);
    }

    /**
     * No transport is wired up. The notification is settled as Skipped and the device's
     * health is left completely untouched — nothing was learned about the handset.
     */
    public static function notConfigured(string $error = 'No push transport is configured.'): self
    {
        return new self(delivered: false, permanent: false, configured: false, error: $error);
    }
}
