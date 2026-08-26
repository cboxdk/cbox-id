<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Portal\AddPortalDomainRequest;
use App\Http\Requests\Portal\CreatePortalConnectionRequest;
use App\Platform\AdminPortal;
use App\Platform\Enums\PortalFeature;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Directory\Enums\DirectoryStatus;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Contracts\DomainVerification;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Exceptions\DomainAlreadyClaimed;
use Cbox\Id\Federation\Exceptions\OidcDiscoveryFailed;
use Cbox\Id\Federation\Exceptions\UnsafeFederationUrl;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Federation\OidcDiscovery;
use Cbox\Id\Organization\Contracts\Organizations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * THE ADMIN PORTAL — an external IT admin, with no account on this platform at all,
 * configuring single sign-on and directory sync for exactly one organization.
 *
 * EVERY READ AND EVERY WRITE TAKES THE ORGANIZATION FROM THE PORTAL SESSION, never from
 * request input. A redeemer can only ever configure the one organization their link was
 * minted for, and the id they would need to reach another is not something they hold —
 * which is a stronger statement than any check on a submitted id could be.
 *
 * THE FEATURE IS ASKED PER ACTION, not once at the door. A link is scoped — SSO only, SCIM
 * only, or both — and a scope that gated the render alone would leave the writes open to
 * anybody who could form the request.
 */
