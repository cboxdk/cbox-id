<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use App\Platform\ScopeCatalog;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\AccessControl\Enums\RoleSource;
use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Authorization\Contracts\EntitlementWriter;
use Cbox\Id\Kernel\Authorization\Enums\EntitlementSource;
use Cbox\Id\Kernel\Authorization\ValueObjects\EntitlementInput;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Support\Facades\Http;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;

/** Entitle an org for a self-serve feature ('cbox-id-sso' | 'cbox-id-scim'). */
function entitle(string $organizationId, string $key): void
{
    app(EntitlementWriter::class)->set(
        $organizationId,
        new EntitlementInput($key, ['enabled' => true]),
        EntitlementSource::Manual,
    );
}

function owner(): string
{
    $subject = app(Subjects::class)->create('owner@acme.test', 'Owner', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-console'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    // An established owner has confirmed their address — see actingAsRole() in Pest.php.
    app(Subjects::class)->markEmailVerified($subject->id, (string) $subject->email);
    $subject = app(Subjects::class)->find($subject->id) ?? $subject;

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Owner);

    // AND THE SESSION KEY THE CONSOLE'S GUARD READS ON THE WAY IN. `CurrentUser` is
    // resolved state for code already inside the process, which is all a Livewire
    // component ever needed; a ported page is reached by a REQUEST, and without this every
    // one of them answers a redirect to /login.
    session([PlatformAuth::SESSION_KEY => $session->id]);

    return $org->id;
}

it('registers a SCIM directory and reveals a bearer token once', function () {
    $orgId = owner();
    entitle($orgId, 'cbox-id-scim');

    // Registration lives on the merged create page now, and the reveal-once token lands
    // on the directory's own detail page — flashed across the single redirect, because a
    // token that is shown once needs somewhere to be shown that is not the row you just
    // submitted.
    confirmConsoleStepUp();
    registerDirectory(['name' => 'Okta'])->assertSessionHasNoErrors();

    $directory = Directory::query()->where('organization_id', $orgId)->where('name', 'Okta')->firstOrFail();

    // ON THE FLASH CHANNEL, which is what makes "shown once" true: props are written into
    // the browser's history entry, and a live bearer token there is readable by pressing
    // Back long after the page that showed it has gone.
    // `hasFlash()` compares with assertSame, so a predicate is not something it can take
    // — the value is read and asserted here instead.
    $flash = session()->get(SessionKey::FLASH_DATA, []);
    $token = is_array($flash) ? ($flash['newToken'] ?? null) : null;

    expect($token)->toBeString()->toStartWith('scim_');

    $shown = test()->get(route('directories.show', $directory->id))->assertOk();

    $shown->assertInertia(fn (AssertableInertia $page) => $page->hasFlash('newToken', $token));

    expect(json_encode($shown->inertiaProps()))->toBeString()->not->toContain('scim_');

    // …and the next visit carries nothing at all.
    test()->get(route('directories.show', $directory->id))
        ->assertOk()
        ->assertInertiaFlashMissing('newToken');
});

it('registers an OAuth client for the organization', function () {
    $orgId = owner();

    // A machine-to-machine app. The form asks ONE question — what kind of app is this —
    // and the client type, the grants and the absence of a redirect URI all follow from
    // the answer; only the API scopes are still typed in.
    confirmConsoleStepUp();
    registerApp([
        'name' => 'CI Pipeline',
        'kind' => 'service',
        'scopes' => [],
        'customScopes' => 'api.read, api.write',
    ])->assertSessionHasNoErrors();

    $client = Client::query()->where('organization_id', $orgId)->first();
    expect($client)->not->toBeNull()
        ->and($client->name)->toBe('CI Pipeline')
        ->and($client->scopes)->toContain('api.read')
        ->and($client->grant_types)->toBe(['client_credentials'])
        // No redirect URI was asked for and none was invented.
        ->and($client->redirect_uris)->toBe([]);
});

