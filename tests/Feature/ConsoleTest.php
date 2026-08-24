<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
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
use Livewire\Volt\Volt;

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
    Volt::test('console.directories.create')
        ->set('name', 'Okta')
        ->call('register')
        ->assertHasNoErrors();

    $directory = Directory::query()->where('organization_id', $orgId)->where('name', 'Okta')->firstOrFail();

    // The token is protected (never dehydrated into the wire snapshot), so assert the
    // one-time reveal on the rendered output rather than reaching into component state.
    Volt::test('console.directories.show', ['directory' => $directory->id])->assertSee('scim_');
});

it('registers an OAuth client for the organization', function () {
    $orgId = owner();

    // A machine-to-machine app. The form asks ONE question — what kind of app is this —
    // and the client type, the grants and the absence of a redirect URI all follow from
    // the answer; only the API scopes are still typed in.
    confirmConsoleStepUp();
    Volt::test('console.clients.create')
        ->set('name', 'CI Pipeline')
        ->set('kind', 'service')
        ->set('selectedScopes', [])
        ->set('customScopes', 'api.read, api.write')
        ->call('create')
        ->assertHasNoErrors();

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
    Volt::test('console.clients.create')
        ->set('name', 'Acme CLI')
        ->set('kind', 'cli')
        ->call('create')
        ->assertHasNoErrors();

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
    Volt::test('console.clients.create')
        ->set('name', 'Hybrid App')
        ->set('kind', 'advanced')
        ->set('type', 'confidential')
        ->set('grantAuthorizationCode', true)
        ->set('grantClientCredentials', true)
        ->set('redirectUris', 'https://hybrid.acme.test/cb')
        ->call('create')
        ->assertHasNoErrors();

    expect(Client::query()->where('name', 'Hybrid App')->firstOrFail()->grant_types)
        ->toBe(['authorization_code', 'refresh_token', 'client_credentials']);
});

// Sign-out only returns people to the app when the requested post_logout_redirect_uri
// is on this client's registered allow-list, byte for byte — so the org console has to
// be able to write that list, or every sign-out ends on Cbox ID's bare page.
it('registers an OAuth client with post-logout redirect URIs', function () {
    $orgId = owner();

    confirmConsoleStepUp();
    Volt::test('console.clients.create')
        ->set('name', 'Support Portal')
        ->set('grantAuthorizationCode', true)
        ->set('redirectUris', 'https://portal.acme.test/auth/callback')
        ->set('postLogoutRedirectUris', 'https://portal.acme.test/signed-out')
        ->call('create')
        ->assertHasNoErrors();

    $client = Client::query()->where('organization_id', $orgId)->where('name', 'Support Portal')->first();
    expect($client)->not->toBeNull()
        ->and($client->post_logout_redirect_uris)->toBe(['https://portal.acme.test/signed-out']);
});

it('creates and activates a SAML connection', function () {
    $orgId = owner();
    entitle($orgId, 'cbox-id-sso');

    Volt::test('console.connections.create')
        ->set('type', 'saml')
        ->set('name', 'Corporate SAML')
        ->set('idp_entity_id', 'https://idp.corp/metadata')
        ->set('idp_sso_url', 'https://idp.corp/sso')
        ->set('idp_x509cert', '-----BEGIN CERTIFICATE-----MIIB-----END CERTIFICATE-----')
        ->set('sp_entity_id', 'https://sp.acme/metadata')
        ->set('sp_acs_url', 'https://sp.acme/acs')
        ->call('create')
        ->assertHasNoErrors();

    expect(Connection::query()->where('organization_id', $orgId)->where('name', 'Corporate SAML')->exists())->toBeTrue();
});

it('forbids a non-admin from registering a directory', function () {
    $subject = app(Subjects::class)->create('member@acme.test', 'Member');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-m'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Member);

    // The read gate now blocks a member at mount — they never reach register().
    confirmConsoleStepUp();
    Volt::test('console.directories.create')->assertForbidden();
});

function member(): string
{
    $subject = app(Subjects::class)->create('plain@acme.test', 'Plain Member');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-reader'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Member);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, MembershipRole::Member);

    return $org->id;
}

it('forbids a non-admin member from reading admin console pages', function (string $page) {
    member();

    // Not just the write buttons — the whole page (org-wide config, secrets,
    // audit) must be unreadable to a plain member.
    Volt::test($page)->assertForbidden();
})->with(['console.audit', 'console.clients.index', 'console.connections.index', 'console.directories.index', 'console.roles.index', 'console.webhooks.index']);

