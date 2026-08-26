<?php

declare(strict_types=1);

use App\Http\Middleware\PointAtFirstRun;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\ConsoleStepUp;
use App\Platform\CurrentUser;
use App\Platform\Enums\AttemptOutcome;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\EnvironmentSudo;
use App\Platform\OperatorEnvironment;
use App\Platform\PlaneResolver;
use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use Cbox\Id\Devices\Enums\DevicePlatform;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Identity\Contracts\Passkeys;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Exceptions\UnknownCredential;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\Kernel\Authorization\Contracts\EntitlementWriter;
use Cbox\Id\Kernel\Authorization\Enums\EntitlementSource;
use Cbox\Id\Kernel\Authorization\ValueObjects\EntitlementInput;
use Cbox\Id\Kernel\Crypto\Contracts\SecretBox;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Models\PlatformOperator;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Cbox\Id\SamlIdp\Contracts\ServiceProviders;
use Cbox\Id\SamlIdp\Enums\NameIdFormat;
use Cbox\Id\SamlIdp\Models\ServiceProvider;
use Cbox\Id\SamlIdp\ValueObjects\NewServiceProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

// Pest 4 browser tests (real Chromium via Playwright) — boot the full app the same
// way, so `visit()` drives the running application with its middleware and DB.
uses(TestCase::class, RefreshDatabase::class)->in('Browser');

/**
 * Stand up the PLATFORM-ROOT environment ("tenant 1"), the environment account members
 * live in as ordinary subjects. Idempotent — a deployment has exactly one, and so does a
 * test. Provision accounts AFTER calling this: an account provisioned with no root is in
 * the first-install bootstrap window, where its members have no subject yet.
 *
 * See docs/core-concepts/unified-identity.md.
 */
function platformRootEnvironment(): Environment
{
    $existing = Environment::query()->where('is_default', true)->first();

    if ($existing !== null) {
        return $existing;
    }

    return Environment::query()->create([
        'name' => 'Platform',
        'slug' => 'platform-root-'.Str::lower((string) Str::ulid()),
        'type' => EnvironmentType::Production,
        'status' => EnvironmentStatus::Active,
        'is_default' => true,
        'settings' => [],
    ]);
}

/**
 * State that this deployment has been INSTALLED — the precondition every page in the
 * product has always had, and that nothing had to say out loud until there was an
 * installer.
 *
 * An unclaimed deployment now answers every human-facing page with a redirect to its
 * first-run screen ({@see PointAtFirstRun}), because a fresh box
 * serving a sign-in form no credential can satisfy reads as a broken product rather than
 * an unfinished install. So a test that renders a page has to stand on a platform
 * somebody installed.
 *
 * A TENANT ORGANIZATION is what it writes, and nothing else. Any occupant would do, and
 * the two obvious alternatives both change the test's world: an operator drags the
 * console's authority resolution in behind it, and a platform-root environment changes
 * which environment an unmapped host resolves to (see {@see serveOnTestHost()}) — so the
 * cheapest true statement is the right one. Idempotent.
 */
function installedDeployment(): void
{
    if (Organization::query()->doesntExist()) {
        app(Organizations::class)->create(new NewOrganization('Installed', 'installed-deployment'));
    }
}

/**
 * Make an environment the one this test's HTTP host resolves to, by stamping the app
 * URL's host on it as a verified custom domain.
 *
 * Tests used to steer requests by pointing `environments.default` at an environment,
 * which worked only because no `is_default` row existed — SetEnvironment falls back to
 * the configured key only when there is no platform root at all. With a platform root in
 * place (as every real deployment has), an unmapped host resolves to the ROOT, so a test
 * that wants to be ON a tenant must reach it the way a tenant admin actually does: by its
 * own host.
 *
 * Idempotent and first-come: an environment can only own a host if no other one already
 * does, so a test that provisions several gets host resolution for the first.
 */
/**
 * Give this environment the suite's own host, so a request that arrives on it resolves
 * here rather than falling back to the platform root.
 *
 * ADDRESS THE RESULT BY `$environment->domain`, never by a literal. The host comes from
 * `app.url`, which is the developer's `.env` — `https://cbox-id.test` on a machine set up
 * for Herd, `http://localhost` on CI, which copies `.env.example`. Two tests wrote
 * `https://cbox-id.test` into the request by hand and passed for a year on the machines
 * where those two happened to agree, then failed on every CI engine at once: the fixture
 * answered on one host and the request arrived at another, so the redirect chain ended
 * somewhere neither test asserted.
 *
 * Returns early — leaving `domain` null — when there is no host to take or another
 * environment already holds it, so a caller that ignores the return value and assumes a
 * domain was set is making the same mistake in a quieter way.
 */
function serveOnTestHost(Environment $environment): Environment
{
    $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

    if ($host === '' || Environment::query()->where('domain', $host)->whereKeyNot($environment->id)->exists()) {
        return $environment;
    }

    $environment->forceFill(['domain' => $host, 'domain_verified_at' => now()])->save();

    return $environment;
}

/**
 * Provision a customer and return every piece of it.
 *
 * ONE DEFINITION, HERE. Four test files each carried their own behind a `function_exists`
 * guard, so whichever loaded first won and the other three silently ran against a fixture
 * they had not written — with different keys, a different platform-root helper and, after
 * the fold, a different return shape. A suite whose fixtures depend on file order is a
 * suite whose failures depend on file order, which is exactly what made the console tests
 * pass alone and fail together.
 *
 * @return array{member: Membership, subjectId: string, organization: Organization, project: Project, environment: Environment}
 */
function provisionAccount(string $email = 'owner@acme.example'): array
{
    platformRootEnvironment();

    $result = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: $email,
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    return [
        'member' => $result->membership,
        'subjectId' => $result->owner->id,
        'organization' => $result->organization,
        'project' => $result->project,
        'environment' => $result->environment,
    ];
}

/**
 * A member with a role, ready to sign in — the same shape {@see addMember()} returns.
 *
 * Kept as a name of its own because a dozen tests read better for it, and because it was
 * one of the duplicated definitions above.
 *
 * @return array{0: Membership, 1: string}
 */
function memberWithRole(string $organizationId, MembershipRole $role, string $email): array
{
    return addMember($organizationId, $role, $email);
}

/**
 * The environments a customer owns, THROUGH ITS PROJECTS.
 *
 * `environments.account_id` was a denormalized copy of ownership and is gone, so this is a
 * join rather than a column. It exists as a fixture because a dozen tests asked the same
 * question of that column, and a test that reads a dropped column does not fail — Eloquent
 * answers null and the query counts zero, which is the shape a "nothing was provisioned"
 * assertion passes on for the wrong reason.
 *
 * @return Builder<Environment>
 */
function environmentsOwnedBy(string $organizationId): Builder
{
    return Environment::query()->whereIn(
        'project_id',
        Project::query()->where('organization_id', $organizationId)->select('id'),
    );
}

/**
 * Re-read a membership FROM THE PLATFORM ROOT.
 *
 * `memberships` is environment-owned, and a console test stands on whatever host it is
 * driving — so `Memberships::of()` asked directly answers null for a membership that is
 * perfectly alive. That matters most where the assertion is `toBeNull()`: a removal test
 * written that way passes whether the row was removed or merely out of scope, which is the
 * account plane's habit showing through — `account_members` sat outside tenancy, so no test
 * that read it ever had to think about this.
 */
function freshMembership(Membership $membership): ?Membership
{
    return app(PlatformRoot::class)->run(
        fn (): ?Membership => app(Memberships::class)->of(
            $membership->organization_id,
            $membership->user_id,
        ),
    );
}

/**
 * Add a member to an organization — a SUBJECT who holds a MEMBERSHIP.
 *
 * The account plane made this one call: `invite()` wrote a member row in an `invited` state
 * carrying the person and the role together, and `activate()` turned it real. Those are two
 * records now, and most tests want the end state rather than the ceremony — a test that
 * needs a viewer should not be exercising the invitation flow to get one.
 *
 * {@see Invitations} is still what the invitation tests
 * drive; this is for the ones that just need somebody on the roster.
 *
 * Returns both halves because the caller almost always needs both: the membership to assert
 * authority against, and the subject id to sign in as.
 *
 * @return array{0: Membership, 1: string} the membership, and the subject id
 */
