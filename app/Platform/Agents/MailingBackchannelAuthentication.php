<?php

declare(strict_types=1);

namespace App\Platform\Agents;

use Cbox\Id\Devices\Support\DeviceConfig;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\AuthorizedGrant;
use Cbox\Id\OAuthServer\ValueObjects\BackchannelAuthenticationResult;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mail the person a CIBA request is for when no phone will tell them ({@see ApprovalMail}).
 *
 * A decorator for the same reason as the devices module's push: the domain event reaches
 * listeners on the relay's next tick, and a person is waiting on a five-minute window.
 *
 * The link is the Approvals page on the host that took the request — the issuer the client
 * called, which is the environment the person signs in to.
 */
final readonly class MailingBackchannelAuthentication implements BackchannelAuthentication
{
    public function __construct(
        private BackchannelAuthentication $inner,
        private ApprovalMail $mail,
        private LoggerInterface $logger,
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
        ?string $resource = null,
    ): BackchannelAuthenticationResult {
        $result = $this->inner->request($client, $scopes, $loginHint, $bindingMessage, $nonce, $requestedExpiry, $resource);

        // Fails open, like the mail itself: the request was filed, and that is the answer.
        try {
            $name = $client->name !== '' ? $client->name : (string) $client->client_id;

            $this->mail->notify(
                subjectId: $result->subjectId,
                app: $name,
                url: route('approvals'),
                expiresAt: Carbon::now()->addSeconds($result->expiresIn),
                pushAllowed: $this->pushAllowed($client),
            );
        } catch (Throwable $e) {
            $this->logger->warning('Could not mail a CIBA approval request.', [
                'request_id' => $result->requestId,
                'exception' => $e->getMessage(),
            ]);
        }

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

    /** The devices module's allowlist: a client outside it gets no push, so mail instead. */
    private function pushAllowed(Client $client): bool
    {
        $allowlist = DeviceConfig::strings('id-devices.ciba.client_allowlist');

        return $allowlist === [] || in_array($client->client_id, $allowlist, true);
    }
}
