<?php

declare(strict_types=1);

use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\PlatformAuth;
use App\Providers\ConsoleServiceProvider;
use Cbox\Console\Kit\Facades\Console;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Contracts\MfaMandate;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Enums\MfaRequirement;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** The organization the signed-up owner belongs to, read in the platform root. */
function ownerOrgOf(string $subjectId): string
{
    return (string) app(PlatformRoot::class)->run(
        fn () => app(Memberships::class)->forUser($subjectId)->first()?->organization_id,
    );
}

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
/**
 * The OWNER'S SUBJECT, which is what every caller here actually wants: these tests are
 * about authority carried by a session, and a session names a subject.
 */
function anAccountOwner(string $email = 'owner@acme.example'): object
{
    // The root FIRST, and that ordering is the whole fixture — provisioning refuses without
    // one rather than producing a customer whose organization does not exist.
    platformRootEnvironment();

    return app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: $email,
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ))->owner;
}

/**
 * Route names of every page the rail would actually DRAW, flattened.
 *
 * Read from the registry with the layout's own feature filter applied, because that is
 * where the answer now comes from: the platform section is three areas of the one console
 * ({@see ConsoleServiceProvider}), not a separate tree in a separate shell,
 * and its pages are gated by the `platform.operator` feature exactly as Billing is gated
 * by `organization.billing`.
 *
 * THE FILTER IS THE POINT, and reading `areas()` raw is what makes this test lie. Every
 * platform page is present in the registry for every visitor — an area is a declaration,
 * not a grant — so an assertion against the unfiltered registry says "the platform section
 * exists", which is true for a member and for an operator alike. Mirrors the reject in
 * `layouts/app.blade.php`; a rail correct in the model and wrong in the projection is
 * still a rail nobody can use.
 *
 * @return list<string>
 */
function railRoutes(): array
{
    $routes = [];

    foreach (Console::nav()->areas() as $area) {
        foreach ($area->pages() as $page) {
            if ($page->feature !== null && ! Console::featureActive($page->feature)) {
                continue;
            }

            $routes[] = $page->route;
        }
    }

    return $routes;
}

it('keeps the platform pages out of an ordinary member\'s rail', function (): void {
    $member = anAccountOwner();
    signInAsMember($member->id);

    // Three refusals, and they are not the same refusal. The feature is what empties the
    // areas; the rendered console is what proves the layout honours it (an area whose
    // pages are all dropped is dropped whole, so a member's rail names no platform page);
    // and the 404 is what proves the rail is not the authorization — a member who types
    // the URL is turned away by AuthenticateOperator whatever the nav says.
    expect(app(ConsoleScope::class)->isPlatformOperator())->toBeFalse()
        ->and(railRoutes())->not->toContain('platform.customers')
        ->and(railRoutes())->not->toContain('platform.environments')
        ->and(railRoutes())->not->toContain('platform.operators');

    $html = (string) $this->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->not->toContain('/platform/');

    $this->get('/platform')->assertNotFound();
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

    signInAsMember($member->id);

    // IN THE SAME RAIL, which is what the test's name has always claimed and what it could
    // not check while the platform section had a rail of its own: the assertion is that
    // one rendered console page carries BOTH a customer area and the platform areas, so an
    // operator moves between them without changing shells.
    expect(app(ConsoleScope::class)->isPlatformOperator())->toBeTrue()
        ->and(railRoutes())->toContain('platform.environments')
        ->and(railRoutes())->toContain('platform.operators');

    /*
     * ON THE PAGE ITSELF, read off the shell it is framed by. Asserted on the nav rather
     * than on the document: the areas are serialised into the response either way, with the
     * slashes escaped, so a substring check over the HTML was matching — or failing to —
     * for reasons that had nothing to do with the rail.
     */
    $shell = (array) $this->get(route('dashboard'))->assertOk()->inertiaProps('shell');

    $hrefs = collect($shell['areas'])->pluck('href');

    expect($hrefs)->toContain(route('platform.customers'))
        ->and($hrefs)->toContain(route('platform.operators'))
        // …and a customer area beside them, which is the whole claim: one rail, both
        // altitudes, no second shell to change into.
        ->and($hrefs)->toContain(route('dashboard'));
})->group('security');

