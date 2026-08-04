<?php

declare(strict_types=1);

use App\Platform\AccountAuth;
use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\Navigation\ConsoleNavigation;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/**
 * Operator authority is a PERMISSION on the session that already exists.
 *
 * The platform pages — environments, accounts, operators, platform security — used to be
 * a second console: their own URL prefix, their own layout, their own sign-in form. None
 * of that was a security boundary. It was a consequence of `platform_operators` being a
 * second credential store, with an email and a bcrypt hash and none of the protections
 * that guard every other sign-in here — password policy, breach refusal, lockout, TOTP,
 * passkeys, step-up, session revocation. The widest reach in the product sat behind the
 * weakest door, and it was weakest BECAUSE it was separate.
 *
 * The operator is a subject now, so the separation has nothing left to justify it: the
 * pages become areas in the one console that appear for whoever may see them, exactly
 * like Billing already appears only for a member who may read it.
 *
 * These cover the rail. The middleware half — 404 for a signed-in non-operator, sign-in
 * for a visitor — lives with the middleware.
 */
function anAccountOwner(string $email = 'owner@acme.example'): object
{
    // The root FIRST, and that ordering is the whole fixture. An account provisioned with
    // no platform root is in the first-install bootstrap window: its member has no subject
    // yet, and neither does an operator created alongside it — so every assertion below
    // would be about an unlinked row rather than about authority. Getting this backwards
    // is what made the first run of these tests fail, and it failed for a real reason.
    platformRootEnvironment();

    return app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: $email,
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ))->member;
}

/**
 * Route names of every page in the rail, flattened.
 *
 * Read through `groups()` — the same projection the layout consumes — rather than off the
 * value objects. A rail that is correct in the model and wrong in the projection is still
 * a rail nobody can use, and the projection is where the layout gets its answer.
 *
 * @return list<string>
 */
function railRoutes(): array
{
    $member = app(AccountAuth::class)->current();
    $routes = [];

    foreach (app(ConsoleNavigation::class)->workspace($member?->role)->groups() as $area) {
        foreach ($area['pages'] as $page) {
            $routes[] = $page['route'];
        }
    }

    return $routes;
}

it('keeps the platform pages out of an ordinary member\'s rail', function (): void {
    $member = anAccountOwner();
    signInAsMember($member);

    expect(app(ConsoleScope::class)->isPlatformOperator())->toBeFalse()
        ->and(railRoutes())->not->toContain('platform.environments')
        ->and(railRoutes())->not->toContain('platform.operators');
})->group('security');

/**
 * The other half. A gate that is never open is not a gate — it is a page nobody can
 * reach, which is the bug this whole change replaces rather than the fix for it.
 *
 * Note the member session, NOT a subject session. That distinction is the one that broke
 * the first implementation: an account member's session key holds the MEMBER id, and the
 * credential behind it is the subject's only because the member row points at one. A
 * check that read the subject session alone answered "nobody" across the entire account
 * console — which is precisely where an operator signs in.
 */
it('gives the platform pages to an operator, in the same rail', function (): void {
    $member = anAccountOwner('staff@cbox.test');

    // The same person, holding an operator record. `create()` reuses the existing subject
    // for that address rather than minting a second one.
    app(PlatformOperators::class)->create('staff@cbox.test', 'a-strong-unbreached-passphrase', 'Staff');

    signInAsMember($member);

    expect(app(ConsoleScope::class)->isPlatformOperator())->toBeTrue()
        ->and(railRoutes())->toContain('platform.environments')
        ->and(railRoutes())->toContain('platform.operators');
})->group('security');

/**
 * Administering an environment is not running the deployment.
 *
 * It is ONE session now, which makes this sharper rather than moot. An environment admin
 * arrives through the account console — `/workspace/open/{env}` hands off — and comes out
 * the other side holding the same subject session plus an ANCHOR naming the environment.
 * So the session that would answer "who runs this deployment?" is the very session the
 * admin is standing in, and a resolver that simply took the subject it found there would
 * hand them the platform's own pages while they are at a tenant's altitude.
 *
 * The refusal is deliberate rather than incidental: if this person really does run the
 * deployment, they get the platform pages through their own sign-in, at the altitude
 * those pages belong to — not as a side effect of which console they last opened.
 */
