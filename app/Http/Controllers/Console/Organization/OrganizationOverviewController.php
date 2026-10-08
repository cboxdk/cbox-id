<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console\Organization;

use App\Http\Props\Shared\HelpProps;
use App\Platform\AuditNames;
use App\Platform\Entitlements;
use App\Platform\Help\HelpTopic;
use App\Platform\Invitations\Contracts\OrganizationInvitations;
use App\Platform\Sso\CertificateExpiryAlerts;
use Cbox\Id\Directory\Enums\DirectoryStatus;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Enums\ConnectionStatus;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Organization\Contracts\Memberships;
use Inertia\Response;

/**
 * AN ORGANIZATION › OVERVIEW — is it set up, and what happened to it lately.
 *
 * THE THREE QUESTIONS SOMEBODY ONBOARDING A CUSTOMER ASKS, each answered from the state of
 * the system rather than from a checklist anybody ticked: is single sign-on connected, is a
 * domain proved, is a directory syncing. Each one links to the tab where it is done — and
 * says when the organization's plan does not include it, rather than offering a step that
 * would be refused.
 *
 * Then the organization's own trail, newest first: the same rows its Audit log tab pages
 * through, so "what changed" is answered without leaving the page.
 */
final readonly class OrganizationOverviewController extends OrganizationTabController
{
    /** How many trail entries the overview shows before handing over to the Audit log tab. */
    private const RECENT = 8;

    public function show(DomainVerification $domains, Memberships $memberships, OrganizationInvitations $invitations, Entitlements $entitlements, AuditNames $names, CertificateExpiryAlerts $certificates): Response
    {
        $organization = $this->organization();
        $ids = ['organization' => $organization->id];

        $connections = Connection::query()
            ->where('organization_id', $organization->id)
            // Hand-configured enterprise connections; a social provider offered on the
            // organization's sign-in page is not single sign-on for its people.
            ->whereNull('provider')
            ->get(['id', 'status']);

        $verified = array_filter(
            $domains->forOrganization($organization->id),
            static fn (VerifiedDomain $domain): bool => $domain->isVerified(),
        );

        $directories = Directory::query()->where('organization_id', $organization->id)->get(['id', 'status']);

        $recent = AuditEntry::query()
            ->where('organization_id', $organization->id)
            ->orderByDesc('sequence')
            ->limit(self::RECENT)
            ->get();

        $resolved = $names->for($recent);

        return $this->page('environment/organizations/tabs/overview', $organization->name, [
            'help' => HelpProps::for(HelpTopic::Organizations),
            'setup' => [
                [
                    'key' => 'sso',
                    'label' => 'Single sign-on connected',
                    'done' => $connections->contains(static fn (Connection $connection): bool => $connection->status === ConnectionStatus::Active),
                    'available' => $entitlements->entitled($organization->id, 'sso'),
                    'detail' => $connections->isEmpty()
                        ? 'No connection yet.'
                        : $connections->count().' '.($connections->count() === 1 ? 'connection' : 'connections').', '
                            .$connections->filter(static fn (Connection $connection): bool => $connection->status === ConnectionStatus::Active)->count().' active.',
                    'href' => route('environment.organizations.sso', $ids),
                ],
                [
                    'key' => 'domain',
                    'label' => 'Domain verified',
                    'done' => $verified !== [],
                    'available' => true,
                    'detail' => $verified === []
                        ? 'No domain proved yet.'
                        : implode(', ', array_map(static fn (VerifiedDomain $domain): string => $domain->domain, array_values($verified))),
                    'href' => route('environment.organizations.domains', $ids),
                ],
                [
                    'key' => 'directory',
                    'label' => 'Directory syncing',
                    'done' => $directories->contains(static fn (Directory $directory): bool => $directory->status === DirectoryStatus::Active),
                    'available' => $entitlements->entitled($organization->id, 'scim'),
                    'detail' => $directories->isEmpty()
                        ? 'No directory connected yet.'
                        : $directories->count().' '.($directories->count() === 1 ? 'directory' : 'directories').', '
                            .$directories->filter(static fn (Directory $directory): bool => $directory->status === DirectoryStatus::Active)->count().' syncing.',
                    'href' => route('environment.organizations.directory-sync', $ids),
                ],
            ],
            'counts' => [
                // The roster's own count, so it agrees with the Members tab about who is a member.
                'members' => $memberships->paginateForOrganization($organization->id, 1)->total(),
                'invitations' => count($invitations->pending($organization->id)),
            ],
            'recent' => $recent->map(static fn (AuditEntry $entry): array => [
                'id' => $entry->id,
                'action' => $entry->action,
                'phrase' => str_replace(['.', '_'], [' · ', ' '], $entry->action),
                'actorName' => $entry->actor_id === null ? null : ($resolved[$entry->actor_id] ?? $entry->actor_id),
                'recordedAt' => $entry->recorded_at?->toIso8601String(),
            ])->values()->all(),
            // The daily certificate scan's warnings, read live: a SAML connection that stops
            // working within 30 days is the one thing on this page that will break by itself.
            'certificateWarnings' => array_map(static fn (array $warning): array => [
                ...$warning,
                'href' => route('environment.connections.show', $warning['connection_id']),
            ], $certificates->warningsFor($organization->id)),
            'hrefs' => [
                'members' => route('environment.organizations.members', $ids),
                'invitations' => route('environment.organizations.invitations', $ids),
                'audit' => route('environment.organizations.audit', $ids),
            ],
        ]);
    }
}
