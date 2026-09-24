<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Props\Shared\AppApiKeyRows;
use App\Http\Props\Shared\HelpProps;
use App\Http\Requests\Account\IssueAppApiKeyRequest;
use App\Platform\ApiKeys\HolderApiKeys;
use App\Platform\ApiKeys\KeyApps;
use App\Platform\ApiKeys\ValueObjects\KeyApp;
use App\Platform\ApiKeys\ValueObjects\KeyOrganization;
use App\Platform\ApiKeys\ValueObjects\KeyPermission;
use App\Platform\ApiKeys\ValueObjects\RefusalExplanation;
use App\Platform\CurrentUser;
use App\Platform\Enums\KeyLifetime;
use App\Platform\Help\HelpTopic;
use Cbox\Id\Organization\Exceptions\CustomerApiKeyRefused;
use Cbox\Id\Organization\Models\CustomerApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * MY ACCOUNT › API KEYS — the keys a person holds for the apps built on this environment.
 *
 * An app with an API of its own lets its users call that API with a key, and the key is
 * made HERE, on the identity provider, rather than in a key table every app would
 * otherwise build for itself. The app declares a prefix; the person picks the app, the
 * organization the key acts in, and which of the permissions THEY hold it may carry; the
 * app verifies the key with its own credentials at `POST /oauth/api-keys/verify`.
 *
 * THE DEEP LINK IS A CONTRACT. SDKs send people to
 * `/account/api-keys?client_id=…&return_to=…`: `client_id` preselects the app and
 * `return_to` becomes a way back — a link, never a redirect, and only to an origin that app
 * registered. `organization=` picks the organization among the person's memberships; the
 * current one is the default. See docs/getting-started/let-your-customers-create-api-keys.md.
 *
 * NO STEP-UP ON MINTING, and that is a decision rather than an omission. The console's
 * step-up asks for a password, and the people this page is for often sign in through
 * their company's single sign-on and have none — a step-up would lock out exactly them.
 * What bounds a key instead: it can only carry permissions its holder holds, it loses them
 * the moment the holder does, it dies with the membership, and every organization
 * administrator sees it and can revoke it. Support sessions cannot mint one either: the
 * console is read-only while somebody is acting as another person.
 */