function addMember(string $organizationId, MembershipRole $role, string $email, ?string $name = 'Member'): array
{
    $root = app(PlatformRoot::class);

    $subject = $root->run(
        fn () => app(Subjects::class)->create($email, $name, 'a-strong-unbreached-passphrase'),
    );

    expect($subject)->not->toBeNull('fixture: no platform root — call platformRootEnvironment() first');

    $membership = $root->run(
        fn () => app(Memberships::class)->add($organizationId, $subject->id, $role),
    );

    return [$membership, $subject->id];
}

/**
 * Sign a member in as the ADMINISTRATOR of an environment.
 *
 * Through the real {@see EnvironmentAdminAuth::establish()}, because an admin session is
 * the person's ordinary PLATFORM-ROOT SUBJECT session plus an anchor naming the environment
 * — not a raw key a test could fabricate. A test that wrote the keys by hand would be
 * asserting against a session shape no door produces.
 *
 * TAKES THE SUBJECT ID. It used to take a member row and read `$member->user_id` off it;
 * a membership carries authority and not identity, so the subject is what the caller has
 * and what this needs. `ProvisionedTenant::$owner` is that subject.
 */
function actAsEnvironmentAdmin(string $subjectId, string $environmentId, bool $emailVerified = true): void
{
    /*
     * VERIFIED BY DEFAULT, for the same reason {@see actingAsRole()} states: an established
     * administrator of an established environment HAS confirmed their address. The
     * alternative — every fixture unverified — means the moment a rule is written about
     * unverified accounts, dozens of unrelated tests start exercising it by accident, and
     * the fixture rather than the rule gets blamed. Pass false to test the rule on purpose.
     *
     * Marked in the platform root because the acting member is an ACCOUNT subject, and the
     * environment context this helper runs under is the tenant's — which is not where that
     * subject lives.
     */
    if ($emailVerified) {
        app(PlatformRoot::class)->run(function () use ($subjectId): void {
            $subject = app(Subjects::class)->find($subjectId);

            if ($subject !== null) {
                app(Subjects::class)->markEmailVerified($subject->id, (string) $subject->email);
            }
        });
    }

    app(EnvironmentAdminAuth::class)->establish($subjectId, $environmentId);
}

/**
 * Sign the browser in AS A MEMBER.
 *
 * There is no member session — a member is a subject that holds a membership, so this
 * establishes the ONE session and the membership is looked up from it. Tests used to write
 * a member session key directly, which is exactly why the shape was easy to get wrong: half
 * of them had to remember a security stamp beside it, and the half that forgot were
 * asserting against a session the plane would have refused.
 */
function signInAsMember(string $subjectId): void
{

    signInAsSubject($subjectId);
}

/*
|--------------------------------------------------------------------------
| Shared fixtures
|--------------------------------------------------------------------------
| A helper used by more than one test FILE lives here, not in whichever file
| happened to need it first.
|
| Under `pest --parallel`, paratest hands each worker a subset of the files: a
| function declared in tests/Feature/A.php simply does not exist in the process
| running tests/Feature/B.php, and B dies with "Call to undefined function". Which
| files share a worker depends on the shard split, so the failure is intermittent
| and looks like a flaky test rather than a missing declaration — 48 tests were
| failing this way, all of them green when the suite ran serially.
|
| Pest.php is loaded by every worker, so anything here is always available.
*/

/**
 * Register an app the way the console's form does: every field, not only the changed one.
 *
 * The create page is a form over a WHOLE registration — the kind decides the client type,
 * the grants and whether a redirect URI is even asked for — so a submission carrying one
 * field is a submission this console cannot produce.
 *
 * `redirectUris` is EMPTY by default rather than plausible. A CLI has no callback URL, and
 * a helper that quietly supplied one would store it on every app a test registers, so the
 * assertion "this kind has no redirect URIs" would be about the helper.
 *
 * @param  array<string, mixed>  $changes
 */
function registerApp(array $changes = [], string $plane = 'clients'): TestResponse
{
    return test()->from(route($plane.'.create'))->post(route($plane.'.store'), [
        'name' => 'Test App',
        'kind' => 'web',
        'type' => 'confidential',
        'grantAuthorizationCode' => true,
        'grantClientCredentials' => false,
        'scopes' => ['openid', 'profile', 'email', 'offline_access'],
        'customScopes' => '',
        'redirectUris' => '',
        'postLogoutRedirectUris' => '',
        'manifestUrl' => '',
        'firstParty' => false,
        'environmentWide' => false,
        ...$changes,
    ]);
}

/**
 * Sign in the way the form does: one POST to the shipped controller.
 *
 * `from(route('login'))` is not decoration. Every refusal on this path is a `back()` with
 * the message on the `email` field, so without a referer the redirect lands on `/` and
 * `assertRedirect(route('login'))` becomes a test about the default. It is stated here
 * once rather than at ninety call sites.
 *
 * @param  array<string, mixed>  $credentials
 */
function attemptLogin(array $credentials = []): TestResponse
{
    return test()->from(route('login'))->post(route('login.attempt'), [
        'email' => 'dana@acme.test',
        'password' => 'supersecret123',
        ...$credentials,
    ]);
}

/**
 * Register the way the form does.
 *
 * @param  array<string, mixed>  $fields
 */
function attemptSignup(array $fields = []): TestResponse
{
    return test()->from(route('signup'))->post(route('signup.register'), [
        'organization' => 'Acme',
        'name' => 'New Person',
        'email' => 'new@acme.test',
        'password' => 'a-strong-unbreached-passphrase',
        /*
         * The two bot signals the form carries, at their HUMAN values: an empty honeypot,
         * and a form that was on screen long enough to be filled in by hand. Omitting them
         * would make every test here a test of the missing-signal path rather than of what
         * it is about — and `renderedAt` absent scores as "implausibly fast".
         */
        'website' => '',
        'renderedAt' => now()->getTimestamp() - 30,
        ...$fields,
    ]);
}

/**
 * Add an existing user to an organization the way the form does.
 *
 * @param  array<string, mixed>  $changes
 */
/**
 * Set an end-user's password from the environment console.
 *
 * Every consequence is an explicit field — the form has no hidden defaults, so neither
 * does this: a test that only says "set a password" would be silently choosing temporary,
 * reveal, and sign-out-everywhere on the caller's behalf.
 */
function setUserPassword(string $userId, array $changes = []): TestResponse
{
    return test()->from(route('environment.users.show', $userId))
        ->post(route('environment.users.password', $userId), [
            'password' => 'a-strong-unbreached-passphrase',
            'reason' => 'Locked out after losing their phone',
            'mode' => 'temporary',
            'delivery' => 'reveal',
            'revoke' => 'sessions_and_tokens',
            'expiryHours' => 24,
            ...$changes,
        ]);
}

/** Invite somebody to the acting organization's own roster. */
function inviteToDirectory(array $changes = []): TestResponse
{
    return test()->from(route('directory.members'))
        ->post(route('directory.members.invite'), [
            'email' => 'newbie@acme.test',
            'role' => 'member',
            'accessRoles' => [],
            ...$changes,
        ]);
}

/**
 * Grant or revoke one access role for a member of the acting organization.
 *
 * An explicit set rather than a toggle, so a retried request and the checkbox cannot
 * disagree about which state was asked for.
 */
function setDirectoryAccessRole(string $userId, string $roleId, bool $granted): TestResponse
{
    return test()->from(route('directory.members'))
        ->post(route('directory.members.access', $userId), [
            'role' => $roleId,
            'granted' => $granted,
        ]);
}

/**
 * Enable a social sign-in provider through the console form.
 *
 * Every field the form states, because the form states them all — a partial payload would
 * let a test pass against a controller that silently kept a value it should have replaced.
 */
function enableSocialProvider(array $changes = []): TestResponse
{
    $provider = $changes['provider'] ?? 'github';

    return test()->from(route('social-providers', ['provider' => $provider]))
        ->post(route('social-providers.store'), [
            'provider' => $provider,
            'clientId' => 'gh-client',
            'clientSecret' => 'gh-secret',
            'parameters' => [],
            ...$changes,
        ]);
}

/**
 * Save branding at whichever altitude the console scope resolves.
 *
 * Every field the form states, because the form states them all — the palette included, so
 * a test that only sets a name cannot pass against a controller that dropped the colours.
 */
