<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Http\Middleware\BindConsoleOrganization;
use App\Http\Props\Console\OptionProps;
use App\Http\Props\Console\OrganizationHeaderProps;
use App\Platform\Entitlements;
use App\Platform\Enums\PortalFeature;
use App\Platform\Enums\PortalScope;
use Cbox\Id\Organization\Models\Organization;

/**
 * The header every tab of an organization's page shares — built in one place, so thirteen
 * tabs cannot disagree about its name, its tabs, or whether a portal link can be made.
 *
 * Shared with every page under `/admin/organizations/{organization}/…` by
 * {@see BindConsoleOrganization}, which is what lets a page written for
 * the environment-wide console (Enterprise SSO, Roles, the audit log) be drawn as a tab of
 * one organization without knowing it is one.
 */
final readonly class OrganizationHeader
{
    public function __construct(
        private OrganizationTabs $tabs,
        private Entitlements $entitlements,
    ) {}

    public function for(string $organizationId, string $tab): ?OrganizationHeaderProps
    {
        $organization = Organization::query()->whereKey($organizationId)->first(['id', 'name', 'slug', 'status']);

        if ($organization === null) {
            return null;
        }

        $covers = $this->portalCovers($organization->id);

        return new OrganizationHeaderProps(
            id: $organization->id,
            name: $organization->name,
            slug: (string) $organization->slug,
            status: $organization->status->value,
            tabs: $this->tabs->for($organization->id, $tab),
            indexHref: route('environment.organizations'),
            portalLinkHref: $covers === [] ? null : route('environment.organizations.portal-links.store', ['organization' => $organization->id]),
            portalCovers: $covers,
        );
    }

    /**
     * What a portal link for this organization may cover: only what it is entitled to, and
     * "both" only when it is entitled to both — the action refuses anything else, and a
     * choice offered here only to be refused there is the console lying about what it can do.
     *
     * @return list<OptionProps>
     */
    private function portalCovers(string $organizationId): array
    {
        $sso = $this->entitlements->entitled($organizationId, PortalFeature::Sso->entitlement());
        $scim = $this->entitlements->entitled($organizationId, PortalFeature::Scim->entitlement());

        $covers = [];

        if ($sso) {
            $covers[] = new OptionProps(PortalScope::Sso->value, 'Single sign-on and domains');
        }

        if ($scim) {
            $covers[] = new OptionProps(PortalScope::Scim->value, 'Directory sync');
        }

        if ($sso && $scim) {
            $covers[] = new OptionProps(PortalScope::Both->value, 'Both');
        }

        // Read-only: the organization's own audit events, for the admin who has to answer
        // "who did that?" inside their company without an account here.
        if ($this->entitlements->entitled($organizationId, PortalFeature::AuditLogs->entitlement())) {
            $covers[] = new OptionProps(PortalScope::AuditLogs->value, 'Audit logs (read-only)');
        }

        return $covers;
    }
}