/**
 * Administering an environment is not running the deployment.
 *
 * It is ONE session now, which makes this sharper rather than moot. An environment admin
 * arrives through the account console — `/open/{env}` hands off — and comes out
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
    $environment = serveOnTestHost(app(PlatformRoot::class)->run(fn () => Organization::query()->findOrFail(ownerOrgOf($member->id))->environments()->firstOrFail()));

    app(EnvironmentContext::class)
        ->set(GenericEnvironment::of($environment->id));

    actAsEnvironmentAdmin($member->id, $environment->id);

    // CurrentUser, populated with the admin's OWN subject. This is the fixture that makes
    // the test about the refusal, and it took a falsification to find out: on a tenant
    // host the middleware structurally cannot populate it — the control-plane session
    // lives in the platform root and `auth_sessions` is environment-owned — so a test
    // that left it empty passed with the refusal DELETED. The resolver answered "nobody"
    // either way, and the assertion was about the tenancy scope rather than about
    // authority.
    $root = app(PlatformRoot::class);
    $subject = $root->run(fn () => app(Subjects::class)->find($member->id));
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

    signInAsMember($member->id);

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
    $this->get('https://cboxid.com/platform')->assertRedirect(route('login'));

    // Sign in the way that door actually does it.
    $outcome = signInAtLogin('staff@cbox.test', 'a-strong-unbreached-passphrase');

    expect($outcome->name)->toBe('Ok', 'the account door refused an operator its own gate points at');

    signInAsMember($member->id);
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

    $this->get('https://cboxid.com/platform')->assertRedirect(route('login'));
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

    $outcome = signInAtLogin('lonely@cbox.test', 'a-strong-unbreached-passphrase');

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

    expect(signInAtLogin('lonely@cbox.test', 'not-the-passphrase')->name)->toBe('Invalid');
})->group('security');

/**
 * Suspending an operator takes away AUTHORITY, not the person.
 *
 * The door used to refuse them outright, and that was an artefact rather than a decision:
 * a successful sign-in meant an account-MEMBER session, and a suspended operator has no
 * member row to write one from — so "holds no authority" and "has no way in" collapsed
 * into the same answer. They are different questions. The credential is an ordinary
 * subject's and it still works; what it opens is a page saying there is no workspace for
 * this address, because authority is asked of the live operator record on every request.
 *
 * Refusing the credential instead would be worse than useless: it does not revoke
 * anything (the same password still signs them in on any tenant plane they belong to),
 * and it would put "is this person staff?" back inside the door — which is the coupling
 * that shut real operators out of the one console they are for.
 *
 * This docblock used to claim they got "a console with none of the platform pages in it",
 * and nothing here asserted it. They did not: the workspace rail derives from the member
 * ROLE, a null role produces zero nav areas, and both landing guards were
 * `abort_unless(isPlatformOperator(), 403)`. The real outcome was a successful sign-in
 * followed by a 403 on every page, an empty rail, and — because every sign-out control
 * lives inside a layout the error page never renders — no way out but clearing cookies.
 * Asserting the REFUSAL and never the LANDING is precisely what let that ship, so the
 * landing is asserted below, followed to the page that finally answers.
 */
it('signs a suspended operator in as an ordinary person, with no platform authority', function (): void {
    $root = platformRootEnvironment();
    installedDeployment();
    app(EnvironmentContext::class)->set(GenericEnvironment::of($root->id));

    $operators = app(PlatformOperators::class);
    $operator = $operators->create('lonely@cbox.test', 'a-strong-unbreached-passphrase', 'Lonely');
    $other = $operators->create('other@cbox.test', 'a-strong-unbreached-passphrase', 'Other');
    $operators->suspend($operator->id, $other->id);

    expect(signInAtLogin('lonely@cbox.test', 'a-strong-unbreached-passphrase')->name)->toBe('Ok');

    // …and the session that produced runs the deployment not at all. 404 rather than 403,
    // because a 403 would confirm to anyone holding any account that a staff console
    // exists at that address.
    signInAsSubject((string) $operator->subject_id);

    $this->get('/platform')->assertNotFound();
    expect(app(ConsoleScope::class)->isPlatformOperator())
        ->toBeFalse('a suspended operator kept platform authority');

    // And they LAND somewhere that serves them. Followed through the redirects to the page
    // that answers, because asserting only where they were sent is what let a 403 pass for
    // a landing — the redirect was always issued, and it was the arrival that refused.
    nextRequest();
    $landing = $this->followingRedirects()->get(route('projects'))->assertSuccessful();

    expect((string) $landing->inertiaProps('greeting'))->toContain('belong to an organization here yet')
        // THE WAY OUT. Nothing rendered one before: the account rail needed nav areas and
        // this person had none, and the mobile sheet is `lg:hidden`. The account menu draws
        // it from the shared auth prop, so a person with no areas still gets one — and
        // tests/Browser is where its being drawn is held.
        ->and($landing->inertiaProps('auth.user'))->not->toBeNull();

    // The same for the one other page a signed-in person is routinely sent to.
    nextRequest();
    $this->followingRedirects()->get(route('account'))->assertSuccessful();
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

    // TWO REQUESTS, because it is two steps: the identifier is answered first and the
    // password only after this deployment has decided that a password is what it wants.
    test()->from(route('login'))->post(route('login.identify'), ['email' => 'lonely@cbox.test']);

    test()->from(route('login'))
        ->post(route('login.attempt'), [
            'email' => 'lonely@cbox.test',
            'password' => 'a-strong-unbreached-passphrase',
        ])
        ->assertSessionHasNoErrors();

    // One console, one landing. The proof is that the landing SERVES them: asserting only
    // where they were sent is what let the loop ship, because the redirect was always
    // correct and it was the arrival that refused. So this follows the redirects and
    // asserts the page that finally answers, which is the assertion that cannot be
    // satisfied by a loop however many hops it has.
    $subjectId = (string) app(PlatformOperators::class)->findByEmail('lonely@cbox.test')?->subject_id;
    signInAsSubject($subjectId);

    // On the ACCOUNT HOST this deployment names, because `plane:console` is a host
    // question by design (an unmapped name resolves to the root, so the context cannot
    // tell `cboxid.com` from `anything.invalid`) — and this test states the SaaS shape.
    $this->followingRedirects()->get('https://cboxid.com/projects')->assertSuccessful();

    // …and it is the PLATFORM, not the account launchpad. The console root used to serve
    // this person a Projects page describing a thing they do not have, with its only
    // action gated off by a role they do not hold — a 200 that was still a dead end, which
    // is why "it answered 200" is not on its own the property worth guarding.
    nextRequest();
    $this->get('https://cboxid.com/projects')->assertRedirect(route('platform.environments'));
})->group('security');