function saveBranding(array $changes = [], bool $environmentPlane = false): TestResponse
{
    $name = $environmentPlane ? 'environment.whitelabel.branding' : 'whitelabel.branding';

    return test()->from(route($name))
        ->post(route($name.'.save'), [
            'palette' => [],
            'appName' => 'Acme Identity',
            'emailFromName' => '',
            'emailTemplate' => '',
            ...$changes,
        ]);
}

/** Suspend or reactivate a platform operator from the roster. */
function toggleOperator(string $operatorId): TestResponse
{
    return test()->from(route('platform.operators'))
        ->post(route('platform.operators.toggle', $operatorId));
}

/** Create a new operator from the roster page. */
function createOperator(array $changes = []): TestResponse
{
    return test()->from(route('platform.operators'))
        ->post(route('platform.operators.store'), [
            'name' => 'Grace Hopper',
            'email' => 'grace@platform.test',
            'password' => 'a-strong-unbreached-passphrase',
            ...$changes,
        ]);
}

/**
 * A value the server put on the INERTIA FLASH CHANNEL, which is where every credential
 * shown once travels.
 *
 * Not a prop, and that is the whole point: props are serialised into the browser's history
 * entry, so a secret there is readable by pressing Back long after the page that showed it
 * has gone. The flash is written into the session and spent by the next render.
 */
function flashed(string $key): mixed
{
    /** @var array<string, mixed> $flash */
    $flash = session()->get(SessionKey::FLASH_DATA, []);

    return $flash[$key] ?? null;
}

/**
 * The device-approval screen, as props.
 *
 * @return array{client: array<string, mixed>|null, me: array<string, mixed>}
 */
function deviceScreen(array $query = []): array
{
    /** @var array{client: array<string, mixed>|null, me: array<string, mixed>} $props */
    $props = (array) test()->get(route('device', $query))->assertOk()->inertiaProps();

    return $props;
}

/** Resolve a TYPED device code to the app behind it. */
function lookUpDeviceCode(string $code): TestResponse
{
    return inertiaRequest(fn (): TestResponse => test()->from(route('device'))
        ->post(route('device.lookup'), ['userCode' => $code]));
}

/** Approve the device request this session has consented to. */
function approveDevice(): TestResponse
{
    return inertiaRequest(fn (): TestResponse => test()->from(route('device'))->post(route('device.approve')));
}

/** Deny it, so the device stops polling. */
function denyDevice(): TestResponse
{
    return inertiaRequest(fn (): TestResponse => test()->from(route('device'))->post(route('device.deny')));
}

/**
 * A person's own trusted devices, as props.
 *
 * @return array{enrolment: array<string, mixed>|null, appStoreUrl: string|null, devices: list<array<string, mixed>>}
 */
function myDevices(): array
{
    /** @var array{enrolment: array<string, mixed>|null, appStoreUrl: string|null, devices: list<array<string, mixed>>} $props */
    $props = (array) test()->get(route('devices.mine'))->assertOk()->inertiaProps();

    return $props;
}

/** Remove one of the reader's own handsets. */
function removeOwnDevice(string $deviceId): TestResponse
{
    return inertiaRequest(fn (): TestResponse => test()->from(route('devices.mine'))
        ->delete(route('devices.mine.destroy', $deviceId)));
}

/**
 * An authorization request, exactly as a relying party sends one.
 *
 * A real request, not a mounted component: `/authorize` is a PROTOCOL surface, and half of
 * what it has to get right — the redirect it answers with, the error it returns to the
 * client, the middleware that resolves the acting subject — only exists on a request.
 */
function authorizeRequest(array $params = []): TestResponse
{
    return test()->get(route('oauth.authorize', [
        'response_type' => 'code',
        'scope' => 'openid email',
        'state' => 'xyz',
        'code_challenge' => pkceChallenge(),
        'code_challenge_method' => 'S256',
        ...$params,
    ]));
}

/**
 * The consent screen's props, for a request that reached it.
 *
 * @return array<string, mixed>
 */
function consentScreen(array $params = []): array
{
    return (array) authorizeRequest($params)->assertOk()->inertiaProps();
}

/**
 * Answer a consent screen the way its buttons do.
 *
 * THE URL COMES FROM THE PAGE, and that is the point: the validated request lives in the
 * session under an opaque id, and the id is the only part of it the browser is given. A
 * test that built this URL itself would be inventing the one thing an attacker cannot.
 */
function answerConsent(array $props, string $answer = 'approve'): TestResponse
{
    $href = $props[$answer.'Href'] ?? null;

    expect($href)->toBeString('the consent screen offered no '.$answer.' control');

    return inertiaRequest(fn (): TestResponse => test()->from(route('oauth.authorize'))->post($href));
}

/**
 * Where a response sends the browser, whichever way it says so.
 *
 * An ordinary request leaving for the relying party gets a 302 with a `Location`. An
 * INERTIA visit gets a 409 with `X-Inertia-Location` — the protocol's own "leave the app"
 * answer, because the client library cannot follow a cross-origin 302 with `fetch`. Both
 * are the same event, and a test that only understood one of them would pass on the
 * consent-skip path while the Approve button was broken, or the reverse.
 */
function leftFor(TestResponse $response): ?string
{
    return $response->headers->get('X-Inertia-Location')
        ?? $response->headers->get('Location');
}

/**
 * The refusal a consent screen is carrying, whichever way it was asked for.
 *
 * A plain request gets the rendered document, and `inertiaProps()` reads the props out of
 * it. An INERTIA visit gets the page object as JSON, which that helper cannot read — it
 * looks for a view. Both are the same page; this asks it the same question either way.
 */
function consentRefusal(TestResponse $response): ?string
{
    $json = $response->headers->get('content-type');

    if (is_string($json) && str_contains($json, 'json')) {
        $error = $response->json('props.error');

        return is_string($error) ? $error : null;
    }

    $error = $response->inertiaProps('error');

    return is_string($error) ? $error : null;
}

/** Add a domain from the Admin Portal's setup screen. */
function addPortalDomain(string $domain): TestResponse
{
    return inertiaRequest(fn (): TestResponse => test()->from(route('portal.setup'))
        ->post(route('portal.domains.store'), ['domain' => $domain]));
}

/** Create an SSO connection from the Admin Portal. Defaults to a complete SAML one. */
function createPortalConnection(array $changes = []): TestResponse
{
    return inertiaRequest(fn (): TestResponse => test()->from(route('portal.setup'))
        ->post(route('portal.connections.store'), [
            'type' => 'saml',
            'connName' => 'Bound Co',
            'idp_entity_id' => 'https://idp.corp/metadata',
            'idp_sso_url' => 'https://idp.corp/sso',
            'idp_x509cert' => '-----BEGIN CERTIFICATE-----MIIB-----END CERTIFICATE-----',
            'sp_entity_id' => 'https://sp.acme/metadata',
            'sp_acs_url' => 'https://sp.acme/acs',
            ...$changes,
        ]));
}

/** Register a SCIM directory — the one credential the portal mints. */
function registerPortalDirectory(string $name = 'Acme Okta SCIM'): TestResponse
{
    return inertiaRequest(fn (): TestResponse => test()->from(route('portal.setup'))
        ->post(route('portal.directories.store'), ['dirName' => $name]));
}

/** Close the setup link. */
function finishPortalSetup(): TestResponse
{
    return inertiaRequest(fn (): TestResponse => test()->from(route('portal.setup'))
        ->post(route('portal.finish')));
}

/** Claim an unclaimed deployment, the way the first-run form does. */
function claimDeployment(array $changes = []): TestResponse
{
    return inertiaRequest(fn (): TestResponse => test()->from(route('first-run'))
        ->post(route('first-run.claim'), [
            'token' => '',
            'name' => 'Root Operator',
            'email' => 'root@acme.example',
            'password' => 'a-strong-unbreached-passphrase',
            'environmentName' => 'Production',
            'organizationName' => '',
            ...$changes,
        ]));
}

/**
 * A person's own security page, as props.
 *
 * @return array<string, mixed>
 */
function accountSecurity(): array
{
    return (array) test()->get(route('account'))->assertOk()->inertiaProps();
}

