<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console\Organization;

use App\Http\Controllers\Console\ConsoleController;
use App\Http\Middleware\BindConsoleOrganization;
use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Support\Collection;

/**
 * WHAT EVERY TAB OF AN ORGANIZATION'S PAGE STANDS ON — the organization the URL names.
 *
 * The id was checked and bound by `console.org` before any tab runs
 * ({@see BindConsoleOrganization}): another environment's id and a
 * made-up one are already a 404 by now. So a tab reads it from the SCOPE, never from a route
 * parameter of its own, and every write a tab draws a button for names the organization in
 * its own URL — re-resolved inside the action that runs it, never trusted from this page.
 *
 * The header, the tabs and the Admin Portal link are not here either: they are shared with
 * every page under the hub by the same middleware, so the tabs that are environment-wide
 * pages narrowed to one organization (Enterprise SSO, Roles, the audit log) draw the same
 * header without knowing they are tabs.
 */
abstract readonly class OrganizationTabController extends ConsoleController
{
    /** A page of a roster or a list: the widest end-user surface in this console. */
    protected const PER_PAGE = 25;

    /**
     * The organization this tab is about.
     *
     * The environment console's own gate first — a membership administering THIS environment
     * — because the hub is that console's and no other.
     */
    protected function organization(): Organization
    {
        abort_if(app(EnvironmentAdminAuth::class)->membership() === null, 403);

        $id = $this->routeOrganizationId();

        abort_if($id === null, 404);

        return Organization::query()->whereKey($id)->firstOrFail();
    }

    /**
     * The domains this organization claims.
     *
     * The TXT record is `verification_token`, published at `recordName` — the two values
     * somebody has to copy into a DNS panel, so each is handed over as its own field rather
     * than left in prose. The name comes from the verifier that will look it up
     * ({@see DomainVerification::challengeHost()}): composed in the browser, it said "at
     * acme.com" while the check read `_cbox-id-challenge.acme.com`, and a record published
     * where the page said was never found.
     *
     * @return list<array{id: string, domain: string, verified: bool, capture: bool, recordName: string, token: string, urls: array{verify: string, capture: string, remove: string}}>
     */
    protected function domainProps(string $organizationId): array
    {
        $rows = [];
        $verifier = app(DomainVerification::class);

        foreach ($verifier->forOrganization($organizationId) as $domain) {
            $rows[] = [
                'id' => $domain->id,
                'domain' => $domain->domain,
                'verified' => $domain->isVerified(),
                'capture' => $domain->capture,
                'recordName' => $verifier->challengeHost($domain->domain),
                'token' => $domain->verification_token,
                'urls' => [
                    'verify' => route('environment.organizations.domains.verify', ['organization' => $organizationId, 'domain' => $domain->id]),
                    'capture' => route('environment.organizations.domains.capture', ['organization' => $organizationId, 'domain' => $domain->id]),
                    'remove' => route('environment.organizations.domains.remove', ['organization' => $organizationId, 'domain' => $domain->id]),
                ],
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @param  array<string, string>  $appNames
     * @return list<array{id: string, name: string, app: string|null, staffOnly: bool}>
     */
    protected function accessRoleProps($roles, array $appNames): array
    {
        $rows = [];

        foreach ($roles as $role) {
            $rows[] = [
                'id' => $role->id,
                'name' => $role->name,
                // Grouped org-wide vs per-app, because "what a person can do" reads
                // differently depending on which apps it reaches.
                'app' => $role->client_id === null ? null : ($appNames[$role->client_id] ?? $role->client_id),
                // A staff role the environment may grant inside one organization; tagged so
                // it is not mistaken for one of the organization's own. Tenant surfaces
                // never list it (OrgAccessRoles::tenantAssignable()).
                'staffOnly' => $role->tenant_assignable === false,
            ];
        }

        return $rows;
    }
}