/**
 * The kind that had no way to exist.
 *
 * A CLI needs the device grant, a public client and no redirect URI. The form offered
 * two checkboxes — "sign people in" and "call the API as itself" — and neither produces
 * that combination, so the flow this platform is best at could not be registered from
 * the page that registers apps for it. The only route to one was an artisan command we
 * wrote for our own CLI.
 */
it('registers a CLI app with the device grant and no redirect URI', function () {
    owner();

    confirmConsoleStepUp();
    registerApp(['name' => 'Acme CLI', 'kind' => 'cli'])->assertSessionHasNoErrors();

    $client = Client::query()->where('name', 'Acme CLI')->firstOrFail();

    expect($client->grant_types)->toBe(['urn:ietf:params:oauth:grant-type:device_code', 'refresh_token'])
        // Public: a binary on somebody's laptop cannot keep a secret, and the form does
        // not offer to pretend otherwise once "CLI" is the answer.
        ->and($client->type)->toBe(ClientType::Public)
        ->and($client->redirect_uris)->toBe([])
        // Registered for `offline_access`, because it registered for `refresh_token`. A
        // device request naming a scope outside the ceiling is refused outright, so a
        // ceiling that omits it turns the first `cbox login` into an error.
        ->and($client->scopes)->toContain('offline_access');
});

/**
 * And the escape hatch stays reachable, or the presets become a cage: the combinations
 * nobody anticipated are exactly the ones a preset list cannot contain.
 */
it('still lets an advanced registration pick its own grants', function () {
    owner();

    confirmConsoleStepUp();
    registerApp([
        'name' => 'Hybrid App',
        'kind' => 'advanced',
        'type' => 'confidential',
        'grantAuthorizationCode' => true,
        'grantClientCredentials' => true,
        'redirectUris' => 'https://hybrid.acme.test/cb',
    ])->assertSessionHasNoErrors();

    expect(Client::query()->where('name', 'Hybrid App')->firstOrFail()->grant_types)
        ->toBe(['authorization_code', 'refresh_token', 'client_credentials']);
});

// Sign-out only returns people to the app when the requested post_logout_redirect_uri
// is on this client's registered allow-list, byte for byte — so the org console has to
// be able to write that list, or every sign-out ends on Cbox ID's bare page.
it('registers an OAuth client with post-logout redirect URIs', function () {
    $orgId = owner();

    confirmConsoleStepUp();
    registerApp([
        'name' => 'Support Portal',
        'redirectUris' => 'https://portal.acme.test/auth/callback',
        'postLogoutRedirectUris' => 'https://portal.acme.test/signed-out',
    ])->assertSessionHasNoErrors();

    $client = Client::query()->where('organization_id', $orgId)->where('name', 'Support Portal')->first();
    expect($client)->not->toBeNull()
        ->and($client->post_logout_redirect_uris)->toBe(['https://portal.acme.test/signed-out']);
});

it('creates and activates a SAML connection', function () {
    $orgId = owner();
    entitle($orgId, 'cbox-id-sso');

    createConnection()->assertSessionHasNoErrors();

    expect(Connection::query()->where('organization_id', $orgId)->where('name', 'Corporate SAML')->exists())->toBeTrue();
});

it('forbids a non-admin from registering a directory', function () {
    $subject = app(Subjects::class)->create('member@acme.test', 'Member');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-m'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Member);

    // The read gate blocks a member at the door — they never reach the write.
    confirmConsoleStepUp();
    session([PlatformAuth::SESSION_KEY => $session->id]);

    test()->get(route('directories.create'))->assertForbidden();
    registerDirectory(['name' => 'Okta'])->assertForbidden();
});

function member(): string
{
    $subject = app(Subjects::class)->create('plain@acme.test', 'Plain Member');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-reader'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Member);

    // AND THE SESSION KEY THE CONSOLE'S GUARD READS ON THE WAY IN. `CurrentUser` is
    // resolved state for code already inside the process, which is all a Livewire
    // component ever needed; a ported page is reached by a REQUEST, and without this every
    // one of them answers a redirect to /login.
    session([PlatformAuth::SESSION_KEY => $session->id]);

    return $org->id;
}

