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
 * accept-language / accept-encoding it advertises) — hashed, never stored raw. The
 * first time a fingerprint appears for an account it scores; thereafter it's
 * remembered (bounded set, TTL) and stays quiet.
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

    public function evaluate(RiskContext $context): ?SignalResult
    {
        $subject = $this->subjectKey->for($context);

        if ($subject === null || $context->userAgent === null || $context->userAgent === '') {
            return null;
        }

        $fingerprint = substr(hash('sha256', implode('|', [
            $context->userAgent,
            $context->header('accept-language') ?? '',
            $context->header('accept-encoding') ?? '',
        ])), 0, 32);

        $bucket = 'cbox-riskplus:devices:'.$subject;
        $known = $this->readKnown($this->cache->get($bucket));

        if (in_array($fingerprint, $known, true)) {
            return null;
        }

        // Remember it (cap the set, newest-wins) so it only scores once.
        $known[] = $fingerprint;
        $this->cache->put($bucket, array_slice($known, -$this->maxDevices), $this->ttlSeconds);

        // First device ever for this account is enrolment, not a new-device event.
        if (count($known) === 1) {
            return null;
        }

        return new SignalResult(
            $this->key(),
            $this->points,
            'sign-in from a device not previously seen for this account',
        );
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
