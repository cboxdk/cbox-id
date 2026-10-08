<?php

declare(strict_types=1);

namespace App\Platform\Portal;

use App\Platform\Enums\PortalIntent;
use App\Platform\Sso\CertificateExpiryAlerts;
use App\Platform\Sso\ConnectionCertificates;
use Cbox\Id\AuditStreaming\Models\AuditStream;
use Cbox\Id\Directory\Enums\DirectoryStatus;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Illuminate\Database\Eloquent\Builder;

/**
 * HOW FAR ALONG EACH INTENT IS, read from the state of the system rather than from a box
 * anybody ticked — the Admin Portal home's checklist.
 *
 * Each intent is a short list of steps, each `done` or not, so the page can say "2 of 3"
 * and the step it is waiting on. An intent is DONE when its last step is: single sign-on
 * when a connection is live and a domain routes to it, directory sync when a directory has
 * been registered and is active, and so on. Nothing here writes; nothing here is asked of a
 * request — the organization is the caller's, read from the portal session.
 */
final readonly class PortalProgress
{
    /** The portal session's note that its holder has opened the audit logs. */
    public const string AUDIT_LOGS_VIEWED = 'cbox.portal.audit_logs_viewed';

    public function __construct(
        private DomainVerification $domains,
        private ConnectionCertificates $certificates,
    ) {}

    /**
     * @return array{steps: list<array{key: string, done: bool}>, done: bool, started: bool}
     */
    public function for(PortalIntent $intent, string $organizationId): array
    {
        $steps = match ($intent) {
            PortalIntent::Sso => $this->sso($organizationId),
            PortalIntent::Dsync => $this->directorySync($organizationId),
            PortalIntent::DomainVerification => $this->domainVerification($organizationId),
            PortalIntent::LogStreams => $this->logStreams($organizationId),
            PortalIntent::CertificateRenewal => $this->certificateRenewal($organizationId),
            // Nothing to configure: done once the session has opened them.
            PortalIntent::AuditLogs => [['key' => 'audit_logs_viewed', 'done' => session()->get(self::AUDIT_LOGS_VIEWED) === true]],
        };

        $done = array_filter($steps, static fn (array $step): bool => $step['done']);

        return [
            'steps' => $steps,
            'done' => count($done) === count($steps),
            'started' => $done !== [],
        ];
    }

    /**
     * @return list<array{key: string, done: bool}>
     */
    private function sso(string $organizationId): array
    {
        $connections = $this->enterpriseConnections($organizationId)->get();

        return [
            ['key' => 'connection_created', 'done' => $connections->isNotEmpty()],
            ['key' => 'domain_verified', 'done' => $this->verifiedDomains($organizationId) !== []],
            ['key' => 'connection_active', 'done' => $connections->contains(static fn (Connection $connection): bool => $connection->isActive())],
        ];
    }

    /**
     * @return list<array{key: string, done: bool}>
     */
    private function directorySync(string $organizationId): array
    {
        $directories = Directory::query()->where('organization_id', $organizationId)->get(['id', 'status', 'last_synced_at']);

        return [
            ['key' => 'directory_created', 'done' => $directories->isNotEmpty()],
            ['key' => 'directory_synced', 'done' => $directories->contains(
                static fn (Directory $directory): bool => $directory->status === DirectoryStatus::Active && $directory->last_synced_at !== null,
            )],
        ];
    }

    /**
     * @return list<array{key: string, done: bool}>
     */
    private function domainVerification(string $organizationId): array
    {
        return [
            ['key' => 'domain_added', 'done' => $this->domains->forOrganization($organizationId) !== []],
            ['key' => 'domain_verified', 'done' => $this->verifiedDomains($organizationId) !== []],
        ];
    }

    /**
     * @return list<array{key: string, done: bool}>
     */
    private function logStreams(string $organizationId): array
    {
        $streams = AuditStream::query()->ownedByOrganization($organizationId)->get(['id', 'enabled', 'last_success_at']);

        return [
            ['key' => 'stream_created', 'done' => $streams->isNotEmpty()],
            ['key' => 'stream_delivering', 'done' => $streams->contains(static fn (AuditStream $stream): bool => $stream->enabled && $stream->last_success_at !== null)],
        ];
    }

    /**
     * Done when no SAML connection of the organization stops working within the widest
     * alert threshold — which is also true of an organization with nothing to renew.
     *
     * @return list<array{key: string, done: bool}>
     */
    private function certificateRenewal(string $organizationId): array
    {
        $horizon = max(CertificateExpiryAlerts::thresholds());
        $staged = false;
        $healthy = true;

        foreach ($this->enterpriseConnections($organizationId)->where('type', ConnectionType::Saml)->get() as $connection) {
            foreach ($this->certificates->all($connection) as $entry) {
                $staged = $staged || $entry['role'] === 'staged';
            }

            $expiry = $this->certificates->effectiveExpiry($connection);

            if ($expiry !== null && $expiry->daysRemaining() <= $horizon) {
                $healthy = false;
            }
        }

        return [
            ['key' => 'certificate_staged', 'done' => $staged || $healthy],
            ['key' => 'certificate_current', 'done' => $healthy && ! $staged],
        ];
    }

    /**
     * The organization's own identity-provider connections — not a social sign-in offered on
     * its sign-in page.
     *
     * @return Builder<Connection>
     */
    private function enterpriseConnections(string $organizationId): Builder
    {
        return Connection::query()->where('organization_id', $organizationId)->whereNull('provider');
    }

    /**
     * @return list<VerifiedDomain>
     */
    private function verifiedDomains(string $organizationId): array
    {
        return array_values(array_filter(
            $this->domains->forOrganization($organizationId),
            static fn (VerifiedDomain $domain): bool => $domain->isVerified(),
        ));
    }
}