/**
 * Make ONE request shaped like the one the console actually makes.
 *
 * `X-Inertia` is not decoration: middleware branches on it. `RequireSudo` answers an
 * Inertia visit with a REDIRECT to the step-up — which the client follows and renders —
 * and answers a bare fetch with a 403 JSON body, because the passkey ceremony endpoints
 * cannot follow a redirect. A test that posts without the header exercises the branch no
 * page in this product takes.
 *
 * SCOPED TO THE ONE CALL, which is why this takes a closure rather than returning a
 * pre-headed client. `withHeaders()` merges into the test instance's DEFAULT headers, so a
 * flag set for a POST outlives it — and the next plain GET is then answered as an Inertia
 * visit, whose body is a JSON page object rather than the document the caller expected.
 *
 * @param  Closure(): TestResponse  $call
 */
function inertiaRequest(Closure $call): TestResponse
{
    test()->withHeader('X-Inertia', 'true');

    try {
        return $call();
    } finally {
        test()->withoutHeader('X-Inertia');
    }
}

/** Rename yourself from the security page. The one write there that needs no step-up. */
function saveOwnProfile(array $changes = []): TestResponse
{
    return inertiaRequest(fn (): TestResponse => test()->from(route('account'))
        ->patch(route('account.profile.update'), ['displayName' => 'Ada Lovelace', ...$changes]));
}

/** Change your own password. */
function changeOwnPassword(array $changes = []): TestResponse
{
    return inertiaRequest(fn (): TestResponse => test()->from(route('account'))
        ->post(route('account.password.update'), [
            'currentPassword' => 'a-strong-unbreached-passphrase',
            'newPassword' => 'an-even-stronger-unbreached-passphrase',
            'newPasswordConfirmation' => 'an-even-stronger-unbreached-passphrase',
            ...$changes,
        ]));
}

/** Begin TOTP enrolment — the secret and its QR arrive on the flash channel. */
function beginMfaEnrolment(): TestResponse
{
    return inertiaRequest(fn (): TestResponse => test()->from(route('account'))->post(route('account.mfa.enrol')));
}

/** Confirm the six digits an authenticator shows. */
function confirmMfaEnrolment(string $code): TestResponse
{
    return inertiaRequest(fn (): TestResponse => test()->from(route('account'))->post(route('account.mfa.confirm'), ['code' => $code]));
}

/** Mint a new set of recovery codes. */
function regenerateRecoveryCodes(): TestResponse
{
    return inertiaRequest(fn (): TestResponse => test()->from(route('account'))->post(route('account.mfa.recovery-codes')));
}

/** Remove one of the reader's own passkeys. */
function removeOwnPasskey(string $passkeyId): TestResponse
{
    return inertiaRequest(fn (): TestResponse => test()->from(route('account'))->delete(route('account.passkeys.destroy', $passkeyId)));
}

/** Disconnect a linked social account. */
function unlinkSocialProvider(string $provider): TestResponse
{
    return inertiaRequest(fn (): TestResponse => test()->from(route('account'))->delete(route('account.social.destroy', $provider)));
}

/** Sign out every session but this one, from the security page. */
function signOutOtherSessions(): TestResponse
{
    return inertiaRequest(fn (): TestResponse => test()->from(route('account'))->post(route('account.sessions.revoke-others')));
}

/**
 * A person's own sessions, connected applications and recent activity, as props.
 *
 * @return array{sessions: list<array<string, mixed>>, applications: list<array<string, mixed>>, activity: list<array<string, mixed>>}
 */
function accountActivity(): array
{
    /** @var array{sessions: list<array<string, mixed>>, applications: list<array<string, mixed>>, activity: list<array<string, mixed>>} $props */
    $props = (array) test()->get(route('account.activity'))->assertOk()->inertiaProps();

    return $props;
}

/** Sign one of the reader's own sessions out. */
function revokeOwnSession(string $sessionId): TestResponse
{
    return inertiaRequest(fn (): TestResponse => test()->from(route('account.activity'))
        ->post(route('account.sessions.revoke', $sessionId)));
}

/** Sign out every session except the one making the request. */
function revokeOtherOwnSessions(): TestResponse
{
    return inertiaRequest(fn (): TestResponse => test()->from(route('account.activity'))
        ->post(route('account.sessions.revoke-others')));
}

/** Withdraw one application's access to the reader's account. */
function withdrawApplication(string $clientId): TestResponse
{
    return inertiaRequest(fn (): TestResponse => test()->from(route('account.activity'))
        ->delete(route('account.applications.destroy', $clientId)));
}

/**
 * The platform's tenant tree for the targeted plane, as props.
 *
 * @return array{organizations: list<array<string, mixed>>, all: list<array<string, mixed>>, search: string}
 */
function platformOrganizations(array $query = []): array
{
    /** @var array{organizations: list<array<string, mixed>>, all: list<array<string, mixed>>, search: string} $props */
    $props = (array) test()->get(route('platform.organizations', $query))->assertOk()->inertiaProps();

    return $props;
}

/** One tenant's own detail page, as props. */
function platformOrganization(string $organizationId): array
{
    return (array) test()->get(route('platform.organization', $organizationId))->assertOk()->inertiaProps();
}

/** Create a tenant in the targeted plane, the way the list's form does. */
function createTenantOrganization(array $changes = []): TestResponse
{
    return test()->from(route('platform.organizations'))
        ->post(route('platform.organizations.store'), [
            'name' => 'Acme Inc',
            'type' => 'customer',
            'parentId' => '',
            ...$changes,
        ]);
}

/** Suspend or reactivate a tenant, from the list or from its own page. */
function toggleTenantOrganization(string $organizationId): TestResponse
{
    return test()->from(route('platform.organization', $organizationId))
        ->post(route('platform.organizations.toggle', $organizationId));
}

/** Move a tenant under another, or to the top level with a blank parent. */
function reparentOrganization(string $organizationId, ?string $parentId): TestResponse
{
    return test()->from(route('platform.organizations'))
        ->post(route('platform.organizations.reparent', $organizationId), [
            'parentId' => $parentId ?? '',
        ]);
}

/**
 * The platform's flat environment list, as props.
 *
 * @return array{environments: list<array<string, mixed>>, activeId: string|null, search: string, pagination: array<string, mixed>}
 */
function platformEnvironments(array $query = []): array
{
    /** @var array{environments: list<array<string, mixed>>, activeId: string|null, search: string, pagination: array<string, mixed>} $props */
    $props = (array) test()->get(route('platform.environments', $query))->assertOk()->inertiaProps();

    return $props;
}

/** Create an isolation plane from the platform environment list. */
function createPlatformEnvironment(array $changes = []): TestResponse
{
    return test()->from(route('platform.environments'))
        ->post(route('platform.environments.store'), [
            'name' => 'Staging',
            'domain' => '',
            ...$changes,
        ]);
}

/** Point the operator console at another plane, the way the list's Target control does. */
function targetPlatformEnvironment(string $environmentId): TestResponse
{
    return test()->from(route('platform.environments'))
        ->post(route('platform.environment.switch'), ['environment' => $environmentId]);
}

/**
 * The platform's customer list, as props.
 *
 * @return array{customers: list<array<string, mixed>>, pagination: array<string, mixed>, search: string}
 */
function platformCustomers(array $query = []): array
{
    /** @var array{customers: list<array<string, mixed>>, pagination: array<string, mixed>, search: string} $props */
    $props = (array) test()->get(route('platform.customers', $query))->assertOk()->inertiaProps();

    return $props;
}

/** One customer's own page, as props. */
function platformCustomer(string $organizationId): array
{
    return (array) test()->get(route('platform.customers.show', $organizationId))->assertOk()->inertiaProps();
}

/** Onboard a customer the way the list's form does. */
function createCustomer(array $changes = []): TestResponse
{
    return test()->from(route('platform.customers'))
        ->post(route('platform.customers.store'), [
            'name' => 'Northwind',
            'ownerName' => 'Ada Lovelace',
            'ownerEmail' => 'owner@northwind.example',
            'environmentLimit' => 2,
            ...$changes,
        ]);
}

/** Suspend or reactivate a customer, from either the list or its own page. */
function toggleCustomer(string $organizationId): TestResponse
{
    return test()->from(route('platform.customers.show', $organizationId))
        ->post(route('platform.customers.toggle', $organizationId));
}

