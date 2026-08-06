<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Platform\Enums\AttemptOutcome;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\MagicLink;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/**
 * The account plane authenticates through the SUBJECT plane now — one identity stack,
 * not two. These tests pin what that buys and what it must not cost.
 *
 * See docs/core-concepts/unified-account-identity.md.
 */
function unifiedSetup(string $password = 'a-strong-unbreached-passphrase'): array
{
    platformRootEnvironment();

    $r = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: $password,
    ));

    return [
        'member' => app(Memberships::class)->find($r->owner->id),
        'organization' => $r->organization->refresh(),
        'env' => $r->environment,
    ];
}

it('signs an account member in against their platform-root subject, not the member row', function (): void {
    ['member' => $member] = unifiedSetup();

    expect($member->subject_id)->not->toBeNull();

    // Rotate the credential on the SUBJECT alone. The account door must follow it — if
    // the member row were still a credential store, the old password would still work.
    app(PlatformRoot::class)->run(
        fn () => app(Subjects::class)->setPassword((string) $member->subject_id, 'rotated-on-the-subject'),
    );

    $auth = app(PlatformAuth::class);
    $request = Request::create('/login', 'POST');

    expect(signInAtLogin('owner@acme.example', 'a-strong-unbreached-passphrase'))
        ->toBe(AttemptOutcome::Invalid)
        ->and(signInAtLogin('owner@acme.example', 'rotated-on-the-subject'))
        ->toBe(AttemptOutcome::Ok);
});

it('holds the account door to the SSO mandate on the account\'s organization', function (): void {
    ['member' => $member, 'organization' => $account] = unifiedSetup();

    $auth = app(PlatformAuth::class);
    $request = Request::create('/login', 'POST');

    // Baseline: the correct password signs in.
    expect(signInAtLogin('owner@acme.example', 'a-strong-unbreached-passphrase'))
        ->toBe(AttemptOutcome::Ok);

    // The account's organization now mandates SSO. The SAME correct password must be
    // refused HERE too — otherwise "require SSO" on an account would be a suggestion
    // that the workspace door quietly ignores.
    //
    // SsoRequired rather than Invalid: the refusal stands, and the door can now say which
    // organization made it and where to go instead. Reported as Invalid, this told the
    // owner of the workspace that their own credentials did not match one.
    app(PlatformRoot::class)->run(fn () => app(AuthPolicies::class)->setForOrganization(
        (string) $account->organization_id,
        new AuthPolicy(sso: SsoEnforcement::Required),
    ));

    expect(signInAtLogin('owner@acme.example', 'a-strong-unbreached-passphrase'))
        ->toBe(AttemptOutcome::SsoRequired);

    // A wrong password against the same member is still just a wrong password — the
    // mandate is asked only after the credential verifies.
    expect(signInAtLogin('owner@acme.example', 'not-the-passphrase'))
        ->toBe(AttemptOutcome::Invalid);
});

it('is identifier-first: the door asks for an email before a password', function (): void {
    platformRootEnvironment();

    // The ONE door. There was a second one for account members, identifier-first in its
    // own copy of this code, and this test existed twice for the same reason.
    Volt::test('auth.login')
        ->assertSee('Continue')
        ->assertDontSee('Forgot password?')
        ->set('email', 'owner@acme.example')
        ->call('continue')
        ->assertSet('identified', true)
        ->assertSee('Forgot password?');
});

it('signs a member in from a magic link, and resolves the membership from the session', function (): void {
    ['member' => $member] = unifiedSetup();
    $root = app(PlatformRoot::class);

    // The requests below are made AS the platform root, because that is the host an
    // account member's link is issued on and redeemed at. Left on the suite's default
    // environment, the redemption reads a token table it cannot see and the test measures
    // the tenancy scope rather than the door.
    platformRootDeployment();

    // There was a SECOND magic-link door — `/workspace/magic/{token}` — whose whole job
    // was to redeem the same token into an account session, and it refused a subject who
    // carried no account membership because the plane it landed on served nothing else.
    // One door, one token, one session: a stranger's link signs the stranger in, and what
    // they hold is a question asked afterwards rather than at the door.
    $stranger = $root->run(fn () => app(Subjects::class)->create('stranger@example.test', 'Stranger', 'a-strong-unbreached-passphrase'));
    $strangerToken = $root->run(fn (): string => app(MagicLink::class)->request('stranger@example.test'));

    $this->get(route('magic.redeem', $strangerToken))
        ->assertRedirect(route('dashboard'));
    expect(app(AccountAuth::class)->check())
        ->toBeFalse('a subject with no membership resolved to an account member')
        ->and($stranger->id)->not->toBeNull();

    // Not `nextRequest()`: it ends the request wholesale, ambient EnvironmentContext
    // included, and the token minted below is written into an environment-owned table.
    forgetSubjectSession();

    // …and the member's own link resolves to the member, off the same one session.
    $token = $root->run(fn (): string => app(MagicLink::class)->request('owner@acme.example'));

    $this->get(route('magic.redeem', $token))
        ->assertRedirect(route('dashboard'));

    // The session the redemption minted names the member's SUBJECT — which is the whole
    // claim: one token, one door, one session, and "which account" is a lookup off it
    // rather than anything the browser carries.
    expect(session(PlatformAuth::SESSION_KEY))->not->toBeNull();

    $sessionId = (string) session(PlatformAuth::SESSION_KEY);

    expect($root->run(fn (): ?string => app(SessionManager::class)->active($sessionId)?->user_id))
        ->toBe($member->subject_id);
});

it('kills the env-admin session when the underlying subject is deactivated', function (): void {
    ['member' => $member, 'env' => $env] = unifiedSetup();

    actAsEnvironmentAdmin($member, $env->id);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($env->id));

    $auth = app(EnvironmentAdminAuth::class);
    expect($auth->current()?->id)->toBe($member->id);

    // The subject is the credential of record, so deactivating it must end the admin
    // session on the very next resolve — not at the next login.
    app(PlatformRoot::class)->run(fn () => app(Subjects::class)->deactivate((string) $member->subject_id));

    // Resolve fresh — the guard memoises within ONE request by design, and what is being
    // pinned here is that the NEXT request finds nothing.
    app()->forgetInstance(EnvironmentAdminAuth::class);

    expect(app(EnvironmentAdminAuth::class)->membership())->toBeNull();
});

it('grants nothing on a session keyed on a subject with no account membership', function (): void {
    ['env' => $env] = unifiedSetup();

    // A real, active subject in the platform root — just not an account member.
    $subject = app(PlatformRoot::class)->run(
        fn () => app(Subjects::class)->create('outsider@example.test', 'Outsider', 'a-strong-unbreached-passphrase'),
    );

    // A real session for that subject, and the anchor — everything an admin session is
    // made of EXCEPT the account membership, which is the one thing being withheld.
    signInAsSubject($subject->id);
    session()->put(EnvironmentAdminAuth::ENV_KEY, $env->id);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($env->id));

    expect(app(EnvironmentAdminAuth::class)->membership())->toBeNull();
});