/**
 * THE SAME PAGES THE PORT HAS MOVED, asked over HTTP.
 *
 * A controller has no snapshot to drive, so the question is the ordinary one: a plain
 * member requests the page and must not be given it. Stronger than the component check
 * beside it, because the whole stack answers — middleware, scope, controller.
 */
it('forbids a non-admin member from reading a ported admin console page', function (string $route) {
    member();

    $response = test()->get(route($route));

    // Refused OR redirected somewhere they can be — the console does both, deliberately,
    // and which one is the page's own business. What must not happen is 200.
    expect($response->status())->not->toBe(200);
})->with(['webhooks', 'clients', 'connections', 'directories', 'roles', 'audit', 'permissions']);

/**
 * The starter snippet has to be for the app you are looking at.
 *
 * It rendered only for `authorization_code`, so a CLI and a service — the two kinds
 * where nobody has an existing snippet to adapt — got nothing at the one moment a
 * copy-paste is worth most: the screen that reveals the secret once. And what it did
 * render called an API the SDK does not have, `new CboxID(…)` with `id.signIn()`.
 *
 * A snippet nobody ran is worse than no snippet. It is read as the documented way, and
 * it fails in the reader's editor with no clue that the console was wrong rather than
 * their typing.
 */
it('shows a CLI app the device-flow snippet, not a redirect one', function () {
    owner();

    confirmConsoleStepUp();
    registerApp(['name' => 'Snippet CLI', 'kind' => 'cli'])->assertSessionHasNoErrors();

    $client = Client::query()->where('name', 'Snippet CLI')->firstOrFail();

    // Asserted on the SNIPPETS themselves rather than on the document: the page renders in
    // the browser, and the code a person copies is a prop.
    // JSON_UNESCAPED_SLASHES, so a package name reads as `@cboxdk/id-js` rather than as
    // `@cboxdk\/id-js` — the escaped form matches nothing anybody would search for.
    $snippets = json_encode(
        test()->get(route('clients.show', $client->id))->assertOk()->inertiaProps('snippets'),
        JSON_UNESCAPED_SLASHES,
    );

    expect($snippets)
        ->toContain('requestDeviceAuthorization')
        ->toContain('pollDeviceToken')
        // Not the browser flow: a CLI has no callback URL, and offering it one here
        // undoes the whole point of the kind it was registered as.
        ->not->toContain('createAuthorizationRequest')
        ->not->toContain('redirectUri');
});

/**
 * And no link to a package that does not exist. `pypi.org/project/cbox-id` was listed
 * beside the working SDKs; nothing has ever been published there.
 */
it('offers only SDKs that can actually be installed', function () {
    owner();

    confirmConsoleStepUp();
    registerApp([
        'name' => 'Snippet Web',
        'kind' => 'web',
        'redirectUris' => 'https://snippet.acme.test/cb',
    ])->assertSessionHasNoErrors();

    $client = Client::query()->where('name', 'Snippet Web')->firstOrFail();

    $html = json_encode(
        test()->get(route('clients.show', $client->id))->assertOk()->inertiaProps('snippets'),
        JSON_UNESCAPED_SLASHES,
    );

    expect($html)
        ->toContain('@cboxdk/id-js')
        // The real class and the real calls, so the snippet compiles where it is pasted.
        ->toContain('CboxIdClient')
        ->toContain('createAuthorizationRequest')
        ->not->toContain('pypi.org');
});

/**
 * JSX escaping in a Blade file prints the escaping.
 *
 * The starter snippet wrote `{'{'}` where it wanted a brace — the React idiom — and Blade
 * has no such rule: only `{{ }}` is special, a lone brace is a lone brace. So the console
 * showed every developer `new CboxIdClient({'{'}` and they copied it. Nothing in the suite
 * could see it, because the assertion everyone writes is `toContain('CboxIdClient')`.
 *
 * Swept across the console rather than asserted on one page: the idiom spreads by
 * copy-paste, which is how it reached a Blade file in the first place.
 */