final readonly class AccountApiKeyController extends PageController
{
    public function index(Request $request, HolderApiKeys $holder, KeyApps $apps, AppApiKeyRows $rows): Response
    {
        $me = app(CurrentUser::class);

        abort_unless($me->check(), 403);

        $userId = $me->id();
        $organizations = $holder->organizationsOf($userId);
        $organization = $this->chosenOrganization($organizations, $request->query('organization'), $me->organizationId());

        $offered = $organization === null ? [] : $apps->offeredTo($organization->id);

        $requestedClientId = $this->queryString($request, 'client_id');
        $requested = $requestedClientId === null ? null : $this->find($offered, $requestedClientId);
        $returnTo = null;

        if ($requested !== null) {
            $url = $requested->returnTo($this->queryString($request, 'return_to'));
            $returnTo = $url === null ? null : ['url' => $url, 'appName' => $requested->name];
        }

        $keys = $organization === null ? [] : $rows->for(
            $holder->held($organization->id, $userId),
            withHolder: false,
            revokeHref: static fn (CustomerApiKey $key): string => route('account.api-keys.revoke', [
                'key' => $key->id,
                'organization' => $organization->id,
            ]),
        );

        return $this->page('account/api-keys', 'API keys', [
            'help' => HelpProps::for(HelpTopic::ApiKeys),
            'organizations' => array_map(fn (KeyOrganization $candidate): array => [
                'id' => $candidate->id,
                'name' => $candidate->name,
                // The query the page was opened with travels along: switching organization
                // is not a reason to lose the app somebody was sent here for.
                'href' => route('account.api-keys', array_filter([
                    'organization' => $candidate->id,
                    'client_id' => $requestedClientId,
                    'return_to' => $this->queryString($request, 'return_to'),
                ], static fn (?string $value): bool => $value !== null)),
            ], $organizations),
            'organizationId' => $organization?->id,
            'organizationName' => $organization?->name,
            'apps' => $organization === null ? [] : array_map(fn (KeyApp $app): array => [
                'clientId' => $app->clientId,
                'name' => $app->name,
                'prefix' => $app->prefix,
                'permissions' => array_map(static fn (KeyPermission $permission): array => [
                    'name' => $permission->name,
                    'description' => $permission->description,
                ], $apps->permissionsHeld($userId, $organization->id, $app->clientId)),
            ], $offered),
            // The app somebody was sent here for, or the only one there is.
            'selectedClientId' => $requested !== null ? $requested->clientId : (count($offered) === 1 ? $offered[0]->clientId : null),
            /*
             * SAID, NOT SILENTLY DROPPED. An SDK link naming an app that offers no keys to
             * this organization — no prefix, another organization's app, a typo — would
             * otherwise open an ordinary page and leave the person wondering where the app
             * went.
             */
            'requestedAppUnavailable' => $requestedClientId !== null && $requested === null,
            'returnTo' => $returnTo,
            'keys' => $keys,
            'lifetimes' => array_map(
                static fn (KeyLifetime $lifetime): array => ['value' => $lifetime->value, 'label' => $lifetime->label()],
                KeyLifetime::cases(),
            ),
            'storeHref' => route('account.api-keys.store'),
        ]);
    }

    public function store(IssueAppApiKeyRequest $request, HolderApiKeys $holder, KeyApps $apps): RedirectResponse
    {
        $me = app(CurrentUser::class);

        abort_unless($me->check(), 403);

        try {
            $issued = $holder->issue(
                userId: $me->id(),
                organizationId: $request->organizationId(),
                clientId: $request->clientId(),
                permissions: $request->permissions(),
                name: $request->keyName(),
                expiresAt: $request->expiresAt(),
            );
        } catch (CustomerApiKeyRefused $refused) {
            $explanation = RefusalExplanation::of(
                $refused,
                $apps->offered($request->organizationId(), $request->clientId())?->name,
            );

            return back()->withErrors([$explanation->field => $explanation->message])->withInput();
        }

        /*
         * The plaintext, on the flash channel and nowhere else. Props are written into the
         * browser's history entry; a credential there is readable by pressing Back, long
         * after the page that showed it has gone.
         */
        $this->inertia->flash('freshKey', $issued->plaintext);

        return back()->with('status', 'API key created — copy it now, it will not be shown again.');
    }

    /**
     * Revoke one of your own keys. The key is looked up with the signed-in person and the
     * organization IN the query, so an id from somebody else's list revokes nothing.
     */
    public function destroy(Request $request, string $key, HolderApiKeys $holder): RedirectResponse
    {
        $me = app(CurrentUser::class);

        abort_unless($me->check(), 403);

        $organizationId = $this->queryString($request, 'organization');

        if ($organizationId === null || ! $holder->revoke($organizationId, $me->id(), $key)) {
            return back();
        }

        return back()->with('status', 'API key revoked.');
    }

    /**
     * The organization the page is about: the one asked for when the person is a member of
     * it, else the one they are signed in to, else the first they belong to. An id they
     * are not an active member of is never honoured — it is a query string.
     *
     * @param  list<KeyOrganization>  $organizations
     */
    private function chosenOrganization(array $organizations, mixed $asked, ?string $current): ?KeyOrganization
    {
        foreach ([is_string($asked) ? $asked : null, $current] as $candidate) {
            foreach ($organizations as $organization) {
                if ($candidate !== null && $organization->id === $candidate) {
                    return $organization;
                }
            }
        }

        return $organizations[0] ?? null;
    }

    /**
     * @param  list<KeyApp>  $apps
     */
    private function find(array $apps, string $clientId): ?KeyApp
    {
        foreach ($apps as $app) {
            if ($app->clientId === $clientId) {
                return $app;
            }
        }

        return null;
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