/** Point the console at one of a customer's own environments, and stay on the customer. */
function targetCustomerEnvironment(string $organizationId, string $environmentId): TestResponse
{
    return test()->from(route('platform.customers.show', $organizationId))
        ->post(route('platform.customers.target', [$organizationId, $environmentId]));
}

/** Target one of a customer's environments AND open the tenants inside it. */
function openCustomerEnvironment(string $organizationId, string $environmentId): TestResponse
{
    return test()->from(route('platform.customers.show', $organizationId))
        ->post(route('platform.customers.open', [$organizationId, $environmentId]));
}

/** Bootstrap a plane with its first organization and an owner admin. */
function provisionEnvironmentAdmin(string $environmentId, array $changes = []): TestResponse
{
    return test()->from(route('platform.environments'))
        ->post(route('platform.environments.provision', $environmentId), [
            'orgName' => 'Acme Inc',
            'adminName' => 'Ada Lovelace',
            'adminEmail' => 'admin@acme.test',
            'adminPassword' => 'a-strong-admin-pass',
            ...$changes,
        ]);
}

/**
 * The platform's cross-plane search, as props.
 *
 * @return array{term: string, ready: bool, organizations: list<array<string, mixed>>, users: list<array<string, mixed>>}
 */
function platformSearch(string $term = ''): array
{
    $query = $term === '' ? [] : ['term' => $term];

    return (array) test()->get(route('platform.search', $query))->assertOk()->inertiaProps();
}

/** A downstream SAML service provider in the current environment. */
function registerServiceProvider(array $changes = []): ServiceProvider
{
    return app(ServiceProviders::class)->register(new NewServiceProvider(
        entityId: $changes['entityId'] ?? 'https://sp/meta',
        acsUrl: $changes['acsUrl'] ?? 'https://sp/acs',
        nameIdFormat: NameIdFormat::cases()[0],
        nameIdAttribute: $changes['nameIdAttribute'] ?? 'email',
        attributeMappings: $changes['attributeMappings'] ?? [],
        certificate: $changes['certificate'] ?? null,
        wantAuthnRequestsSigned: $changes['wantAuthnRequestsSigned'] ?? false,
    ));
}

/**
 * Save a SAML application's whole configuration.
 *
 * Every field, because the form states every field — a partial payload would let a test
 * pass against a controller that silently kept the value it was supposed to replace.
 */
function saveServiceProvider(string $providerId, array $changes = []): TestResponse
{
    return test()->from(route('environment.sso-providers.show', $providerId))
        ->patch(route('environment.sso-providers.update', $providerId), [
            'entityId' => 'https://sp/meta',
            'acsUrl' => 'https://sp/acs',
            'nameIdFormat' => NameIdFormat::cases()[0]->value,
            'nameIdAttribute' => 'email',
            'attributeMappings' => [],
            'wantAuthnRequestsSigned' => false,
            'certificate' => '',
            ...$changes,
        ]);
}

/**
 * Grant or clear a role EVERYWHERE in the environment, from a user's page.
 *
 * An explicit set rather than a toggle, so a retried request and the checkbox cannot
 * disagree about which state was asked for.
 */
function setEnvironmentRole(string $userId, string $roleId, bool $granted): TestResponse
{
    return test()->from(route('environment.users.show', $userId))
        ->post(route('environment.users.roles', $userId), [
            'role' => $roleId,
            'granted' => $granted,
        ]);
}

/** Add an end-user to an organization from that USER's page (the mirror of the org one). */
function assignUserToOrganization(string $userId, string $organizationId, array $changes = []): TestResponse
{
    return test()->from(route('environment.users.show', $userId))
        ->post(route('environment.users.organizations.store', $userId), [
            'organization' => $organizationId,
            'role' => 'member',
            'accessRoles' => [],
            ...$changes,
        ]);
}

function addOrganizationMember(string $organizationId, array $changes = []): TestResponse
{
    return test()->from(route('environment.organizations.show', $organizationId))
        ->post(route('environment.organizations.members.store', $organizationId), [
            'email' => 'dave@acme.example',
            'role' => 'member',
            'accessRoles' => [],
            ...$changes,
        ]);
}

/**
 * Invite somebody into an organization the way the form does.
 *
 * @param  array<string, mixed>  $changes
 */
function inviteOrganizationMember(string $organizationId, array $changes = []): TestResponse
{
    return test()->from(route('environment.organizations.show', $organizationId))
        ->post(route('environment.organizations.invitations.store', $organizationId), [
            'email' => 'newbie@acme.example',
            'role' => 'member',
            'accessRoles' => [],
            ...$changes,
        ]);
}

/** Save an organization's details the way the form does. */
function saveOrganization(string $organizationId, string $name, string $slug): TestResponse
{
    return test()->from(route('environment.organizations.show', $organizationId))
        ->patch(route('environment.organizations.update', $organizationId), [
            'name' => $name,
            'slug' => $slug,
            'metadata' => [],
        ]);
}

/** Approve the declared legacy-login endpoint, the way the confirm dialog does. */
function approveLegacyLogin(): TestResponse
{
    return test()->from(route('environment.legacy-login'))
        ->post(route('environment.legacy-login.approve'));
}

/** Probe the declared legacy-login endpoint with one address. */
function probeLegacyLogin(string $email): TestResponse
{
    return test()->from(route('environment.legacy-login'))
        ->post(route('environment.legacy-login.probe'), ['email' => $email]);
}

/**
 * Issue a publishable key the way the form does.
 *
 * @param  array<string, mixed>  $changes
 */
function issueFrontendKey(array $changes = []): TestResponse
{
    return test()->from(route('environment.frontend-keys'))
        ->post(route('environment.frontend-keys.store'), [
            'name' => 'Marketing site',
            'mode' => 'test',
            'origins' => 'https://acme.test',
            ...$changes,
        ]);
}

/**
 * An environment the signed-in member may actually mint a key against.
 *
 * ASKED OF THE PAGE rather than guessed from the table. Reachability is
 * `accessibleEnvironmentIds()` resolved in the platform root, and a test that picked the
 * first row of `environments` would be asserting about the fixture's ordering — and would
 * pass or fail for reasons that have nothing to do with the gate under test.
 */
function reachableEnvironmentId(): string
{
    $environments = test()->get(route('environment-keys'))->assertOk()->inertiaProps('environments');

    expect($environments)->toBeArray()->not->toBeEmpty(
        'the signed-in member reaches no environment, so this test cannot be about the step-up',
    );

    return (string) $environments[0]['id'];
}

/**
 * Issue an environment management-plane key the way the form does.
 *
 * @param  array<string, mixed>  $changes
 */
function issueEnvironmentKey(string $environmentId, array $changes = []): TestResponse
{
    return test()->from(route('environment-keys'))->post(route('environment-keys.store'), [
        'environment' => $environmentId,
        'name' => 'Provisioner',
        'scopes' => ['users:read'],
        ...$changes,
    ]);
}

/**
 * Create a SIEM export stream the way the form does.
 *
 * `secret` is EMPTY by default, which on the HMAC scheme is what asks for a generated
 * signing key — the path the form advertises and the one worth exercising.
 *
 * @param  array<string, mixed>  $changes
 */
function createLogStream(array $changes = [], string $plane = 'audit-streams'): TestResponse
{
    return test()->from(route($plane.'.create'))->post(route($plane.'.store'), [
        'name' => 'Acme Splunk',
        'destination' => 'generic_json',
        'endpointUrl' => 'https://siem.acme.example/collector',
        'scheme' => 'none',
        'secret' => '',
        ...$changes,
    ]);
}

/**
 * Register an outbound SCIM connection the way the form does.
 *
 * `tokenUrl`/`clientId`/`scope` are EMPTY by default rather than plausible: they belong to
 * one auth scheme, and a helper that supplied them would make "a bearer target writes no
 * auth config" an assertion about the helper.
 *
 * @param  array<string, mixed>  $changes
 */
function registerOutboundSync(array $changes = [], string $plane = 'provisioning'): TestResponse
{
    return test()->from(route($plane.'.create'))->post(route($plane.'.store'), [
        'name' => 'Downstream',
        'baseUrl' => 'https://scim.example.test/v2',
        'scheme' => 'bearer',
        'secret' => 'tok_123',
        'environmentWide' => false,
        'tokenUrl' => '',
        'clientId' => '',
        'scope' => '',
        ...$changes,
    ]);
}

