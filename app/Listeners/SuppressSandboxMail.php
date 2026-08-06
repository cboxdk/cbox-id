<?php

declare(strict_types=1);

namespace App\Listeners;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;

/**
 * Stops real email leaving a sandbox environment. Sandbox realms are for testing,
 * so their sign-ups, invitations, and resets must never reach real inboxes.
 * Returning false from a MessageSending listener cancels delivery; the attempt is
 * logged so it's still observable during development.
 *
 * Mail in the first-party flows is sent synchronously, so the request's resolved
 * environment is in scope here. If the environment can't be resolved (e.g. a queued
 * send with no environment context), delivery is allowed rather than silently
 * dropped — failing open on suppression, never suppressing production.
 */
final class SuppressSandboxMail
{
    public function __construct(private readonly EnvironmentContext $context) {}

    public function handle(MessageSending $event): bool
    {
        $key = $this->context->current()?->environmentKey();

        if ($key === null) {
            return true;
        }

        $isSandbox = Environment::query()->whereKey($key)->where('type', 'sandbox')->exists();

        if (! $isSandbox) {
            return true;
        }

        Log::info('Suppressed outbound email from sandbox environment.', [
            'environment' => $key,
            'to' => array_map(static fn ($address) => $address->getAddress(), $event->message->getTo()),
            'subject' => $event->message->getSubject(),
        ]);

        return false;
    }
}