it('does not make an environment administrator an operator', function (): void {
    $member = anAccountOwner('staff@cbox.test');

    app(PlatformOperators::class)->create('staff@cbox.test', 'a-strong-unbreached-passphrase', 'Staff');

    // The FULL handoff state, not half of it. `EnvironmentAdminAuth::check()` resolves the
    // member from the live session and compares the anchor to the host's environment — an
    // anchor alone is not an environment admin, and asserting against half a session would
    // have been asserting against a state no browser is ever in.
    $environment = serveOnTestHost($member->account->environments()->firstOrFail());

    app(EnvironmentContext::class)
        ->set(GenericEnvironment::of($environment->id));

    actAsEnvironmentAdmin($member, $environment->id);

    // CurrentUser, populated with the admin's OWN subject. This is the fixture that makes
    // the test about the refusal, and it took a falsification to find out: on a tenant
    // host the middleware structurally cannot populate it — the control-plane session
    // lives in the platform root and `auth_sessions` is environment-owned — so a test
    // that left it empty passed with the refusal DELETED. The resolver answered "nobody"
    // either way, and the assertion was about the tenancy scope rather than about
    // authority.
    $root = app(PlatformRoot::class);
    $subject = $root->run(fn () => app(Subjects::class)->find((string) $member->refresh()->subject_id));
    $session = $root->run(fn () => app(SessionManager::class)->active((string) session(PlatformAuth::SESSION_KEY)));
    app(CurrentUser::class)->set($subject, $session, null);

    expect(app(EnvironmentAdminAuth::class)->check())
        ->toBeTrue('fixture: this is meant to BE an environment admin')
        ->and(app(CurrentUser::class)->check())
        ->toBeTrue('fixture: the acting subject must be resolvable, or the refusal is not what holds')
        ->and(app(ConsoleScope::class)->isPlatformOperator())
        ->toBeFalse('an environment admin was handed the platform pages by the session they administer with');
})->group('security');

/**
 * Suspension has to reach the RAIL, not only the next sign-in.
 *
 * Authority rides a session that already exists, and suspending an operator has never
 * revoked their subject sessions. A check that ran only at sign-in would take away
 * tomorrow's access while leaving today's untouched — which is not what suspending
 * someone means.
 */
it('takes the platform pages away from a suspended operator mid-session', function (): void {
    $member = anAccountOwner('staff@cbox.test');

    $operators = app(PlatformOperators::class);
    $operator = $operators->create('staff@cbox.test', 'a-strong-unbreached-passphrase', 'Staff');
    // Two, because the platform refuses to suspend its last remaining operator.
    $other = $operators->create('other@cbox.test', 'a-strong-unbreached-passphrase', 'Other');

    signInAsMember($member);

    expect(app(ConsoleScope::class)->isPlatformOperator())->toBeTrue();

    $operators->suspend($operator->id, $other->id);

    // A fresh scope, because the answer is memoised per request and this is a new one.
    app()->forgetScopedInstances();

    expect(app(ConsoleScope::class)->isPlatformOperator())
        ->toBeFalse('a suspended operator kept the platform pages in the session they already held');
})->group('security');

/**
 * The loop this gate shipped with, in one test.
 *
 * `AuthenticateOperator` sends an unauthenticated visitor to the account sign-in. That
 * door writes an account-MEMBER session and nothing else. The gate's first version then
 * asked the SUBJECT session — so an operator who did exactly what they were told landed
 * back on the sign-in they had just completed, indefinitely, with correct credentials and
 * no message explaining anything.
 *
 * Asserting "the gate lets an operator through" is not enough on its own: it passes if
 * the test fabricates a session shape no door produces. This signs in through the real
 * door first, and only then knocks.
 */
it('lets an operator through the door it sent them to', function (): void {
    $member = anAccountOwner('staff@cbox.test');
    app(PlatformOperators::class)->create('staff@cbox.test', 'a-strong-unbreached-passphrase', 'Staff');

    config()->set('cbox-id.tenancy.multi_tenant', true);
    config()->set('cbox-id.tenancy.account_host', 'cboxid.com');

    // Refused while signed out, and pointed at the account door.
    $this->get('https://cboxid.com/platform')->assertRedirect(route('workspace.login'));

    // Sign in the way that door actually does it.
    $outcome = app(AccountAuth::class)->attempt(
        Request::create('/workspace/login', 'POST'),
        'staff@cbox.test',
        'a-strong-unbreached-passphrase',
    );

    expect($outcome->name)->toBe('Ok', 'the account door refused an operator its own gate points at');

    signInAsMember($member);
    $this->get('https://cboxid.com/platform')
        ->assertSuccessful();
})->group('security');

/**
 * The sign-in a refused visitor is pointed at depends on the deployment SHAPE.
 *
 * Both routes are always registered, so `Route::has()` cannot tell them apart — which one
 * answers is a question about the plane. `workspace.login` carries `plane:account`, false
 * on a single-host install because there is no separate account plane when there is no
 * host split. Pointing a self-hosted operator there points them at a 404, and a gate whose
 * refusal is itself a dead end is worse than no gate.
 */