/**
 * Seal a downstream credential the way the form does.
 *
 * @param  array<string, mixed>  $changes
 */
function storeVaultSecret(array $changes = [], string $plane = 'vault'): TestResponse
{
    return test()->from(route($plane.'.create'))->post(route($plane.'.store'), [
        'name' => 'openai',
        'provider' => 'openai',
        'secret' => 'sk-live-x',
        ...$changes,
    ]);
}

/**
 * Define a role-conflict rule the way the form does.
 *
 * `roles` has no default: a rule is ABOUT the roles it names, so a helper that supplied a
 * pair would make every test here a test of the helper's pair.
 *
 * @param  array<string, mixed>  $changes
 */
function defineRoleConflict(array $changes = [], string $plane = 'sod-policies'): TestResponse
{
    return test()->from(route($plane.'.create'))->post(route($plane.'.store'), [
        'name' => 'PO vs pay',
        'description' => '',
        'roles' => [],
        'environmentWide' => false,
        ...$changes,
    ]);
}

/**
 * Open an access review the way the form does.
 *
 * @param  array<string, mixed>  $changes
 */
function openAccessReview(array $changes = [], string $plane = 'governance'): TestResponse
{
    return test()->from(route($plane.'.create'))->post(route($plane.'.store'), [
        'name' => 'Q3 review',
        ...$changes,
    ]);
}

/** Certify or revoke one item on a review, the way the row's two buttons do. */
function decideAccessItem(string $campaignId, string $itemId, string $decision, string $plane = 'governance'): TestResponse
{
    return test()->from(route($plane.'.show', $campaignId))
        ->post(route($plane.'.item', ['campaign' => $campaignId, 'item' => $itemId]), [
            'decision' => $decision,
        ]);
}

/**
 * Register an inline hook the way the form does.
 *
 * @param  array<string, mixed>  $changes
 */
function registerHook(array $changes = [], string $plane = 'hooks'): TestResponse
{
    return test()->from(route($plane.'.create'))->post(route($plane.'.store'), [
        'point' => 'token_minting',
        'url' => 'https://hooks.example.test/token',
        'environmentWide' => false,
        ...$changes,
    ]);
}

/**
 * Author a manual permission the way the form does.
 *
 * `tenantAssignable` defaults TRUE because that is what the form ticks — the shared tier's
 * whole reason for the checkbox is that a key is offered to tenants unless somebody says
 * otherwise, and a helper defaulting it false would make every test about the exception.
 *
 * @param  array<string, mixed>  $changes
 */
function createPermission(array $changes = [], string $plane = 'permissions'): TestResponse
{
    return test()->from(route($plane))->post(route($plane.'.store'), [
        'name' => 'invoices:create',
        'description' => '',
        'tenantAssignable' => true,
        ...$changes,
    ]);
}

/**
 * Define a role the way the form does.
 *
 * `permissions` is EMPTY by default rather than plausible: composing a role from the
 * declared catalogue is a separate act with its own gate — `tenant_assignable` — and a
 * helper that quietly ticked a key would put it on every role a test creates, so
 * "this role holds nothing it was not given" would be an assertion about the helper.
 *
 * @param  array<string, mixed>  $changes
 */
function defineRole(array $changes = [], string $plane = 'roles'): TestResponse
{
    return test()->from(route($plane.'.create'))->post(route($plane.'.store'), [
        'name' => 'Manager',
        'description' => '',
        'app' => '',
        'environmentWide' => false,
        'permissions' => [],
        ...$changes,
    ]);
}

/**
 * Grant or revoke one permission on a role, the way both the detail page's checkbox and
 * the list's picker do.
 */
function setRolePermission(string $roleId, string $permissionId, bool $granted, string $plane = 'roles'): TestResponse
{
    return test()->from(route($plane.'.show', $roleId))->post(route($plane.'.permissions', $roleId), [
        'permission' => $permissionId,
        'granted' => $granted,
    ]);
}

/**
 * Create an SSO connection the way the form does: the whole config, not one field.
 *
 * Which fields are required follows from the PROTOCOL — a SAML connection needs an entity
 * id, a sign-on URL and a certificate; an OIDC one needs an issuer, a client id, a secret
 * and a key — so a helper that carried only what a test changed would be submitting a form
 * this console cannot produce.
 *
 * @param  array<string, mixed>  $changes
 */
function createConnection(array $changes = [], string $plane = 'connections'): TestResponse
{
    return test()->from(route($plane.'.create'))->post(route($plane.'.store'), [
        'name' => 'Corporate SAML',
        'type' => 'saml',
        'environmentWide' => false,
        'idp_entity_id' => 'https://idp.corp/metadata',
        'idp_sso_url' => 'https://idp.corp/sso',
        'idp_x509cert' => '-----BEGIN CERTIFICATE-----MIIB-----END CERTIFICATE-----',
        'sp_entity_id' => 'https://sp.acme/metadata',
        'sp_acs_url' => 'https://sp.acme/acs',
        ...$changes,
    ]);
}

/**
 * Register a SCIM directory the way the form does.
 *
 * @param  array<string, mixed>  $changes
 */
function registerDirectory(array $changes = [], string $plane = 'directories'): TestResponse
{
    return test()->from(route($plane.'.create'))->post(route($plane.'.store'), [
        'provider' => 'scim',
        'name' => 'HR directory',
        ...$changes,
    ]);
}

/**
 * Connect an API-pull directory — the other half of the same page.
 *
 * A SEPARATE ENDPOINT from the one above, because they are separate acts: registering
 * MINTS a token this platform hands over, connecting SEALS credentials it will then use.
 * One button chooses between them from the provider, and the tests follow the same seam.
 *
 * @param  array<string, mixed>  $changes
 */
function connectDirectory(array $changes = [], string $plane = 'directories'): TestResponse
{
    return test()->from(route($plane.'.create'))->post(route($plane.'.connect'), [
        'provider' => 'google_workspace',
        'googleServiceAccountJson' => '',
        'googleAdminEmail' => '',
        'entraTenantId' => '',
        'entraClientId' => '',
        'entraClientSecret' => '',
        ...$changes,
    ]);
}

/** A valid PAM justification for the impersonation start POST. */
const IMPERSONATION_REASON = 'Investigating support ticket #4271';

/**
 * Make the platform root the environment this test lives in — the shape a single-tenant
 * install has, and the one every operator test needs.
 *
 * An operator is a SUBJECT now, and subjects and `auth_sessions` are environment-owned:
 * the operator's is written inside the platform root ({@see PlatformRoot}), because that
 * is where the platform's own people live. An unmapped test host resolves to the root
 * too. So without stating this, an operator fixture writes its subject into one
 * environment while the test's organizations and members are created in another, and the
 * scope — deny-by-default — makes the mismatch look like "no such record" rather than
 * like a fixture that never lined up.
 */
function platformRootDeployment(): Environment
{
    $root = platformRootEnvironment();

    app(EnvironmentContext::class)->set($root);

    return $root;
}

/**
 * A platform operator, signed in the way the console now requires: as a subject.
 *
 * There is no operator session key to write any more. Authority is asked of the ONE
 * session — {@see ConsoleScope::operator()} resolves the operator
 * from the signed-in subject — so a test that wants an operator has to establish the
 * same session a sign-in would, which is what this does.
 */
function actAsOperator(string $email = 'op@platform.test'): PlatformOperator
{
    platformRootDeployment();

    $operator = app(PlatformOperators::class)->create($email, 'a-strong-operator-pass', 'Operator');

    signInAsSubject((string) $operator->subject_id);

    return $operator;
}

/**
 * Establish the browser session for a PLATFORM-ROOT subject, through the same
 * {@see PlatformAuth::establish()} every real sign-in goes through — so the held-account
 * set, the active pointer and the framework session row all have the shape production
 * has, and a test cannot accidentally prove something about a session shape that only
 * exists in tests.
 */
