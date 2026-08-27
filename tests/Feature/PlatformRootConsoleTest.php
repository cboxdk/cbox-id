<?php

declare(strict_types=1);

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * The platform root is a tenant, and its console has to be a place people can actually
 * arrive at.
 *
 * `tests/Feature/IdpSurfaceBulkheadTest.php` proves the ROUTES exist there now. That is
 * only half of it: a door that opens onto a 403, or onto a redirect that comes back to
 * itself, is a door in the sense that a wall is. The account plane learned this the
 * expensive way — `workspace.no-access` exists because a signed-in member with no
 * membership met a 403 rendered on a layout with no navigation and therefore no sign-out
 * control, and the fix for that was itself an infinite redirect for a while.
 *
 * The persona is not an edge case. Every subject in the platform root today holds zero
 * organization memberships: account members are subjects of the root, and the account
 * relationship is not an organization membership. So "signs in at the root and belongs to
 * no organization" is the COMMON case on this host, not the unusual one.
 */

/** The SaaS shape: a platform root at cboxid.com, plus one tenant environment. */
function rootConsoleShape(): Environment
{
    multiTenantDeployment();
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);

    Environment::query()->create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active', 'is_default' => false]);

    $root = Environment::query()->create(['name' => 'Platform', 'slug' => 'platform', 'status' => 'active']);
    $root->makeDefault();

    return $root;
}

/**
 * Sign a subject of the PLATFORM ROOT into the browser, the way the door does.
 *
 * Created inside the root's scope on purpose: the environment scope is deny-by-default, so
 * a subject seeded under the ambient test environment would simply not be there when the
 * request resolves to the root and the session would fail to resolve — a green test
 * asserting nothing.
 */
function actAsRootSubject(?string $organizationId = null): string
{
    return app(PlatformRoot::class)->run(function () use ($organizationId): string {
        $subject = app(Subjects::class)->create('root-subject@cboxid.example', 'Root Subject', 'a-strong-unbreached-passphrase');
        app(Subjects::class)->markEmailVerified($subject->id, (string) $subject->email);

        $session = app(SessionManager::class)->start($subject->id, $organizationId, ['pwd']);

        test()->withSession([PlatformAuth::SESSION_KEY => $session->id]);

        return $subject->id;
    });
}

/**
 * Follow the redirect chain to wherever it comes to rest, and refuse to call a cycle a
 * landing.
 *
 * A single-hop `assertRedirect` cannot see a loop, and a loop is the specific failure this
 * whole file is about: the console's answer to "you hold nothing here" is a redirect, and
 * a redirect whose destination redirects back is what ships when only the first hop was
 * ever asserted.
 *
 * `nextRequest()` between hops because ConsoleScope memoises per request — without it the
 * second hop is answered with the first hop's authority, which is not what a browser does.
 */
function rootConsoleLanding(string $url, int $hops = 4): TestResponse
{
    $response = test()->get($url);
    $chain = [$url];

    for ($hop = 0; $hop < $hops && $response->isRedirect(); $hop++) {
        $location = (string) $response->headers->get('Location');
        $chain[] = $location;

        nextRequest();
        $response = test()->get($location);
    }

    expect($response->isRedirect())->toBeFalse(
        'still redirecting after '.$hops.' hops: '.implode(' -> ', $chain),
    );

    return $response;
}

/**
 * The common case, and the one that decides whether this host is habitable: a subject of
 * the root with no organization.
 *
 * FOLLOWED, not asserted one hop at a time. `ConsoleScope::organizationId()` throws an
 * AuthorizationException for exactly this person — that is deliberate, it is what stops a
 * member whose membership went away from being handed an unfiltered query — so the only
 * thing that separates a landing from a 403 or a cycle is what the console does with it,
 * and a single-hop `assertRedirect` cannot see either.
 */
it('lands a root subject who belongs to no organization on something real', function (): void {
    rootConsoleShape();
    actAsRootSubject();

    $response = rootConsoleLanding('http://cboxid.com/dashboard');

    $response->assertSuccessful();

    /*
     * A LANDING, NOT A SHELL. It says something true about a person who holds no membership
     * rather than the old fallback — "here's what's happening across your organization" —
     * which described something they do not have, and it offers the things that ARE theirs.
     *
     * Asserted on the props the page is built from. The sentence is one of them; the
     * controls beside it — "Manage security", the sign-out in the account menu — are drawn
     * by the components from `isAdmin` and the shared `auth`, and tests/Browser is where
     * their being DRAWN is held.
     */
    $props = $response->assertOk();

    expect((string) $props->inertiaProps('greeting'))->toContain('belong to an organization here yet')
        ->and($props->inertiaProps('isAdmin'))->toBeFalse()
        ->and($props->inertiaProps('organizationName'))->toBeNull()
        // The two things that are theirs: their own security page, and — through the
        // shared auth prop the account menu reads — a way out.
        ->and((array) $props->inertiaProps('urls'))->toHaveKey('account')
        ->and($props->inertiaProps('auth.user'))->not->toBeNull();
});

/**
 * And the ordinary case: a member of an organization IN the root environment gets the
 * ordinary console, scoped to that organization.
 *
 * The root has organizations — an account's own org lives there, which is what home-realm
 * discovery on `/login` resolves against — so this is not hypothetical either.
 */
it('serves the ordinary console to a member of an organization in the root environment', function (): void {
    rootConsoleShape();

    $organizationId = app(PlatformRoot::class)->run(
        fn (): string => app(Organizations::class)->create(new NewOrganization('Root Co', 'root-co'))->id,
    );

    $subjectId = actAsRootSubject($organizationId);

    app(PlatformRoot::class)->run(function () use ($organizationId, $subjectId): void {
        app(Memberships::class)->add($organizationId, $subjectId, MembershipRole::Admin);
    });

    rootConsoleLanding('http://cboxid.com/dashboard')
        ->assertSuccessful()
        ->assertSee('Root Co', false);
});

/**
 * The self-service pages are the point of having a console here at all.
 *
 * "user / subject — myself: profile, password, sessions. Always." An account member who
 * wants to rotate the password on the identity they actually sign in with had no page on
 * this host to do it from.
 */
it('serves the subject their own account page on the platform root', function (): void {
    rootConsoleShape();
    actAsRootSubject();

    rootConsoleLanding('http://cboxid.com/account')->assertSuccessful();
});
