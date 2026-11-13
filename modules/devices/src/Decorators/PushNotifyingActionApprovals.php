<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Decorators;

use Cbox\Id\Devices\Contracts\ApprovalOriginResolver;
use Cbox\Id\Devices\Contracts\PushDispatcher;
use Cbox\Id\Devices\Enums\ApprovalKind;
use Cbox\Id\Devices\Enums\NotificationKind;
use Cbox\Id\Devices\Support\ApprovalKindHint;
use Cbox\Id\Devices\Support\ApprovalOrigins;
use Cbox\Id\Devices\Support\ApprovalPushText;
use Cbox\Id\Devices\Support\DeviceConfig;
use Cbox\Id\Devices\ValueObjects\ApprovalOrigin;
use Cbox\Id\OAuthServer\Contracts\ActionApprovals;
use Cbox\Id\OAuthServer\Enums\ActionApprovalStatus;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\ActionApprovalRequest;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pushes a request to approve ONE action to the approver's handset, the moment it is filed.
 *
 * The sibling of {@see PushNotifyingBackchannelAuthentication}, for the same reason and with
 * the same fail-open rule: an unreachable phone must never turn a held action into a failed
 * one — the approval still stands and is answerable from the console. No client allowlist:
 * these are filed only by the platform's own step-up client, never by a third party, so the
 * push-fatigue guard the CIBA decorator needs has nothing to guard here.
 */
final class PushNotifyingActionApprovals implements ActionApprovals
{
    public function __construct(
        private readonly ActionApprovals $inner,
        private readonly Container $container,
        private readonly LoggerInterface $logger,
    ) {}

    public function request(Client $client, string $subjectId, string $bindingMessage, string $actionDigest, ?int $ttlSeconds = null): ActionApprovalRequest
    {
        // Pulled before the request is filed, so a stated kind is spent whether or not the
        // filing succeeds and can never describe a later request in the same process.
        $kind = $this->hintedKind();

        $request = $this->inner->request($client, $subjectId, $bindingMessage, $actionDigest, $ttlSeconds);

        try {
            $resolver = $this->container->make(ApprovalOriginResolver::class);
            // Recorded on the request's row while the browser that asked is still the
            // request being handled; the push and the approvals API both read this.
            $origins = $this->container->make(ApprovalOrigins::class);
            $origins->put($request->requestId, $resolver->capture($kind));
            // Read back, so the push says exactly what the approvals API will.
            $origin = $origins->find($request->requestId) ?? new ApprovalOrigin($kind);

            if (DeviceConfig::bool('id-devices.enabled', false)) {
                $this->container->make(PushDispatcher::class)->dispatch(
                    subjectId: $subjectId,
                    kind: NotificationKind::Approval,
                    payload: $this->container->make(ApprovalPushText::class)->payload(
                        origin: $origin,
                        locale: $resolver->localeFor($subjectId, $kind),
                        expiresAt: $request->expiresAt,
                        routing: [
                            'kind' => NotificationKind::Approval->value,
                            'request_id' => $request->requestId,
                            'url' => 'com.cboxid.authenticator://approvals/'.$request->requestId,
                        ],
                        collapseKey: $request->requestId,
                        // A sign-in's message carries the code the person compares; only a
                        // plain action is named, and without its code.
                        action: $kind === ApprovalKind::Action ? $request->bindingMessage : null,
                    ),
                    expiresAt: Carbon::instance($request->expiresAt),
                );
            }
        } catch (Throwable $e) {
            $this->logger->warning('Could not push an action approval prompt.', [
                'request_id' => $request->requestId,
                'exception' => $e->getMessage(),
            ]);
        }

        return $request;
    }

    private function hintedKind(): ApprovalKind
    {
        try {
            return $this->container->make(ApprovalKindHint::class)->pull() ?? ApprovalKind::Action;
        } catch (Throwable) {
            return ApprovalKind::Action;
        }
    }

    public function status(string $requestId): ?ActionApprovalStatus
    {
        return $this->inner->status($requestId);
    }

    public function consume(string $requestId, string $actionDigest): bool
    {
        return $this->inner->consume($requestId, $actionDigest);
    }
}
