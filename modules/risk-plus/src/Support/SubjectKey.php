<?php

declare(strict_types=1);

namespace Cbox\Id\RiskPlus\Support;

use Cbox\Risk\ValueObjects\RiskContext;

/**
 * Derives the per-account key that the adaptive signals track history against.
 *
 * Impossible-travel and new-device are per-*account* checks, so they key on who is
 * signing in — the email when present, or an explicit `subject` attribute the host
 * can set on the context. The value is HMAC'd with the app key before it's ever
 * used as a cache key, so no raw email/identifier is stored (same discipline as
 * laravel-risk's velocity signal). Returns null when there's nothing to key on, so
 * the signal cleanly no-ops rather than tracking anonymous traffic.
 */
readonly class SubjectKey
{
    public function __construct(private string $secret) {}

    public function for(RiskContext $context): ?string
    {
        $subject = $context->attribute('subject');
        $raw = is_string($subject) && $subject !== '' ? $subject : $context->email;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return hash_hmac('sha256', strtolower($raw), $this->secret);
    }
}