function signInAsSubject(string $subjectId): void
{
    app(PlatformRoot::class)->run(function () use ($subjectId): void {
        app(PlatformAuth::class)->establish(request(), $subjectId, ['pwd']);

        // …and populate CurrentUser as the Authenticate middleware would, so a test that
        // drives a console component DIRECTLY (Volt::test, no middleware) asks the same
        // question a real request does. ConsoleScope reads the acting subject from here.
        $sessionId = session(PlatformAuth::SESSION_KEY);
        $subject = app(Subjects::class)->find($subjectId);
        $session = is_string($sessionId) ? app(SessionManager::class)->active($sessionId) : null;

        if ($subject === null || $session === null) {
            return;
        }

        // …INCLUDING the organization, which this used to leave null. The middleware falls
        // back to the subject's first membership and remembers it, so a real request
        // always arrives with one resolved; a fixture that skipped it made every question
        // keyed on the ACTING organization answer "none" — and those questions are now
        // what decides whether a page exists at all
        // ({@see \App\Platform\Console\ConsoleScope::accountRole()}). A component
        // driven directly would mount, find no acting organization, and redirect, which
        // surfaces as a mangled Livewire snapshot several frames away from the cause.
        $membership = app(Memberships::class)->forUser($subjectId)->first();
        $organizationId = $membership?->organization_id;
        $organization = is_string($organizationId) ? app(Organizations::class)->find($organizationId) : null;

        if ($organization !== null) {
            session()->put(PlatformAuth::ORG_KEY, $organization->id);
        }

        app(CurrentUser::class)->set($subject, $session, $organization, $membership?->role);
    });
}

/**
 * Sign the browser out — every trace of the one session, held accounts included.
 *
 * `session()->forget(SESSION_KEY)` is not enough and never was: PlatformAuth keeps a set
 * of concurrently signed-in accounts, and leaving it behind leaves a way back in.
 */
function forgetSubjectSession(): void
{
    session()->forget([
        PlatformAuth::SESSION_KEY,
        PlatformAuth::ORG_KEY,
        PlatformAuth::ACCOUNTS_KEY,
        PlatformAuth::ACTIVE_KEY,
    ]);

    app()->forgetInstance(CurrentUser::class);
}

/**
 * End the current request, so the next `$this->get()` really is a NEW one.
 *
 * `ConsoleScope` is bound `scoped` and memoises the answer to "does this person run the
 * deployment?" — deliberately, because the rail and every platform-page guard ask it on
 * every render. A real deployment drops scoped instances between requests; Laravel's HTTP
 * test helpers do not, so two `$this->get()` calls in one test share the memo and the
 * SECOND one answers with the FIRST one's authority.
 *
 * That matters exactly where it is most dangerous to be wrong: a test asserting that
 * authority CHANGED between requests — a suspension landing, an impersonation starting —
 * passes trivially without this, because the second request never re-asked.
 *
 * FOLLOW IT WITH A REQUEST, not with a direct call. It ends the request wholesale, and
 * that includes the ambient EnvironmentContext — so an Eloquent read made after it and
 * before the next `$this->get()` runs with NO environment, which the tenancy scope answers
 * (correctly, and silently) with zero rows. A refusal asserted in that gap is the scope
 * talking, not the thing under test; that exact mistake made an impersonation-escape test
 * pass against an implementation with the defence deleted.
 */
function nextRequest(): void
{
    app()->forgetScopedInstances();
}

/** Point an operator's console at a plane, as the environment switcher does. */
function targetEnvironment(string $slug): void
{
    app(OperatorEnvironment::class)->pointAt($slug);
}

/**
 * State the MULTI-TENANT (SaaS) deployment shape for this test.
 *
 * The suite baseline is single-tenant — pinned in {@see TestCase::setUp()} because that is
 * the shape a fresh install has, and because inheriting tenancy from a developer's `.env`
 * decided 122 tests by luck. A test that needs the SaaS shape says so, here.
 *
 * TWO facts, and stating only the first is the trap:
 *
 *  1. `multi_tenant` — what {@see PlaneResolver::isMultiTenant()} reads before it derives
 *     anything. It turns the host bulkheads ON.
 *  2. `account_host` — where the account console lives. Without it a multi-tenant
 *     deployment is {@see PlaneResolver::misconfigured()}: the environment console's
 *     sign-in handoff has nowhere to hand off TO, so it falls back to a local credential
 *     form that no coherent deployment serves.
 *
 * And a third that is not config: `plane:subject` is every host EXCEPT the platform root,
 * so the request must reach a host that resolves to a NON-root environment. An unmapped
 * test host falls back to the root, lands on the account plane, and is refused by the
 * plane bulkhead — a 404 identical to the one the multi-tenant statement just removed.
 * {@see serveOnTestHost()} is how a test gets there on the default host; naming a tenant
 * host on the request (`https://tenant.cboxid.com/...`) is the other way.
 *
 * The account host defaults to a domain that is deliberately NOT the app URL's host, so
 * a cross-plane bounce is visible as a different origin rather than as a same-host path.
 */
function multiTenantDeployment(string $consoleHost = 'cboxid.com'): void
{
    config()->set('cbox-id.tenancy.multi_tenant', true);
    config()->set('cbox-id.tenancy.account_host', $consoleHost);
}

/**
 * Populate CurrentUser as the Authenticate middleware would, then drive the
 * component directly.
 *
 * @return array{0: string, 1: Organization}
 */
function actingAsRole(MembershipRole $role, bool $emailVerified = true): array
{
    $subject = app(Subjects::class)->create($role->value.'@acme.test', $role->label(), 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-'.$role->value));
    app(Memberships::class)->add($org->id, $subject->id, $role);

    // Verified by default, because that is what an established member of an established
    // organization IS. The alternative — every fixture unverified — meant the moment a
    // rule was written about unverified accounts, dozens of unrelated tests started
    // exercising it by accident, and the fixture rather than the rule would have been
    // blamed. Pass false to test the rule deliberately.
    if ($emailVerified) {
        app(Subjects::class)->markEmailVerified($subject->id, (string) $subject->email);
        $subject = app(Subjects::class)->find($subject->id) ?? $subject;
    }

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, $role);

    // AND THE SESSION KEY THE CONSOLE'S OWN GUARD READS, without which an HTTP request
    // made after this helper is anonymous: `CurrentUser` is resolved state for code inside
    // the process, and the guard on the way in reads the session. Tests that then asserted
    // `not 403` were asserting against a 302 to /login and passed whatever the page did —
    // including a 500, a 404, or the very cross-plane 403 they were written to catch.
    session([PlatformAuth::SESSION_KEY => $session->id]);

    return [$subject->id, $org];
}

/**
 * Sign an admin into a fresh org and return its id. The org starts with NO
 * entitlements — deny-by-default is the thing under test.
 */
function gateAdmin(string $slug = 'gate-acme', MembershipRole $role = MembershipRole::Owner): string
{
    $subject = app(Subjects::class)->create("admin@{$slug}.test", 'Admin', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', $slug));
    app(Memberships::class)->add($org->id, $subject->id, $role);

    // See actingAsRole(): an established admin has confirmed their address, and the
    // thing under test here is entitlements, not verification.
    app(Subjects::class)->markEmailVerified($subject->id, (string) $subject->email);
    $subject = app(Subjects::class)->find($subject->id) ?? $subject;

    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, $role);

    // AND THE SESSION KEY THE CONSOLE'S GUARD READS. `CurrentUser` is resolved state for
    // code already inside the process, which is all a Livewire component ever needed; a
    // ported page is reached by a REQUEST, and without this every one of them answers a
    // redirect to /login — which an assertion about a WRITE not happening passes.
    session([PlatformAuth::SESSION_KEY => $session->id]);

    return $org->id;
}

function grantFeature(string $organizationId, string $key): void
{
    app(EntitlementWriter::class)->set(
        $organizationId,
        new EntitlementInput($key, ['enabled' => true]),
        EntitlementSource::Manual,
    );
}

/**
 * A controllable Passkeys stand-in: the framework already tests the real WebAuthn
 * verifier against a software authenticator, so here we verify only the HTTP +
 * session bridging in the app's controller.
 */
function fakePasskeys(?string $authenticateAs): void
{
    app()->instance(Passkeys::class, new class($authenticateAs) implements Passkeys
    {
        public function __construct(private readonly ?string $authenticateAs) {}

        public function register(string $userId, string $challenge, string $clientResponseJson, ?string $name = null): WebAuthnCredential
        {
            return new WebAuthnCredential(['user_id' => $userId, 'credential_id' => 'cred_'.$userId, 'name' => $name]);
        }

        public function authenticate(string $credentialId, string $challenge, string $clientResponseJson): string
        {
            return $this->authenticateAs ?? throw new UnknownCredential('none');
        }

        public function credentialById(string $credentialId): ?WebAuthnCredential
        {
            return null;
        }
    });
}