/** And an account member lands in the same console, by the same door. */
it('lands an account member in the console too', function (): void {
    $member = anAccountOwner();
    installedDeployment();

    // The door authenticates in the environment the request is standing in, and an account
    // member's subject lives in the platform root. On the root host those are the same
    // environment; in a test they are only the same if the fixture says so. The account
    // door wrapped every lookup in `PlatformRoot::run()` because it was served on ONE host
    // and could assume which — a door served everywhere cannot, and must not.
    platformRootDeployment();

    test()->from(route('login'))->post(route('login.identify'), ['email' => $member->email]);

    test()->from(route('login'))
        ->post(route('login.attempt'), [
            'email' => $member->email,
            'password' => 'a-strong-unbreached-passphrase',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard'));

    // …and what they OWN is what puts the Identity platform area in front of them, rather
    // than which door they came through. There was a second door for exactly this, and it
    // authenticated the same subject against the same credential.
    signInAsMember($member->id);
    $this->get(route('projects'))->assertOk();
})->group('security');

/**
 * A per-request memo on IDENTITY must not answer for whoever was signed in a moment ago.
 *
 * This was written against `AccountAuth::current()`, whose memo crossed into the platform
 * root and was costing four to nine reads per page. That class is gone, and the property is
 * not: {@see ConsoleScope} memoises the operator lookup for exactly the same reason — the
 * rail asks on every render and so does every guard on a platform page — and a memo on an
 * identity is the one kind that fails dangerously. Answer from it after the session changed
 * and the console renders one person's authority under another person's session.
 *
 * Two people in one request, because that is the shape a person actually produces:
 * switching accounts, resuming from an impersonation, or signing out on a shared machine
 * while someone else signs in.
 */
it('does not answer for the previous identity after a sign-out', function (): void {
    platformRootEnvironment();
    installedDeployment();

    $first = anAccountOwner('first@acme.example');
    $second = anAccountOwner('second@acme.example');

    // Both halves through `signInAsSubject()`, which is what a request with somebody
    // signed in looks like. It used to sign the first one in through the door itself, and
    // that only worked because the door refreshed `CurrentUser` on the way past — a thing
    // it did for this test's benefit and for nothing else, since every real caller
    // redirects and the next request resolves the identity from the session anyway.
    // Putting that refresh on the one door every plane now shares stamped a
    // platform-root identity onto environment-admin sessions and 403'd that console.
    signInAsSubject($first->id);

    expect(app(ConsoleScope::class)->actorId())->toBe($first->id);

    // The subject session is repointed WITHOUT the scope being told — which is what an
    // account switch and an impersonation resume both do. Going through logout() instead
    // would clear everything and prove nothing about the key.
    signInAsSubject($second->id);

    expect(app(ConsoleScope::class)->actorId())
        ->toBe($second->id, 'the scope answered for the identity that just left');
})->group('security');

/**
 * Follow a redirect chain with a HOP LIMIT, and answer where it ARRIVES.
 *
 * Neither of the two shapes already in this file can see a loop. `assertRedirect()` reads
 * ONE hop, so it passes against an infinite chain — which is exactly how the loop below
 * shipped green. And `followingRedirects()` reads every hop but its `while` has no bound,
 * so an infinite chain hangs the suite rather than failing it, and a hang reads as CI
 * trouble rather than as a bug in the product.
 *
 * A bound is the whole point: the chain either ends somewhere a person can act, within a
 * number of hops a browser would tolerate, or this says so and names the chain it walked.
 */
function arrivalWithin(TestCase $test, string $url, int $hops = 4): TestResponse
{
    $response = $test->get($url);
    $chain = [$url];

    for ($hop = 0; $hop < $hops && $response->isRedirect(); $hop++) {
        $location = (string) $response->headers->get('Location');
        $chain[] = $location;

        // Each hop is a REQUEST, and ConsoleScope memoises per request — without this the
        // second hop answers with the first hop's authority.
        nextRequest();
        $response = $test->get($location);
    }

    expect($response->isRedirect())->toBeFalse(
        'still redirecting after '.$hops.' hops: '.implode(' -> ', $chain),
    );

    return $response;
}

/**
 * THE LANDING IS NOT A PAGE A HOLD MAY HOLD.
 *
 * The account console had a `no-access` page so a member-less non-operator was not stuck
 * at a door — and under an environment-wide MFA mandate it became the door. The MFA hold
 * exempted that console's security page, which turns this persona around to the landing,
 * and the landing was not exempt: held, sent to the security page, turned around, held
 * again. A 403 with no sign-out was the bug that page was written for;
 * ERR_TOO_MANY_REDIRECTS is worse. The landing is `/dashboard` now — the console root
 * every signed-in subject has — and the exemption moved with it.
 *
 * No membership is needed to reach that state. {@see DatabaseMfaMandate} starts from the
 * environment BASELINE — the `organization_id IS NULL` policy an environment admin sets
 * on the sign-in rules page — so every subject in the environment is held, including one
 * who belongs to no organization and therefore has nothing to enrol INTO.
 */
it('keeps the no-access landing reachable under an environment-wide MFA mandate', function (): void {
    $root = platformRootEnvironment();
    installedDeployment();
    app(EnvironmentContext::class)->set(GenericEnvironment::of($root->id));

    // The persona the landing exists for: a real subject, no membership anywhere, no
    // operator record.
    $subject = app(Subjects::class)->create('stranger@nowhere.test', 'Stranger', 'a-strong-unbreached-passphrase');
    app(Subjects::class)->markEmailVerified($subject->id, 'stranger@nowhere.test');
    signInAsSubject($subject->id);

    // The BASELINE this test would be worthless without: with no mandate the landing
    // answers, so a failure below is the hold and nothing else.
    arrivalWithin($this, route('dashboard'))->assertSuccessful();

    app(AuthPolicies::class)->setForEnvironment(new AuthPolicy(mfa: MfaRequirement::Required));

    expect(app(MfaMandate::class)->requiresEnrolment($subject->id))
        ->toBeTrue('fixture: the mandate does not bind this subject, so nothing is being tested');

    /*
     * The one action always available to somebody who cannot satisfy the hold. WHERE they
     * come to rest is the security page — the one page the mandate leaves reachable,
     * because it is where enrolment lives — and the property being asserted is that the
     * chain terminates on a page that has a way out at all.
     *
     * ASKED OF THE PROPS. The sign-out URL used to be in the response body; on a ported
     * page it is a serialised prop, so a document match would fail for a page that offers
     * the way out perfectly well — and, worse, would pass on any page that happened to
     * mention the URL.
     */
    nextRequest();
    expect(hasWayOut(arrivalWithin($this, route('dashboard'))->assertSuccessful()))
        ->toBeTrue('the mandate left this person on a page with no way to sign out');

    // An Identity platform page enters the same cycle — this persona owns no identity
    // providers, so it turns them around too — and it is asserted with the same bound
    // rather than assumed. Where they COME TO REST is the security page rather than the
    // console root, because that is the one page the mandate leaves reachable; what
    // matters is that the chain terminates on a page with a way out, which is the whole
    // property the account console's `no-access` page existed for.
    nextRequest();
    expect(hasWayOut(arrivalWithin($this, route('projects'))->assertSuccessful()))
        ->toBeTrue('the mandate left this person on a page with no way to sign out');
})->group('security');

/**
 * Whether the page a person landed on can offer them a sign-out.
 *
 * The control lives in the account menu, and the menu is drawn from the shared AUTH prop
 * rather than from the navigation — deliberately, so that having no organization, and
 * therefore no nav areas, cannot take it away. A page that renders with `auth.user` null
 * falls through to the shell's frameless branch and has no chrome at all, which is exactly
 * the state this asserts against.
 *
 * The URL itself is built on the client, so it is not a prop to look for; that the menu
 * actually draws a sign-out is held in tests/Browser, which is the only place that can see
 * it.
 */
function hasWayOut(TestResponse $response): bool
{
    $auth = (array) $response->inertiaProps('auth');

    return ($auth['user'] ?? null) !== null;
}