it('points a refused visitor at the sign-in this deployment actually serves', function (): void {
    // An INSTALLED deployment, stated. An empty one answers every human page with its
    // first-run screen instead, which is right — and which would have made this test pass
    // or fail on a fact it is not about.
    installedDeployment();

    // Single-host: the ordinary door.
    $this->get('/platform')->assertRedirect(route('login'));

    // SaaS: the account door, on the account host.
    config()->set('cbox-id.tenancy.multi_tenant', true);
    config()->set('cbox-id.tenancy.account_host', 'cboxid.com');

    $this->get('https://cboxid.com/platform')->assertRedirect(route('workspace.login'));
})->group('security');

/**
 * An operator who is not a customer.
 *
 * This is the ordinary case and it was the one that did not work. An operator runs the
 * deployment; they do not buy it, so they have no account and no member row. The account
 * host serves exactly one sign-in, and `plane:subject` withholds the tenant door there —
 * so a door that could only match member rows left the person who runs the platform with
 * no way in at all. The console told them to sign in, and the sign-in had nothing to match
 * them against.
 *
 * They get a SUBJECT session, and therefore a rail with the platform areas and nothing
 * else — which is the right console for someone with no account.
 */
it('signs in an operator who has no account at all', function (): void {
    $root = platformRootEnvironment();
    installedDeployment();
    config()->set('cbox-id.tenancy.multi_tenant', true);
    config()->set('cbox-id.tenancy.account_host', 'cboxid.com');

    // The ambient environment is the platform root, which is what the account HOST
    // resolves to. It matters: an operator's subject and its session row both live there,
    // and `CurrentUser` resolves the session under whatever scope is current — so a test
    // standing in some other environment would report a signed-in operator as nobody, and
    // blame the sign-in for it.
    app(EnvironmentContext::class)
        ->set(GenericEnvironment::of($root->id));

    app(PlatformOperators::class)->create('lonely@cbox.test', 'a-strong-unbreached-passphrase', 'Lonely');

    $outcome = app(AccountAuth::class)->attempt(
        Request::create('/workspace/login', 'POST'),
        'lonely@cbox.test',
        'a-strong-unbreached-passphrase',
    );

    expect($outcome->name)->toBe('Ok', 'the person who runs the deployment cannot sign in to it');

    // And the session that produced actually carries the authority. Asserted through a
    // REQUEST, not in this tick: `CurrentUser` is populated by middleware at the start of
    // a request, so asking it here would report nobody no matter what the door did — and
    // would have blamed the door for it.
    $subjectId = (string) app(PlatformOperators::class)->findByEmail('lonely@cbox.test')?->subject_id;
    signInAsSubject($subjectId);

    $this->get('https://cboxid.com/platform')->assertSuccessful();
})->group('security');

/** A wrong password for a real operator is still refused. */
it('refuses an operator with the wrong password', function (): void {
    platformRootEnvironment();
    app(PlatformOperators::class)->create('lonely@cbox.test', 'a-strong-unbreached-passphrase', 'Lonely');

    expect(app(AccountAuth::class)->attempt(
        Request::create('/workspace/login', 'POST'),
        'lonely@cbox.test',
        'not-the-passphrase',
    )->name)->toBe('Invalid');
})->group('security');

/**
 * Suspending an operator takes away AUTHORITY, not the person.
 *
 * The door used to refuse them outright, and that was an artefact rather than a decision:
 * a successful sign-in meant an account-MEMBER session, and a suspended operator has no
 * member row to write one from — so "holds no authority" and "has no way in" collapsed
 * into the same answer. They are different questions. The credential is an ordinary
 * subject's and it still works; what it opens is a console with none of the platform
 * pages in it, because authority is asked of the live operator record on every request.
 *
 * Refusing the credential instead would be worse than useless: it does not revoke
 * anything (the same password still signs them in on any tenant plane they belong to),
 * and it would put "is this person staff?" back inside the door — which is the coupling
 * that shut real operators out of the one console they are for.
 */
it('signs a suspended operator in as an ordinary person, with no platform authority', function (): void {
    $root = platformRootEnvironment();
    installedDeployment();
    app(EnvironmentContext::class)->set(GenericEnvironment::of($root->id));

    $operators = app(PlatformOperators::class);
    $operator = $operators->create('lonely@cbox.test', 'a-strong-unbreached-passphrase', 'Lonely');
    $other = $operators->create('other@cbox.test', 'a-strong-unbreached-passphrase', 'Other');
    $operators->suspend($operator->id, $other->id);

    expect(app(AccountAuth::class)->attempt(
        Request::create('/workspace/login', 'POST'),
        'lonely@cbox.test',
        'a-strong-unbreached-passphrase',
    )->name)->toBe('Ok');

    // …and the session that produced runs the deployment not at all. 404 rather than 403,
    // because a 403 would confirm to anyone holding any account that a staff console
    // exists at that address.
    signInAsSubject((string) $operator->refresh()->subject_id);

    $this->get('/platform')->assertNotFound();
    expect(app(ConsoleScope::class)->isPlatformOperator())
        ->toBeFalse('a suspended operator kept platform authority');
})->group('security');

