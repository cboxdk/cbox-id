<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console\Organization;

use App\Actions\PortalLinks\CreatePortalLink;
use App\Platform\Enums\PortalScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * THE HUB HEADER'S "Admin Portal link" — a one-time link that lets the customer's own IT
 * administrator set up their single sign-on and domains, or their directory sync, without an
 * account here.
 *
 * The action a management key mints the same link with (`organizations.portal_links.create`),
 * which refuses a link covering anything the organization's plan does not include and records
 * who minted it.
 */
final readonly class OrganizationPortalLinkController extends OrganizationTabController
{
    public function store(Request $request): RedirectResponse
    {
        $organization = $this->organization();

        $request->validate([
            'covers' => ['required', 'string', Rule::in(array_map(static fn (PortalScope $scope): string => $scope->value, PortalScope::cases()))],
        ]);

        $result = $this->act(CreatePortalLink::class, [
            'organization_id' => $organization->id,
            'covers' => $request->string('covers')->toString(),
        ], ['covers' => 'covers'], 'covers');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        /*
         * ON THE FLASH CHANNEL. The link admits its holder to this organization's setup with
         * no account at all — as a page prop it would be written into the browser's history
         * entry and readable by pressing Back.
         */
        $this->inertia->flash('portalUrl', $result->value);

        return back();
    }
}