final readonly class PortalSetupController extends PageController
{
    public function show(AdminPortal $portal): Response
    {
        $organizationId = $portal->boundOrgId();

        return $this->page('portal/setup', 'Set up SSO & SCIM', [
            'organizationName' => $organizationId === null
                ? null
                : app(Organizations::class)->find($organizationId)?->name,
            'showSso' => $portal->canConfigure(PortalFeature::Sso),
            'showScim' => $portal->canConfigure(PortalFeature::Scim),
            'domains' => $organizationId === null ? [] : array_map(
                fn (VerifiedDomain $domain): array => [
                    'id' => $domain->id,
                    'domain' => $domain->domain,
                    'verified' => $domain->isVerified(),
                    'verifyHref' => route('portal.domains.verify', $domain->id),
                    'removeHref' => route('portal.domains.destroy', $domain->id),
                ],
                app(DomainVerification::class)->forOrganization($organizationId),
            ),
            'connections' => $organizationId === null ? [] : Connection::query()
                ->where('organization_id', $organizationId)
                ->orderByDesc('created_at')
                ->get()
                ->map(static fn (Connection $connection): array => [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'protocol' => mb_strtoupper($connection->type->value),
                    'status' => $connection->status->value,
                    'active' => $connection->isActive(),
                    'activateHref' => route('portal.connections.activate', $connection->id),
                ])->values()->all(),
            'directories' => $organizationId === null ? [] : Directory::query()
                ->where('organization_id', $organizationId)
                ->orderByDesc('created_at')
                ->get()
                ->map(static fn (Directory $directory): array => [
                    'id' => $directory->id,
                    'name' => $directory->name,
                    'active' => $directory->status === DirectoryStatus::Active,
                ])->values()->all(),
            'scimBaseUrl' => url('/scim/v2'),
            'urls' => [
                'addDomain' => route('portal.domains.store'),
                'createConnection' => route('portal.connections.store'),
                'registerDirectory' => route('portal.directories.store'),
                'finish' => route('portal.finish'),
            ],
        ]);
    }

    public function addDomain(AddPortalDomainRequest $request, DomainVerification $domains): RedirectResponse
    {
        $organizationId = $this->boundTo(PortalFeature::Sso);

        try {
            $record = $domains->add($organizationId, $request->domain());
        } catch (DomainAlreadyClaimed) {
            return back()->withInput()->withErrors([
                'domain' => 'That domain is already claimed by another organization.',
            ]);
        }

        /*
         * THE DNS CHALLENGE, ON THE FLASH CHANNEL. It is what the admin must publish to
         * prove control, shown on the render that answered — a prop would put it in the
         * browser's history entry, and it is re-issuable from the list if lost.
         */
        $this->inertia->flash('dns', [
            'host' => $domains->challengeHost($record->domain),
            'token' => $record->verification_token,
            'domain' => $record->domain,
        ]);

        return back();
    }

    public function verifyDomain(string $domain, DomainVerification $domains): RedirectResponse
    {
        $this->ownedDomain($domain, $domains);

        /*
         * TWO OUTCOMES, SAID DIFFERENTLY. A single message with the severity applied to the
         * whole expression announced a SUCCESSFUL verification in red, assertively.
         */
        return $domains->verify($domain)
            ? back()->with('status', 'Domain verified — users on this domain can now sign in with SSO.')
            : back()->withErrors([
                'domain' => "We couldn't find the TXT record yet — DNS can take a few minutes to propagate.",
            ]);
    }

    public function removeDomain(string $domain, DomainVerification $domains): RedirectResponse
    {
        $this->ownedDomain($domain, $domains);

        $domains->remove($domain);

        return back()->with('status', 'Domain removed.');
    }

    public function createConnection(CreatePortalConnectionRequest $request, Connections $connections): RedirectResponse
    {
        $organizationId = $this->boundTo(PortalFeature::Sso);

        $type = $request->type();
        $config = $request->config();

        if ($type !== ConnectionType::Saml) {
            /*
             * DISCOVERED, not typed. An issuer's endpoints come from its own `.well-known`
             * document; asking an IT admin to copy four URLs by hand is asking for a
             * mistake that surfaces days later as a sign-in that fails for everybody.
             */
            try {
                $config = array_merge($config, app(OidcDiscovery::class)->fromIssuer($request->issuer())->toConfig());
            } catch (OidcDiscoveryFailed|UnsafeFederationUrl $e) {
                return back()->withInput()->withErrors([
                    'issuer' => "Couldn't read the provider's OpenID configuration — check the issuer URL. ({$e->getMessage()})",
                ]);
            }
        }

        $connections->create($organizationId, $type, $request->name(), $config);

        // A DRAFT, said out loud: nothing routes to it until somebody activates it, which
        // is what stops a half-typed connection taking sign-in down while it is being set up.
        return back()->with('status', 'Connection created as a draft.');
    }

    public function activateConnection(string $connection, Connections $connections): RedirectResponse
    {
        $organizationId = $this->boundTo(PortalFeature::Sso);

        // The organization is a parameter of the call, so a connection belonging to another
        // one is not found rather than activated.
        $connections->activate($organizationId, $connection);

        return back()->with('status', 'Connection activated.');
    }

    public function registerDirectory(Request $request, Directories $directories): RedirectResponse
    {
        $organizationId = $this->boundTo(PortalFeature::Scim);

        $request->validate(['dirName' => ['required', 'string', 'max:120']]);

        $registered = $directories->register($organizationId, (string) $request->string('dirName'));

        /*
         * THE BEARER TOKEN, ON THE FLASH CHANNEL. It authenticates every inbound
         * provisioning call for this organization, which makes it a credential — and this
         * page is handed to a THIRD PARTY, so it must not be written into their browser's
         * history entry any more than into anybody else's.
         */
        $this->inertia->flash([
            'newToken' => $registered->token,
            'newTokenName' => $registered->directory->name,
        ]);

        return back();
    }

    /**
     * Close the link.
     *
     * The session is cleared by `complete()`, so this cannot re-render the setup screen —
     * the next request would bounce to the expired page, which is the wrong sentence for
     * somebody who just finished. It hands over to a page of its own instead, carrying the
     * organization's name because the session that knew it is gone.
     */
    public function finish(AdminPortal $portal): RedirectResponse
    {
        // Belt-and-braces with the middleware: only a live session may finish.
        abort_unless($portal->sessionValid(), 403);

        $organizationId = $portal->boundOrgId();
        $name = $organizationId === null ? null : app(Organizations::class)->find($organizationId)?->name;

        $portal->complete();

        $this->inertia->flash('portalOrganization', $name);

        return redirect()->route('portal.done');
    }

    /** "All set" — outside the portal session, because finishing ends it. */
    public function done(): Response
    {
        return $this->page('portal/done', 'All set');
    }

    /** The friendly refusal for a link that has expired or was already used. */
    public function expired(): Response
    {
        return $this->page('portal/expired', 'Link unavailable');
    }

    /**
     * The domain named by an action, refused unless the bound organization owns it.
     *
     * Deny-by-default rather than "look it up and act on it": the id comes from the page,
     * which is to say from a third party's browser.
     */
    private function ownedDomain(string $id, DomainVerification $domains): VerifiedDomain
    {
        $organizationId = $this->boundTo(PortalFeature::Sso);

        foreach ($domains->forOrganization($organizationId) as $domain) {
            if ($domain->id === $id) {
                return $domain;
            }
        }

        abort(403);
    }

    /**
     * The organization this portal session is bound to, if it may configure this feature.
     *
     * Asked per action, not once at the door — a link scoped to SCIM must not be able to
     * add a domain by forming the request, and the scope is re-read from the session every
     * time rather than remembered from the render.
     */
    private function boundTo(PortalFeature $feature): string
    {
        $portal = app(AdminPortal::class);

        abort_unless($portal->sessionValid() && $portal->canConfigure($feature), 403);

        $organizationId = $portal->boundOrgId();

        abort_if($organizationId === null, 403);

        return $organizationId;
    }
}