/**
 * Signing in and LANDING are two questions.
 *
 * Answering only the first turned a successful sign-in into a silent loop: the account
 * door sent everyone to `workspace.home`, which is gated on a member session, so an
 * operator with no account — who gets a subject session, because that is what they are —
 * was bounced straight back to the form. With no error, because nothing had failed. They
 * simply had no account to land in, and nothing said so.
 *
 * This drives the real component, not the service beneath it: the loop lived in the
 * destination the page chose, and a test of `attempt()` alone reports success while the
 * person in front of the screen is stuck.
 */
it('lands an account-less operator on the console they actually have', function (): void {
    $root = platformRootEnvironment();
    installedDeployment();
    config()->set('cbox-id.tenancy.multi_tenant', true);
    config()->set('cbox-id.tenancy.account_host', 'cboxid.com');
    app(EnvironmentContext::class)->set(GenericEnvironment::of($root->id));

    app(PlatformOperators::class)->create('lonely@cbox.test', 'a-strong-unbreached-passphrase', 'Lonely');

    $page = Volt::test('workspace.login')
        ->set('email', 'lonely@cbox.test')
        ->call('continue')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('login');

    expect($page->errors()->all())->toBe([]);

    // One console, one landing. The proof is that the landing SERVES them: asserting only
    // where they were sent is what let the loop ship, because the redirect was always
    // correct and it was the arrival that refused. So this follows the redirects and
    // asserts the page that finally answers, which is the assertion that cannot be
    // satisfied by a loop however many hops it has.
    $subjectId = (string) app(PlatformOperators::class)->findByEmail('lonely@cbox.test')?->subject_id;
    signInAsSubject($subjectId);

    $this->followingRedirects()->get(route('workspace.home'))->assertSuccessful();

    // …and it is the PLATFORM, not the account launchpad. The console root used to serve
    // this person a Projects page describing a thing they do not have, with its only
    // action gated off by a role they do not hold — a 200 that was still a dead end, which
    // is why "it answered 200" is not on its own the property worth guarding.
    $this->get(route('workspace.home'))->assertRedirect(route('platform.environments'));
})->group('security');

/** And an account member still lands on their account, unchanged. */
it('still lands an account member on their workspace', function (): void {
    $member = anAccountOwner();
    installedDeployment();

    $page = Volt::test('workspace.login')
        ->set('email', $member->email)
        ->call('continue')
        ->set('password', 'a-strong-unbreached-passphrase')
        ->call('login');

    expect($page->effects['redirect'] ?? '')->toBe(route('workspace.home'));
})->group('security');

/**
 * The member memo must not answer for whoever was signed in a moment ago.
 *
 * `AccountAuth::current()` is memoised now — the lookup crosses into the platform root
 * and was costing four to nine reads per page. A memo on an identity is the one kind that
 * fails dangerously: answer from it after the session changed and the console renders one
 * person's account under another person's session.
 *
 * Two accounts in one request, with a real sign-out between them, because that is the
 * shape a person actually produces — switching accounts, or signing out on a shared
 * machine and someone else signing in.
 */
it('does not answer for the previous identity after a sign-out', function (): void {
    platformRootEnvironment();
    installedDeployment();

    $first = anAccountOwner('first@acme.example');
    $second = anAccountOwner('second@acme.example');

    $auth = app(AccountAuth::class);
    $request = fn () => Request::create('/workspace/login', 'POST');

    expect($auth->attempt($request(), 'first@acme.example', 'a-strong-unbreached-passphrase')->name)->toBe('Ok')
        ->and(app(AccountAuth::class)->current()?->email)->toBe('first@acme.example');

    // The subject session is repointed WITHOUT AccountAuth being told — which is what an
    // account switch and an impersonation resume both do. Going through logout() instead
    // would clear the memo and prove nothing about the key: the docblock on forgetMemo()
    // is explicit that the key is what handles a DIFFERENT person, and the reset is only
    // for the same person's freshly-written row. An unkeyed memo passes the logout path.
    signInAsSubject((string) $second->subject_id);

    expect(app(AccountAuth::class)->current()?->email)
        ->toBe('second@acme.example', 'the memo answered for the account that just left');
})->group('security');
