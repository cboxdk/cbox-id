<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Decorators;

use Cbox\Id\Devices\Contracts\PushDispatcher;
use Cbox\Id\Devices\Enums\NotificationKind;
use Cbox\Id\Devices\Support\DeviceConfig;
use Cbox\Id\Devices\ValueObjects\PushPayload;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\AuthorizedGrant;
use Cbox\Id\OAuthServer\ValueObjects\BackchannelAuthenticationResult;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pushes a CIBA approval prompt to the user's handsets the moment the request is made.
 *
 * WHY A DECORATOR AND NOT AN EVENT LISTENER
 * -----------------------------------------
 * `request()` already emits `oauth.backchannel_authentication_requested`, and listening
 * for it would be the obvious wiring. But that event goes to the transactional outbox,
 * and `EventDelivered` is only dispatched when the relay next runs — every minute by
 * default. A CIBA request lives for 300 seconds, and someone is sitting there waiting
 * for their phone to buzz. Spending up to a fifth of the window on a scheduler tick is
 * not a trade worth making for a person's login.
 *
 * Decorating the contract in the container costs nothing structurally — the binding is
 * a plain singleton with a single implementation — and the prompt goes out in the same
 * request that created it. Security alerts, which nobody is waiting on, keep using the
 * ordinary relay path.
 *
 * FAILING OPEN IS THE POINT
 * -------------------------
 * Every push concern here is wrapped. A CIBA request that succeeded must not be turned
 * into a failure because a handset was unreachable, a transport was misconfigured, or
 * the devices tables have not been migrated yet: the client can still poll, and the
 * console's own approval page still works. Notification is an enhancement to CIBA, not
 * a dependency of it — and the FailOpen test exists to keep it that way.
 */
final class PushNotifyingBackchannelAuthentication implements BackchannelAuthentication
{
    public function __construct(
        private readonly BackchannelAuthentication $inner,
        /**
         * The container rather than a PushDispatcher, so nothing in the push path is
         * constructed until a request actually needs notifying. Resolving the dispatcher
         * eagerly would pull in SecretBox, which throws when no crypto key is
         * configured — making the mere act of resolving BackchannelAuthentication fail,
         * outside the try/catch below and even with this module turned off.
         */
        private readonly Container $container,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  list<string>  $scopes
     */
    public function request(
        Client $client,
        array $scopes,
        string $loginHint,
        ?string $bindingMessage = null,
        ?string $nonce = null,
        ?int $requestedExpiry = null,
    ): BackchannelAuthenticationResult {
        $result = $this->inner->request($client, $scopes, $loginHint, $bindingMessage, $nonce, $requestedExpiry);

        $this->notify($client, $result);

        return $result;
    }

    public function approve(string $requestId, string $subjectId, ?string $organizationId = null): bool
    {
        return $this->inner->approve($requestId, $subjectId, $organizationId);
    }

    public function deny(string $requestId, string $subjectId): bool
    {
        return $this->inner->deny($requestId, $subjectId);
    }

    public function redeem(string $clientId, string $authReqId): AuthorizedGrant
    {
        return $this->inner->redeem($clientId, $authReqId);
    }

    private function notify(Client $client, BackchannelAuthenticationResult $result): void
    {
        try {
            if (! DeviceConfig::bool('id-devices.enabled', false)) {
                return;
            }

            if (! $this->clientMayNotify($client)) {
                // A hard refusal, not a log-and-continue. Any client holding the CIBA
                // grant can otherwise spray approval prompts at a person's phone, and
                // push fatigue attacks the human-in-the-loop guarantee that is CIBA's
                // entire reason to exist. The request itself still stands — the client
                // may poll, and the console can approve — it simply produces no push.
                $this->logger->warning('Suppressed a CIBA push from a client outside the devices allowlist.', [
                    'client_id' => $client->client_id,
                    'request_id' => $result->requestId,
                ]);

                return;
            }

            $this->container->make(PushDispatcher::class)->dispatch(
                subjectId: $result->subjectId,
                kind: NotificationKind::Approval,
                payload: $this->payload($result),
                expiresAt: Carbon::now()->addSeconds($result->expiresIn),
            );
        } catch (Throwable $e) {
            // Fail open. See the class docblock: an unreachable handset must never turn
            // a successful CIBA request into a failed one.
            $this->logger->warning('Could not push a CIBA approval prompt.', [
                'request_id' => $result->requestId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * What the handset is told.
     *
     * The visible title and body are deliberately uninformative. They render on the
     * LOCK SCREEN, readable by anyone holding the phone, and the one thing a CIBA
     * prompt must protect is the binding message — which is the transaction description
     * ("Transfer DKK 4,200 to ACME ApS"), the very detail an attacker would most like to
     * read and the one the user is meant to verify. It is fetched over TLS after the app
     * opens and the user has authenticated, never pushed.
     *
     * The data payload carries routing only, and the app treats it as untrusted: it
     * re-fetches the approval by id and renders that response, not this.
     */
    private function payload(BackchannelAuthenticationResult $result): PushPayload
    {
        $data = ['kind' => NotificationKind::Approval->value];

        if (DeviceConfig::bool('id-devices.include_request_id_in_push', true)) {
            $data['request_id'] = $result->requestId;
            $data['url'] = 'cboxauth://approvals/'.$result->requestId;
        } else {
            $data['url'] = 'cboxauth://approvals';
        }

        return new PushPayload(
            title: 'Approval request',
            body: 'Open Cbox ID to review a pending request.',
            data: $data,
            // Collapse on the request id so a re-push replaces the existing banner
            // rather than stacking a second one for the same decision.
            collapseKey: $result->requestId,
        );
    }

    private function clientMayNotify(Client $client): bool
    {
        $allowlist = DeviceConfig::strings('id-devices.ciba.client_allowlist');

        return $allowlist === [] || in_array($client->client_id, $allowlist, true);
    }
}