it('re-authorizes org-admin console pages on every request via boot(), not just mount()', function () {
    // A member who is an admin at mount, then demoted mid-session. The read gate must
    // catch the demotion on the NEXT request — proving the guard lives in boot()
    // (runs every hydration), not mount() (runs once). Under a mount()-only gate the
    // open snapshot would keep re-rendering org-wide SSO/SCIM/role/webhook config.
    $subject = app(Subjects::class)->create('demote@acme.test', 'Dee', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-demote'));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    $cu = app(CurrentUser::class);
    $cu->set($subject, $session, $org, MembershipRole::Owner);

    entitle($org->id, 'cbox-id-sso');
    entitle($org->id, 'cbox-id-scim');

    foreach (['console.connections.index', 'console.directories.index', 'console.roles.index', 'console.webhooks.index'] as $page) {
        $component = Volt::test($page)->assertOk();          // mounts fine as admin

        $cu->set($subject, $session, $org, MembershipRole::Member); // demoted

        $component->call('$refresh')->assertForbidden();     // boot() re-checks → 403

        $cu->set($subject, $session, $org, MembershipRole::Owner);  // restore for next page
    }
});

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
    Volt::test('console.clients.create')
        ->set('name', 'Snippet CLI')
        ->set('kind', 'cli')
        ->call('create')
        ->assertHasNoErrors();

    $client = Client::query()->where('name', 'Snippet CLI')->firstOrFail();

    $html = Volt::test('console.clients.show', ['client' => $client->id])->html();

    expect($html)
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
    Volt::test('console.clients.create')
        ->set('name', 'Snippet Web')
        ->set('kind', 'web')
        ->set('redirectUris', 'https://snippet.acme.test/cb')
        ->call('create')
        ->assertHasNoErrors();

    $client = Client::query()->where('name', 'Snippet Web')->firstOrFail();

    $html = Volt::test('console.clients.show', ['client' => $client->id])->html();

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
    Volt::test('console.clients.create')->set('name', 'List CLI')->set('kind', 'cli')->call('create')->assertHasNoErrors();

    confirmConsoleStepUp();
    Volt::test('console.clients.create')->set('name', 'List Service')->set('kind', 'service')->call('create')->assertHasNoErrors();

    confirmConsoleStepUp();
    Volt::test('console.clients.create')
        ->set('name', 'List Web')->set('kind', 'web')->set('redirectUris', 'https://list.acme.test/cb')
        ->call('create')->assertHasNoErrors();

    $html = Volt::test('console.clients.index')->html();

    expect($html)
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

    $html = Volt::test('console.roles.index')->html();

    expect($html)
        ->toContain('What your app receives')
        // The real claim names, and this environment's real role in them.
        ->toContain('"roles"')
        ->toContain('"permissions"')
        ->toContain('Support agent')
        // And the distinction that the page exists to make.
        ->toContain('what the');
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
    Volt::test('console.clients.create')
        ->set('name', 'Issuer Check')
        ->set('kind', 'web')
        ->set('redirectUris', 'https://issuer.acme.test/cb')
        ->call('create')
        ->assertHasNoErrors();

    $client = Client::query()->where('name', 'Issuer Check')->firstOrFail();

    $served = $this->getJson('/.well-known/openid-configuration')->assertOk()->json('issuer');

    expect($served)->toBeString()->not->toBeEmpty();

    expect(Volt::test('console.clients.show', ['client' => $client->id])->html())
        ->toContain($served);

    // And the Settings page, which had a THIRD derivation of the same value —
    // `'https://'.request()->getHost()`, right for a tenant on its own subdomain and
    // right only by coincidence for the platform root, which keeps its configured
    // issuer whatever host answers.
    expect(Volt::test('console.settings')->html())->toContain($served);
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
    Volt::test('console.clients.create')
        ->set('name', 'Scope Edit')->set('kind', 'web')
        ->set('redirectUris', 'https://scope.acme.test/cb')
        ->call('create')->assertHasNoErrors();

    $client = Client::query()->where('name', 'Scope Edit')->firstOrFail();
    $before = $client->client_id;

    Volt::test('console.clients.show', ['client' => $client->id])
        // Seeded from the record, split so a hand-typed key is not silently dropped.
        ->assertSet('editScopes', fn (array $s): bool => in_array('openid', $s, true))
        ->set('editScopes', ['openid', 'profile'])
        ->set('editCustomScopes', 'tax.data, api.read')
        ->call('saveDetails')
        ->assertHasNoErrors();

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
    Volt::test('console.clients.create')
        ->set('name', 'Custom Scope')->set('kind', 'web')
        ->set('redirectUris', 'https://custom.acme.test/cb')
        ->set('customScopes', 'tax.data')
        ->call('create')->assertHasNoErrors();

    $client = Client::query()->where('name', 'Custom Scope')->firstOrFail();

    Volt::test('console.clients.show', ['client' => $client->id])
        ->set('editName', 'Custom Scope Renamed')
        ->call('saveDetails')
        ->assertHasNoErrors();

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

    expect(Volt::test('console.roles.index')->html())
        ->toContain('groups')
        // The claim, with this environment's own role in it.
        ->toContain('"groups"')
        ->toContain('Support agent');
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