it('never escapes braces the way JSX does', function () {
    $offenders = [];

    foreach (['resources/views', 'modules'] as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root)));

        foreach ($files as $file) {
            if (! str_ends_with((string) $file, '.blade.php')) {
                continue;
            }

            $source = (string) file_get_contents((string) $file);

            if (str_contains($source, "{'{'}") || str_contains($source, "{'}'}")) {
                $offenders[] = str_replace(base_path().'/', '', (string) $file);
            }
        }
    }

    expect($offenders)->toBe([]);
});

/**
 * The list has to say what each app is.
 *
 * It drew "Sign-in" for `authorization_code` and "API" for `client_credentials`, read
 * straight off the grants — so a CLI, whose grant is neither, drew no label at all and
 * sat in the list as an unexplained "Public". The one kind whose author most needs to
 * find it again was the one the list could not describe.
 */
it('names every kind of app in the list, including the ones with neither grant', function () {
    owner();

    confirmConsoleStepUp();
    registerApp(['name' => 'List CLI', 'kind' => 'cli'])->assertSessionHasNoErrors();

    confirmConsoleStepUp();
    registerApp(['name' => 'List Service', 'kind' => 'service'])->assertSessionHasNoErrors();

    confirmConsoleStepUp();
    registerApp([
        'name' => 'List Web',
        'kind' => 'web',
        'redirectUris' => 'https://list.acme.test/cb',
    ])->assertSessionHasNoErrors();

    // The LABEL each row carries, read off the rows. It is one word per app that answers
    // "what is this?", and it used to be drawn from the grants directly — so a CLI, whose
    // grant is neither of the two the old code knew, drew nothing at all.
    $labels = collect((array) test()->get(route('clients'))->assertOk()->inertiaProps('clients'))
        ->pluck('kindLabel');

    expect($labels)
        ->toContain('CLI or device')
        ->toContain('Service or background job')
        ->toContain('Web app');
});

/**
 * The console says a role is "a label stamped into the token" and then never shows the
 * token.
 *
 * That is the last mile of the whole model: the person configuring roles here and the
 * developer reading them in an app are usually the same person, an hour apart, and
 * nothing on this page told them WHICH claim to read. The two most common guesses are
 * `scope` — which is what the app was allowed to ask for, a different question — and a
 * nested `authorization` object, which this platform does not emit at all.
 *
 * Built from this environment's own role, because a generic example is one more thing to
 * translate.
 */
