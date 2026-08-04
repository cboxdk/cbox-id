<?php

declare(strict_types=1);

use App\Http\Middleware\PointAtFirstRun;
use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\ConsoleStepUp;
use App\Platform\CurrentUser;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\EnvironmentSudo;
use App\Platform\OperatorEnvironment;
use App\Platform\PlaneResolver;
use App\Platform\PlatformAuth;
use App\Platform\Sudo;
use Cbox\Id\Devices\Enums\DevicePlatform;
use Cbox\Id\Devices\Enums\DeviceStatus;
use Cbox\Id\Devices\Models\Device;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
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
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Models\AccountMember;
use Cbox\Id\Platform\Models\PlatformOperator;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\PersistentMiddleware;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use PHPUnit\Framework\Assert as PHPUnit;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

// Pest 4 browser tests (real Chromium via Playwright) — boot the full app the same
// way, so `visit()` drives the running application with its middleware and DB.
uses(TestCase::class, RefreshDatabase::class)->in('Browser');

/**
 * Assert the component actually RENDERED — it did not redirect, at mount OR afterwards.
 *
 * USE THIS INSTEAD OF `assertNoRedirect()`. Livewire's own `assertNoRedirect()` is
 * VACUOUS for a redirect issued in `mount()`, and that asymmetry is a trap:
 *
 *   - `assertNoRedirect()` inspects ONLY the Livewire EFFECT payload
 *     (`$this->effects['redirect']`).
 *   - A redirect issued during `mount()` of a `Volt::test(...)` / `Livewire::test(...)`
 *     is an INITIAL render, not a Livewire message — it surfaces as an HTTP 302 on the
 *     underlying response and never reaches the effects array.
 *   - So `assertNoRedirect()` passes, silently, on a component that redirected at mount.
 *   - `assertRedirect()` does NOT have this hole: it falls back to the response when the
 *     request is not a Livewire request. Only the negative form is blind.
 *
 * A `max_age` P0 in the OAuth consent screen survived a test written to catch it for
 * exactly this reason. This macro closes both halves: HTTP 200 on the response (mount
 * rendered a page rather than a 302) AND no redirect effect (no action redirected).
 *
 * It still only says "nothing bad happened" — always pair it with a positive assertion
 * about what SHOULD have rendered or been set.
 */
Testable::macro('assertRenderedNotRedirected', function (): Testable {
    /** @var Testable $this */
    $this->assertStatus(200);

    PHPUnit::assertArrayNotHasKey(
        'redirect',
        $this->effects,
        'Component performed a redirect, but the test expected it to render.'
    );

    return $this;
});

/**
 * Stand up the PLATFORM-ROOT environment ("tenant 1"), the environment account members
 * live in as ordinary subjects. Idempotent — a deployment has exactly one, and so does a
 * test. Provision accounts AFTER calling this: an account provisioned with no root is in
 * the first-install bootstrap window, where its members have no subject yet.
 *
 * See docs/core-concepts/unified-account-identity.md.
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
 * Sign an account member in as the ADMINISTRATOR of an environment.
 *
 * Through the real {@see EnvironmentAdminAuth::establish()}, because an admin session is
 * the member's ordinary PLATFORM-ROOT SUBJECT session plus an anchor naming the
 * environment — not a raw key a test could fabricate. A test that wrote the keys by hand
 * would be asserting against a session shape no door produces.
 *
 * The member must HAVE a subject, which means a platform root existed before the account
 * was provisioned ({@see platformRootEnvironment()}).
 */
function actAsEnvironmentAdmin(AccountMember $member, string $environmentId): void
{
    app(EnvironmentAdminAuth::class)->establish(
        (string) $member->refresh()->subject_id,
        $environmentId,
    );
}

/**
 * Sign the browser in AS AN ACCOUNT MEMBER.
 *
 * There is no member session any more — a member is a subject that holds a membership, so
 * this establishes the ONE session and the membership is looked up from it. Tests used to
 * write `AccountAuth::SESSION_KEY` directly, which is exactly why the shape was easy to
 * get wrong: half of them had to remember to write a security stamp beside it, and the
 * half that forgot were asserting against a session the plane would have refused.
 *
 * Same precondition as above: the member needs a subject, so stand up the platform root
 * BEFORE provisioning the account.
 */
