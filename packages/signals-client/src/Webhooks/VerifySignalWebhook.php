<?php

declare(strict_types=1);

namespace Cbox\Signals\Webhooks;

use Cbox\Signals\Exceptions\SignalsFailure;
use Cbox\WebhookSignature\Schemes\CboxScheme;
use Cbox\WebhookSignature\ValueObjects\SecretSet;
use Cbox\WebhookSignature\ValueObjects\VerificationContext;
use Cbox\WebhookSignature\ValueObjects\WebhookMessage;

class VerifySignalWebhook
{
    public function __construct(private readonly CboxScheme $scheme) {}

    /** @param array<string,string> $headers */
    public function verify(string $body, array $headers, SecretSet $secrets, string $project, string $environment, int $now): SignalEnvelope
    {
        if (strlen($body) > 1048576) {
            throw new SignalsFailure('payload_too_large', 413);
        }
        $message = WebhookMessage::make($body, $headers);
        $this->scheme->verify($message, $secrets, new VerificationContext(now: $now, toleranceSeconds: 300));
        $payload = $message->json();
        if ($payload === null) {
            throw new SignalsFailure('invalid_envelope', 422);
        }
        $envelope = SignalEnvelope::fromArray($payload, $body);
        if ($envelope->project !== $project || $envelope->environment !== $environment) {
            throw new SignalsFailure('scope_mismatch', 403);
        }

        return $envelope;
    }
}
