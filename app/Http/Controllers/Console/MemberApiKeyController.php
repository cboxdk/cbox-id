<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Props\Shared\AppApiKeyRows;
use App\Http\Props\Shared\HelpProps;
use App\Platform\ApiKeys\MemberApiKeys;
use App\Platform\Help\HelpTopic;
use Cbox\Id\Organization\Models\CustomerApiKey;
use Cbox\Id\Organization\ValueObjects\ApiKeyActor;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * PEOPLE › MEMBER API KEYS — every API key this organization's people hold for its apps.
 *
 * An administrator answers for what can act in their organization, and a key acts with its
 * holder's access for as long as nobody stops it. So they see all of them — whose, for
 * which app, what it may do, when it was last used, when it expires — and can revoke any.
 *
 * THEY CANNOT CREATE ONE FOR SOMEBODY ELSE. A key carries its holder's access; a key minted
 * in a colleague's name would be a credential acting as a person who never asked for it.
 * Each person creates their own under My account › API keys.
 *
 * Organization console only. The environment console shows the same list on each
 * organization's own page, where its roster already is.
 */
final readonly class MemberApiKeyController extends ConsoleController
{
    public function index(MemberApiKeys $keys, AppApiKeyRows $rows): Response
    {
        $this->scope->assertMayAdminister();

        $organizationId = $this->scope->requireOrganizationId();

        return $this->page('console/member-api-keys', 'Member API keys', [
            'help' => HelpProps::for(HelpTopic::MemberApiKeys),
            'keys' => $rows->for(
                $keys->inOrganization($organizationId),
                withHolder: true,
                revokeHref: static fn (CustomerApiKey $key): string => route('directory.api-keys.revoke', $key->id),
            ),
        ]);
    }

    /**
     * Revoke a key in THIS organization. The organization is the signed-in person's, never
     * the URL's, and it is part of the lookup — an id from another organization's list
     * revokes nothing.
     */
    public function destroy(string $key, MemberApiKeys $keys): RedirectResponse
    {
        $this->scope->assertMayAdminister();

        $actor = $this->scope->auditActor();

        if (! $keys->revoke($this->scope->requireOrganizationId(), $key, new ApiKeyActor($actor->type, $actor->id))) {
            return back();
        }

        return back()->with('status', 'API key revoked. Whatever was using it stops working now.');
    }
}