function signInAsMember(AccountMember $member): void
{
    $subjectId = (string) $member->refresh()->subject_id;

    expect($subjectId)->not->toBe(
        '',
        'fixture: this member has no subject — provision the account AFTER platformRootEnvironment()',
    );

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

    signInAsSubject((string) $operator->refresh()->subject_id);

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

        if ($subject !== null && $session !== null) {
            app(CurrentUser::class)->set($subject, $session, null);
        }
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
function multiTenantDeployment(string $accountHost = 'cboxid.com'): void
{
    config()->set('cbox-id.tenancy.multi_tenant', true);
    config()->set('cbox-id.tenancy.account_host', $accountHost);
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
 * @return array{member: AccountMember, envId: string}
 */
function crudSetup(): array
{
    // The environment-admin console is a multi-tenant surface — it is gated on an account
    // member administering one of their ACCOUNT's environments, and a single-tenant install
    // has one environment which is the platform root and belongs to no account. So the whole
    // `/admin` prefix 404s on that shape ({@see \App\Http\Middleware\RequireMultiTenant}),
    // and every test that drives it is stating the SaaS shape whether it says so or not.
    multiTenantDeployment();

    platformRootEnvironment();
    $r = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($r->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($r->environment->id));
    actAsEnvironmentAdmin($r->member, $r->environment->id);

    return ['member' => $r->member, 'envId' => $r->environment->id];
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
 * Drive a component action through the REAL Livewire update endpoint.
 *
 * WHY THIS EXISTS. `Volt::test()` and `Livewire::test()` invoke the component directly:
 * they never route, and {@see PersistentMiddleware}
 * short-circuits for anything that is not the update endpoint, so NO route middleware runs
 * — persistent or otherwise. Every write test against a `sudo`-gated page was therefore
 * passing with the gate removed, and the suite could not tell. Nothing in this repository
 * had ever POSTed to the endpoint, so there was no path that ran the middleware at all.
 *
 * @param  list<mixed>  $params
 */
function livewireUpdate(string $pageUrl, string $component, string $method, array $params = []): TestResponse
{
    $page = test()->get($pageUrl);
    $page->assertSuccessful();

    return replaySnapshot($pageUrl, snapshotFor((string) $page->getContent(), $component), $method, $params);
}

/**
 * The wire:snapshot of ONE named component, exactly as rendered.
 *
 * Lifted verbatim rather than rebuilt: it carries the HMAC checksum Livewire verifies
 * before it will hydrate anything, and a re-encoded copy of the decoded array does not
 * survive that check. Reading the string out of the DOM is also what a browser does.
 *
 * By name, because a console page renders several components — the organization switcher
 * and the rail among them — and the first is never the one under test.
 */
function snapshotFor(string $html, string $component): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

    $snapshot = null;

    foreach ($matches[1] as $encoded) {
        $candidate = html_entity_decode($encoded, ENT_QUOTES);
        $decoded = json_decode($candidate, true);

        if (is_array($decoded) && (($decoded['memo']['name'] ?? null) === $component)) {
            $snapshot = $candidate;
        }
    }

    PHPUnit::assertNotNull($snapshot, "No `{$component}` component was rendered on that page.");

    return (string) $snapshot;
}

/**
 * POST a captured snapshot back, as the browser holding it would.
 *
 * Separate from {@see livewireUpdate()} so a test can capture a snapshot in one state and
 * replay it in another — which is the shape of the threat the persistent-middleware list
 * exists for: a page rendered while a step-up window was open must not go on acting once
 * it has closed.
 *
 * @param  list<mixed>  $params
 */
function replaySnapshot(string $pageUrl, string $snapshot, string $method, array $params = []): TestResponse
{
    // Same ORIGIN as the page. The endpoint's PATH is Livewire's to choose — it is
    // anonymised per application key, so a hardcoded `/livewire/update` simply 404s.
    $origin = (string) preg_replace('#^(https?://[^/]+).*$#', '$1', $pageUrl);

    return test()->withHeaders(['X-Livewire' => ''])->postJson(
        $origin.app(HandleRequests::class)->getUpdateUri(),
        [
            '_token' => csrf_token(),
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => (object) [],
                'calls' => [['path' => '', 'method' => $method, 'params' => $params]],
            ]],
        ],
    );
}