/**
 * The console plane these two pages live on.
 *
 * Publishable keys and the legacy-login declaration are owned by the ENVIRONMENT and have
 * no organization column, so they are administered from the environment console alone —
 * on the organization plane, "may this administrator change this" resolves to a membership
 * role and answers yes for every organization in the environment. See
 * {@see ConsoleScope::assertMayAdministerEnvironment()}.
 */
function actAsEnvironmentAdminOfATenant(): string
{
    /*
     * MULTI-TENANT, said out loud. The environment console lives under `/admin`, which is
     * gated on a member administering one of their organization's environments — and a
     * single-tenant install has one environment that belongs to nobody, so the whole prefix
     * 404s on that shape ({@see \App\Http\Middleware\RequireMultiTenant}). It did not
     * matter while these pages were driven at the component; every one of them is a request
     * now, and without this each answers 404 rather than the page under test.
     */
    multiTenantDeployment();

    $tenant = provisionAccount();

    serveOnTestHost($tenant['environment']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($tenant['environment']->id));
    actAsEnvironmentAdmin($tenant['subjectId'], $tenant['environment']->id);

    return $tenant['environment']->id;
}

/**
 * Confirm a fresh step-up for the current console session.
 *
 * For the handful of actions gated behind `sudo` — the token vault, log-stream creation,
 * approving where sign-ins are delegated. Written through `Sudo` rather than by putting the
 * key in the session by hand, so a test cannot assert against a shape no door produces.
 */
function confirmStepUp(): void
{
    app(Sudo::class)->confirm();
}

/**
 * Confirm the ENVIRONMENT plane's step-up.
 *
 * A different session key from {@see confirmStepUp()}, deliberately: a confirmation made
 * as an organization member must never satisfy a gate on the environment console, where
 * the person is a platform-root subject administering somebody else's tenant.
 */
function confirmEnvironmentStepUp(): void
{
    app(EnvironmentSudo::class)->confirm();
}

/**
 * Leave the console session behind, keeping everything `actingAsRole()` set up.
 *
 * For the pages an ANONYMOUS visitor sees — a branded sign-in page, a public consent
 * screen. The fixture that creates the organization has to sign in to create it; the
 * request under test must not be signed in, or the page correctly redirects to the
 * dashboard and the assertion is about a 302.
 */
function signOutOfConsole(): void
{
    session()->forget(PlatformAuth::SESSION_KEY);
}

/**
 * A subject who owns a fresh organization.
 *
 * @return array{0: Subject, 1: Organization}
 */
function accountWithOrg(string $email): array
{
    $subject = app(Subjects::class)->create($email, 'Holder', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-'.substr(md5($email), 0, 6)));
    app(Memberships::class)->add($org->id, $subject->id, MembershipRole::Owner);

    return [$subject, $org];
}

/**
 * Provision an account + environment, pin the environment context and an
 * environment-admin session.
 *
 * @return array{member: Membership, envId: string}
 */
function crudSetup(): array
{
    // The environment-admin console is a multi-tenant surface — it is gated on a member
    // administering one of their ORGANIZATION's environments, and a single-tenant install
    // has one environment which is the platform root and belongs to nobody. So the whole
    // `/admin` prefix 404s on that shape ({@see \App\Http\Middleware\RequireMultiTenant}),
    // and every test that drives it is stating the SaaS shape whether it says so or not.
    multiTenantDeployment();

    platformRootEnvironment();
    $r = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($r->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($r->environment->id));
    actAsEnvironmentAdmin($r->owner->id, $r->environment->id);

    return ['member' => $r->membership, 'subjectId' => $r->owner->id, 'envId' => $r->environment->id];
}

/**
 * A subject with one enrolled handset, plus an agent client to raise CIBA requests.
 *
 * @return array{0: string, 1: Client}
 */
function cibaSubjectWithDevice(): array
{
    config()->set('id-devices.enabled', true);

    $subject = app(Subjects::class)->create('ciba@acme.test', 'CIBA User', 'supersecret123');

    $device = new Device;
    $device->fill([
        'subject_id' => $subject->id,
        'install_id' => (string) Str::ulid(),
        'platform' => DevicePlatform::Ios,
        'name' => 'CIBA iPhone',
        'status' => DeviceStatus::Active,
    ]);
    $device->save();
    $device->token_encrypted = app(SecretBox::class)->seal('fcm-token', $device->secretContext());
    $device->save();

    $client = app(ClientRegistry::class)->register(
        new NewClient('Agent', ClientType::Confidential, scopes: ['openid'])
    )->client;

    return [$subject->id, $client];
}

/** An operator, signed in, with the test living in the platform root. */
function impersonationOperator(string $email = 'imp-op@platform.test'): PlatformOperator
{
    return actAsOperator($email);
}

/**
 * A member account inside the default test plane. Defaults to a REGULAR member —
 * owners and admins are not impersonable (an operator inheriting their elevated
 * surface is exactly the risk we close), so the happy-path helper must be a member.
 *
 * @return array{0: Organization, 1: Subject}
 */
function impersonationMember(string $email = 'member@acme.test', MembershipRole $role = MembershipRole::Member): array
{
    $org = app(Organizations::class)->create(new NewOrganization('Acme Inc', 'acme-'.substr(md5($email), 0, 6)));
    $subject = app(Subjects::class)->create($email, 'Member One', 'supersecret123');
    app(Memberships::class)->add($org->id, $subject->id, $role);

    return [$org, $subject];
}

/**
 * Open the console step-up window for the plane the current session is on.
 *
 * The credential-minting console actions (rotating an app secret, a SCIM bearer token, a
 * webhook signing secret) sit behind {@see ConsoleStepUp}, so a test that drives one is
 * otherwise asserting the gate rather than the action. Everything up to and including
 * the gate has its own coverage in `ConsoleStepUpTest`; this is for the tests whose
 * subject is what happens AFTER it.
 *
 * Plane-aware rather than confirming both keys, deliberately. The two planes answer to
 * different authorities and keep separate session keys precisely so a confirmation on one
 * cannot satisfy the other — a helper that opened both would make a component reaching for
 * the wrong plane's window invisible to every test that used it.
 */
function confirmConsoleStepUp(): void
{
    app(ConsoleScope::class)->plane() === ConsolePlane::Environment
        ? app(EnvironmentSudo::class)->confirm()
        : app(Sudo::class)->confirm();
}

/**
 * The one password door, run the way a request runs it.
 *
 * `/login` is served by the platform-root host, so `SetEnvironment` has already selected
 * that environment by the time {@see PlatformAuth::attemptPassword()} looks a subject up.
 * A test that calls it with no environment selected gets the deny-by-default scope —
 * `WHERE 1 = 0`, no subject, and `AttemptOutcome::Invalid`.
 *
 * That is the SAME answer a wrong password gives, which is why this is a helper and not
 * four inline calls: a test asserting a refusal passes identically whether the rule it
 * names refused the credential or the scope never found anybody to refuse. Two of the
 * tests below assert exactly that shape, and both now carry a positive baseline in the
 * same test so the refusal has something to be different from.
 *
 * It replaced the account plane's own password door, a second password door with its own copy of these
 * rules that no route ever reached.
 */
function signInAtLogin(string $email, string $password, bool $stepUp = false): AttemptOutcome
{
    return app(PlatformRoot::class)->run(
        fn (): AttemptOutcome => app(PlatformAuth::class)->attemptPassword(
            Request::create('/login', 'POST'),
            $email,
            $password,
            $stepUp,
        ),
    );
}

/**
 * A REAL S256 PKCE PAIR, because a placeholder is not a test.
 *
 * Five suites sent `code_challenge: 'abc'` — three characters that are the base64url of
 * no digest at all. Every one of those tests was exercising a request no conformant
 * client could send, and the issuer's own RFC 7636 §4.2 check is what surfaced it.
 *
 * @return array{verifier: string, challenge: string}
 */
function pkcePair(string $verifier = 'a-verifier-of-sufficient-length-0123456789abc'): array
{
    return [
        'verifier' => $verifier,
        'challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
    ];
}

/** Just the challenge, for the many call sites that never redeem the code. */
function pkceChallenge(): string
{
    return pkcePair()['challenge'];
}
