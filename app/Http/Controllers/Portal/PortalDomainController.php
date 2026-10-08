<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\Organizations\AddOrganizationDomain;
use App\Actions\Organizations\RemoveOrganizationDomain;
use App\Actions\Organizations\VerifyOrganizationDomain;
use App\Http\Requests\Portal\AddPortalDomainRequest;
use App\Platform\Enums\PortalIntent;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * DOMAIN VERIFICATION IN THE ADMIN PORTAL — add a domain, publish the TXT record it answers
 * with, check it, see it verified.
 *
 * Reached by two intents: domain verification on its own, and single sign-on, whose
 * connection routes nobody until a domain does. The writes are the console's own domain
 * actions (`organizations.domains.*`), run as the portal session.
 *
 * The TXT record is shown for every pending domain, not only on the answer that created it:
 * it is published in public DNS, so it is not a secret, and somebody who closed the tab
 * before their DNS change went live must not have to start again.
 */
final readonly class PortalDomainController extends PortalController
{
    public function show(DomainVerification $domains): Response
    {
        $this->requireIntent(PortalIntent::DomainVerification, PortalIntent::Sso);

        return $this->portalPage('portal/domains', __('portal.domains.title'), [
            'domains' => self::rows($domains, $this->organizationId()),
            'addHref' => route('portal.domains.store'),
        ]);
    }

    public function store(AddPortalDomainRequest $request): RedirectResponse
    {
        $this->requireIntent(PortalIntent::DomainVerification, PortalIntent::Sso);

        $result = $this->act(AddOrganizationDomain::class, [
            'organization_id' => $this->organizationId(),
            'domain' => $request->domain(),
        ], ['domain' => 'domain'], 'domain');

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', __('portal.domains.added', ['domain' => $request->domain()]));
    }

    public function verify(string $domain): RedirectResponse
    {
        $this->requireIntent(PortalIntent::DomainVerification, PortalIntent::Sso);

        $result = $this->act(VerifyOrganizationDomain::class, [
            'organization_id' => $this->organizationId(),
            'domain_id' => $domain,
        ], [], 'domain');

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', __('portal.domains.verified_status'));
    }

    public function destroy(string $domain): RedirectResponse
    {
        $this->requireIntent(PortalIntent::DomainVerification, PortalIntent::Sso);

        $result = $this->act(RemoveOrganizationDomain::class, [
            'organization_id' => $this->organizationId(),
            'domain_id' => $domain,
        ], [], 'domain');

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', __('portal.domains.removed'));
    }

    /**
     * The organization's domains as the page draws them — with the record to publish.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(DomainVerification $domains, string $organizationId): array
    {
        return array_map(static fn (VerifiedDomain $domain): array => [
            'id' => $domain->id,
            'domain' => $domain->domain,
            'verified' => $domain->isVerified(),
            'verifiedAt' => $domain->verified_at?->toIso8601String(),
            'recordHost' => $domains->challengeHost($domain->domain),
            'recordValue' => $domain->verification_token,
            'verifyHref' => route('portal.domains.verify', $domain->id),
            'removeHref' => route('portal.domains.destroy', $domain->id),
        ], $domains->forOrganization($organizationId));
    }
}
