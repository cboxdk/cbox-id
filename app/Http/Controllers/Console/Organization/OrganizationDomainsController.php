<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console\Organization;

use Inertia\Response;

/**
 * AN ORGANIZATION › DOMAINS — the email domains it claims, proved by a DNS TXT record, and
 * whether everybody on one is routed to the organization's own sign-in.
 *
 * CAPTURE IS THE CONSEQUENTIAL SWITCH, so it stays off until the domain is proven —
 * otherwise an organization could claim addresses it does not own. Adding, verifying,
 * capturing and removing are the `organizations.domains.*` actions.
 */
final readonly class OrganizationDomainsController extends OrganizationTabController
{
    public function index(): Response
    {
        $organization = $this->organization();

        return $this->page('environment/organizations/tabs/domains', $organization->name.' · Domains', [
            'domains' => $this->domainProps($organization->id),
            'addDomainHref' => route('environment.organizations.domains.store', ['organization' => $organization->id]),
        ]);
    }
}
