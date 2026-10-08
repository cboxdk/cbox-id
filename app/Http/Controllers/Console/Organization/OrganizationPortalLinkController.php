<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console\Organization;

use App\Actions\PortalLinks\CreatePortalLink;
use App\Actions\PortalLinks\RevokePortalLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * THE HUB HEADER'S "Admin Portal link" — a one-time link that lets the customer's own IT
 * administrator set up what the administrator here ticked (single sign-on, directory sync,
 * domain verification, log streams, SAML certificate renewal) without an account here,
 * waiting as long as they chose, and mailed to the customer's IT contact if they gave one.
 *
 * The action a management key mints the same link with (`organizations.portal_links.create`),
 * which refuses a link covering anything the organization's plan does not include, sends the
 * mail, and records who minted it. The form's field names are the action's.
 */
final readonly class OrganizationPortalLinkController extends OrganizationTabController
{
    public function store(Request $request): RedirectResponse
    {
        $organization = $this->organization();

        $minutes = $request->input('expires_in_minutes');
        $email = trim((string) $request->string('email'));

        $result = $this->act(CreatePortalLink::class, [
            'organization_id' => $organization->id,
            'intents' => $request->input('intents', []),
            // The select posts a string; the action takes the number it names.
            'expires_in_minutes' => is_numeric($minutes) ? (int) $minutes : null,
            'email' => $email === '' ? null : $email,
            'locale' => $email === '' ? null : $request->string('locale')->toString(),
        ], [
            'intents' => 'intents',
            'expires_in_minutes' => 'expires_in_minutes',
            'email' => 'email',
            'locale' => 'locale',
        ], 'intents');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /*
         * ON THE FLASH CHANNEL. The link admits its holder to this organization's setup with
         * no account at all — as a page prop it would be written into the browser's history
         * entry and readable by pressing Back.
         */
        $this->inertia->flash('portalUrl', $result->value);

        $sentTo = $result->payload['emailed_to'] ?? null;

        return match (true) {
            is_string($sentTo) => back()->with('status', "Setup link sent to {$sentTo}."),
            ($result->payload['email_suppressed'] ?? false) === true => back()->with('status', 'Sandbox environments send no email — copy the setup link below and share it yourself.'),
            default => back(),
        };
    }

    /**
     * THE OVERVIEW'S "Revoke" — withdraw a link that is still outstanding, through the action
     * a management key withdraws it with (`organizations.portal_links.revoke`): the link is
     * looked up inside this organization, and a setup session it opened ends on its next
     * request.
     */
    public function destroy(string $link): RedirectResponse
    {
        $organization = $this->organization();

        $result = $this->act(RevokePortalLink::class, [
            'organization_id' => $organization->id,
            'id' => $link,
        ]);

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', 'Admin Portal link revoked.');
    }
}