it('shows the claim shape an app receives, using a real role', function () {
    $orgId = owner();

    app(Roles::class);

    Role::query()->create([
        'environment_id' => app(EnvironmentContext::class)->requireEnvironment()->environmentKey(),
        'organization_id' => $orgId,
        'name' => 'Support agent',
        'source' => RoleSource::Manual,
    ]);

    // THE SERVER HALF: the environment's own role, which the snippet is built from. The
    // claim names are drawn by the page, so a request cannot see them — that half is held
    // by tests/Browser/RolesTest.php, in a browser that actually renders it.
    test()->get(route('roles'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('sample.role', 'Support agent')
            ->where('sample.permissions', []));
});

/**
 * THE ISSUER ON THE APP PAGE IS THE ONE THE TOKENS WILL CARRY.
 *
 * It read `config('app.url')` — the apex the deployment is installed at — so an app
 * registered in a tenant environment on its own host was told, from a page served at
 * that host, that its issuer was the apex. Every developer copying that into an SDK
 * pointed it at a host that serves no discovery document at all, and the failure arrives
 * one layer down as "discovery request failed", blaming the SDK rather than the console
 * that handed over the wrong value.
 *
 * Asserted against what `/.well-known/openid-configuration` actually answers, not against
 * a second copy of the expected string: the whole defect was two sources disagreeing, and
 * a test with its own third source could not have seen it.
 */
it('shows the issuer that discovery actually serves', function () {
    owner();

    confirmConsoleStepUp();
    registerApp([
        'name' => 'Issuer Check',
        'kind' => 'web',
        'redirectUris' => 'https://issuer.acme.test/cb',
    ])->assertSessionHasNoErrors();

    $client = Client::query()->where('name', 'Issuer Check')->firstOrFail();

    $served = $this->getJson('/.well-known/openid-configuration')->assertOk()->json('issuer');

    expect($served)->toBeString()->not->toBeEmpty();

    expect(test()->get(route('clients.show', $client->id))->assertOk()->inertiaProps('issuer'))
        ->toBe($served);

    // And the Settings page, which had a THIRD derivation of the same value —
    // `'https://'.request()->getHost()`, right for a tenant on its own subdomain and
    // right only by coincidence for the platform root, which keeps its configured
    // issuer whatever host answers.
    //
    // Asserted on the PROP rather than on the rendered page: this one is exact, where
    // "the document mentions the string somewhere" would also pass if the page printed
    // the right issuer beside a wrong one.
    expect(test()->get(route('settings'))->assertOk()->inertiaProps('issuer'))->toBe($served);
});

/**
 * Scopes were badges.
 *
 * To add one to a live app you deleted it and registered a new one — taking its client
 * id and secret, and every integration holding them, with it, to add a string to a list.
 */
it('lets an app’s scopes be edited without re-registering it', function () {
    owner();

    confirmConsoleStepUp();
    registerApp([
        'name' => 'Scope Edit',
        'kind' => 'web',
        'redirectUris' => 'https://scope.acme.test/cb',
    ])->assertSessionHasNoErrors();

    $client = Client::query()->where('name', 'Scope Edit')->firstOrFail();
    $before = $client->client_id;

    // Seeded from the record, split so a hand-typed key is not silently dropped.
    $showing = (array) test()->get(route('clients.show', $client->id))
        ->assertOk()
        ->inertiaProps('client');

    expect($showing['scopes'] ?? [])->toContain('openid');

    test()->from(route('clients.show', $client->id))
        ->patch(route('clients.update', $client->id), [
            'name' => $showing['name'],
            'redirectUris' => $showing['redirectUris'],
            'postLogoutRedirectUris' => $showing['postLogoutRedirectUris'],
            'scopes' => ['openid', 'profile'],
            'customScopes' => 'tax.data, api.read',
        ])
        ->assertSessionHasNoErrors();

    $client->refresh();

    expect($client->scopes)->toContain('openid')
        ->toContain('tax.data')
        ->toContain('api.read')
        // Narrowed, not merely added to.
        ->not->toContain('offline_access')
        // And the credentials are the same ones every integration already holds.
        ->and($client->client_id)->toBe($before);
});

/**
 * A key the catalog does not know must survive an edit, or the first save after adding
 * one silently removes it — and the failure lands on whoever calls the API next.
 */
it('keeps a custom scope through an unrelated edit', function () {
    owner();

    confirmConsoleStepUp();
    registerApp([
        'name' => 'Custom Scope',
        'kind' => 'web',
        'redirectUris' => 'https://custom.acme.test/cb',
        'customScopes' => 'tax.data',
    ])->assertSessionHasNoErrors();

    $client = Client::query()->where('name', 'Custom Scope')->firstOrFail();

    // The form as the page hands it back, with ONE field changed — which is what an
    // unrelated edit is, and the only shape in which the custom key can be dropped.
    $showing = (array) test()->get(route('clients.show', $client->id))
        ->assertOk()
        ->inertiaProps('client');

    test()->from(route('clients.show', $client->id))
        ->patch(route('clients.update', $client->id), [
            'name' => 'Custom Scope Renamed',
            'redirectUris' => $showing['redirectUris'],
            'postLogoutRedirectUris' => $showing['postLogoutRedirectUris'],
            'scopes' => $showing['scopes'],
            'customScopes' => $showing['customScopes'],
        ])
        ->assertSessionHasNoErrors();

    expect($client->refresh()->scopes)->toContain('tax.data');
});

/**
 * "Where do I create a group?" is the question, and the answer was in a code comment.
 *
 * An app that authorizes from a `groups` claim — Kubernetes, Grafana, Vault, and most
 * SaaS written before this vocabulary settled — gets the person's ROLE names under that
 * claim, once the app's `groups` scope is on. There is nothing else to create. The only
 * other place the console says "group" is directory sync, which goes the opposite way
 * and applies only when somebody ELSE is the identity provider — so somebody whose IdP
 * is Cbox ID went looking there and found instructions for a system they do not have.
 */
it('says on the roles page that roles are the groups a token carries', function () {
    $orgId = owner();

    Role::query()->create([
        'environment_id' => app(EnvironmentContext::class)->requireEnvironment()->environmentKey(),
        'organization_id' => $orgId,
        'name' => 'Support agent',
        'source' => RoleSource::Manual,
    ]);

    // Same split as above: the role the `groups` snippet is built from is the server's,
    // the sentence about Kubernetes and Grafana is the page's. See tests/Browser/RolesTest.php.
    test()->get(route('roles'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('sample.role', 'Support agent'));
});

/**
 * The consent screen showed people raw scope keys.
 *
 * It carried its own map of four labels and fell back to the key for everything else, so
 * somebody deciding whether to allow an app was shown the literal word "groups" — the
 * most end-user-facing page in the product, and the one scope whose name matches nothing
 * else in this console. `organizations` had the same problem. Both were added to the
 * token issuer, to discovery and to the app picker, and nobody came back here.
 *
 * One list, two voices: the picker addresses an administrator registering an app, this
 * addresses the person deciding whether to allow it.
 */
it('gives the consent screen a human phrase for every catalog scope', function () {
    $labels = app(ScopeCatalog::class)->consentLabels();

    // The two that were bare, and the four that were already fine.
    expect($labels)->toHaveKeys(['openid', 'profile', 'email', 'offline_access', 'organizations', 'groups'])
        ->and($labels['groups'])->not->toBe('groups')
        ->and($labels['organizations'])->not->toBe('organizations');

    // A CUSTOM scope has no entry, and rendering its key verbatim is right: it is the
    // app's own word and there is nothing truer to say about it.
    expect($labels)->not->toHaveKey('tax.data');
});

/**
 * @group security
 *
 * A tenant administrator may not mint a connection the whole environment signs in
 * through. The checkbox is not rendered for them, which is not the guard — the property
 * is client-settable, so the write asks again.
 *
 * Discovery is faked here deliberately: without it the OIDC branch fails to read the
 * provider's configuration and refuses for that reason instead, and the test would pass
 * with the plane guard deleted. It did, until this line was added.
 */
it('refuses an environment-owned SSO connection from the organization plane', function (): void {
    owner();

    config(['cbox-id.federation.verify_url' => false]);
    Http::fake(['idp.rival/.well-known/openid-configuration' => Http::response([
        'issuer' => 'https://idp.rival',
        'authorization_endpoint' => 'https://idp.rival/oauth2/authorize',
        'token_endpoint' => 'https://idp.rival/oauth2/token',
        'jwks_uri' => 'https://idp.rival/oauth2/keys',
    ])]);

    createConnection([
        'environmentWide' => true,
        'type' => 'oidc',
        'name' => 'Sneaky Okta',
        'issuer' => 'https://idp.rival',
        'client_id' => 'abc',
        'client_secret' => 'shh',
        'signing_key' => 'a-signing-key',
    ]);

    expect(Connection::query()->where('name', 'Sneaky Okta')->exists())->toBeFalse();
})->group('security');
