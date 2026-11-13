<?php

declare(strict_types=1);

namespace Cbox\Id\RiskPlus\Signals;

use Cbox\Id\RiskPlus\Support\SubjectKey;
use Cbox\Risk\Contracts\Signal;
use Cbox\Risk\ValueObjects\RiskContext;
use Cbox\Risk\ValueObjects\SignalResult;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Scores a sign-in from a device this account has never been seen on. The device
 * is fingerprinted from stable request characteristics (user agent + the
 * accept-language / accept-encoding it advertises) — hashed, never stored raw. A
 * fingerprint scores until a sign-in from it SUCCEEDS ({@see remember()}); thereafter
 * it's remembered (bounded set, TTL) and stays quiet.
 *
 * Fails open: no subject or no user agent yields no signal. The very first sign-in
 * an account ever makes is treated as known (there's no prior device to be "new"
 * relative to), so enrolment isn't punished — only *subsequent* new devices score.
 */
class NewDeviceSignal implements Signal
{
    public function __construct(
        private readonly SubjectKey $subjectKey,
        private readonly Cache $cache,
        private readonly float $points = 35.0,
        private readonly int $maxDevices = 20,
        private readonly int $ttlSeconds = 7776000,
    ) {}

    public function key(): string
    {
        return 'device.new';
    }

    /**
     * READ-ONLY. The assessment runs before the credential is checked, on whatever address
     * was typed, so nothing it sees may become history: a device learned here was known on
     * the very next try, and an attacker holding only the password passed the new-device
     * step-up by submitting twice. A device is learned in {@see remember()}, once a sign-in
     * from it has fully succeeded.
     */
    public function evaluate(RiskContext $context): ?SignalResult
    {
        $subject = $this->subjectKey->for($context);

        if ($subject === null || $context->userAgent === null || $context->userAgent === '') {
            return null;
        }

        $known = $this->readKnown($this->cache->get($this->bucket($subject)));

        // No device remembered yet: the first sign-in is enrolment, not a new-device event.
        if ($known === [] || in_array($this->fingerprint($context), $known, true)) {
            return null;
        }

        return new SignalResult(
            $this->key(),
            $this->points,
            'sign-in from a device not previously seen for this account',
        );
    }

    /**
     * A sign-in from this device SUCCEEDED, every factor and step-up included: remember it
     * (bounded set, newest wins) so it stays quiet from now on.
     */
    public function remember(RiskContext $context): void
    {
        $subject = $this->subjectKey->for($context);

        if ($subject === null || $context->userAgent === null || $context->userAgent === '') {
            return;
        }

        $bucket = $this->bucket($subject);
        $known = $this->readKnown($this->cache->get($bucket));
        $fingerprint = $this->fingerprint($context);

        if (in_array($fingerprint, $known, true)) {
            return;
        }

        $known[] = $fingerprint;
        $this->cache->put($bucket, array_slice($known, -$this->maxDevices), $this->ttlSeconds);
    }

    private function bucket(string $subject): string
    {
        return 'cbox-riskplus:devices:'.$subject;
    }

    private function fingerprint(RiskContext $context): string
    {
        return substr(hash('sha256', implode('|', [
            $context->userAgent,
            $context->header('accept-language') ?? '',
            $context->header('accept-encoding') ?? '',
        ])), 0, 32);
    }

    /**
     * @return list<string>
     */
    private function readKnown(mixed $stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        $known = [];

        foreach ($stored as $value) {
            if (is_string($value)) {
                $known[] = $value;
            }
        }

        return $known;
    }
}
