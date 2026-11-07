<?php

declare(strict_types=1);

namespace Cbox\Signals\Managed;

use Cbox\Signals\Contracts\ManagedCredential;
use Cbox\Signals\Contracts\ManagedIntegration;
use Cbox\Signals\Data\ManagedEnrollment;
use Cbox\Signals\Data\ManagedEnvironment;
use Cbox\Signals\Exceptions\SignalsFailure;
use Cbox\Signals\Support\JsonTransport;
use Cbox\Signals\Support\Wire;

/**
 * The Signals enrolment API (`/v1/managed-integrations/{provider}/enrollments/{owner}`).
 * Provider and owner ids travel in the path and are therefore restricted to a
 * conservative character set before any request is made.
 */
final readonly class HttpManagedIntegration implements ManagedIntegration
{
    public function __construct(private JsonTransport $transport, private ManagedCredential $credential) {}

    public function enroll(string $provider, ManagedEnvironment $environment, string $secret): ManagedEnrollment
    {
        if ($secret === '') {
            throw new SignalsFailure('secret_required', 422);
        }
        $data = $this->transport->send('PUT', $this->path($provider, $environment->ownerId), $this->credential->token(), [...$environment->toArray(), 'secret' => $secret]);

        return ManagedEnrollment::fromArray($data);
    }

    public function enrollment(string $provider, string $ownerId): ?ManagedEnrollment
    {
        $data = $this->transport->sendOrAbsent('GET', $this->path($provider, $ownerId), $this->credential->token(), ['not_enrolled', 'not_found']);

        return $data === null ? null : ManagedEnrollment::fromArray($data);
    }

    public function revoke(string $provider, string $ownerId): void
    {
        try {
            $this->transport->send('DELETE', $this->path($provider, $ownerId), $this->credential->token());
        } catch (SignalsFailure $failure) {
            if ($failure->status !== 404) {
                throw $failure;
            }
        }
    }

    private function path(string $provider, string $ownerId): string
    {
        try {
            return '/v1/managed-integrations/'.Wire::identifier($provider).'/enrollments/'.Wire::identifier($ownerId);
        } catch (SignalsFailure) {
            throw new SignalsFailure('invalid_identifier', 422);
        }
    }
}
