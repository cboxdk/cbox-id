<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\EnvironmentAdminAuth;
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
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
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
 * Seed an environment-admin session for an account member on an environment.
 *
 * The session is keyed on the member's PLATFORM-ROOT SUBJECT — the credential of record
 * — not on the membership row, so tests go through this rather than writing the raw key
 * and encoding the wrong shape in a dozen places.
 */
function actAsEnvironmentAdmin(AccountMember $member, string $environmentId): void
{
    session()->put(EnvironmentAdminAuth::SESSION_KEY, $member->refresh()->subject_id);
    session()->put(EnvironmentAdminAuth::ENV_KEY, $environmentId);
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

/** An operator whose console reads are pinned to the default test plane. */
function impersonationOperator(string $email = 'imp-op@platform.test'): PlatformOperator
{
    return app(PlatformOperators::class)->create($email, 'a-strong-operator-pass', 'Op');
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
