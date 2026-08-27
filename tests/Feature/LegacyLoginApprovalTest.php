<?php

declare(strict_types=1);

use App\Platform\EnvironmentSudo;
use App\Platform\Migration\LegacyLoginApprovals;
use Cbox\Id\AccessControl\Manifest\Manifest;
use Cbox\Id\AccessControl\ManifestSyncService;
use Cbox\Id\Migration\Contracts\LegacyCredentialSource;
use Cbox\Id\Migration\Models\LegacyLoginDeclarationRecord;
use Cbox\Id\Migration\ValueObjects\LegacyLoginDeclaration;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('cbox-id.migration.verify_url', false);
});

function declareLegacy(string $url = 'https://legacy.acme.test/verify'): void
{
    /*
     * The declaring app has to EXIST, because naming it is half of what the page is for:
     * "this came from Acme Web" is what an operator can judge, and a manifest always
     * arrives from something registered. The id is the registry's, not a literal — a
     * hand-written one syncs a declaration nothing on the page can name.
     */
    $clientId = app(ClientRegistry::class)->register(new NewClient('Acme Web'))->client->client_id;

    app(ManifestSyncService::class)->sync($clientId, new Manifest(
        version: 'v'.mt_rand(),
        permissions: [],
        roles: [],
        legacyLogin: new LegacyLoginDeclaration($url, str_repeat('s', 40)),
    ));
}

it('shows an operator what was declared and who declared it', function (): void {
    actAsEnvironmentAdminOfATenant();
    declareLegacy();

    // The step-up gates the PAGE on this plane, so it is opened to read it — the gate has
    // its own test at the bottom of this file.
    confirmEnvironmentStepUp();

    test()->get(route('environment.legacy-login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('console/legacy-login')
            ->where('declaration.url', 'https://legacy.acme.test/verify')
            ->where('declaration.approved', false)
            // NAMED, not shown as an id: "this came from Acme Web" is what an operator can
            // judge; a ULID is something they have to take on trust.
            ->where('declaration.declaredBy', 'Acme Web'));
});

/**
 * THE BINDING IS THE WHOLE FEATURE. Without it an operator could approve a declaration
 * and nothing would ever consult it — the screen would work and the migration would not.
 */
it('actually consults the endpoint once approved, and not before', function (): void {
    Http::fake(['*' => Http::response(['email' => 'ada@legacy.test', 'name' => 'Ada'], 200)]);

    // The environment first: a declaration belongs to one, and approving it from a console
    // pointed somewhere else would be approving a row that is not there.
    actAsEnvironmentAdminOfATenant();
    declareLegacy();

    $source = app(LegacyCredentialSource::class);

    expect($source->verify('ada@legacy.test', 'pw'))->toBeNull();
    Http::assertNothingSent();

    confirmEnvironmentStepUp();
    approveLegacyLogin()->assertSessionHasNoErrors();

    expect($source->verify('ada@legacy.test', 'pw')?->email)->toBe('ada@legacy.test');
});

it('withdraws it again, leaving the declaration visible', function (): void {
    actAsEnvironmentAdminOfATenant();
    declareLegacy();
    confirmEnvironmentStepUp();

    approveLegacyLogin()->assertSessionHasNoErrors();
    expect(LegacyLoginDeclarationRecord::query()->first()?->isApproved())->toBeTrue();

    test()->from(route('environment.legacy-login'))
        ->post(route('environment.legacy-login.revoke'))
        ->assertSessionHasNoErrors();

    // Withdrawn, not deleted: an operator who revokes during an incident should still be
    // able to see what they revoked.
    $record = LegacyLoginDeclarationRecord::query()->first();
    expect($record?->isApproved())->toBeFalse()
        ->and($record?->url)->toBe('https://legacy.acme.test/verify');
});

/**
 * "Who agreed to send passwords there" is the first question anybody asks afterwards.
 */
it('records who approved it, and keeps the original moment', function (): void {
    actAsEnvironmentAdminOfATenant();
    declareLegacy();
    confirmEnvironmentStepUp();

    approveLegacyLogin()->assertSessionHasNoErrors();
    $first = LegacyLoginDeclarationRecord::query()->first();

    expect($first?->approved_by)->not->toBeNull();

    // A second click must not overwrite when the URL joined the login path.
    app(LegacyLoginApprovals::class)->approve('somebody-else');

    expect(LegacyLoginDeclarationRecord::query()->first()?->approved_at?->toString())
        ->toBe($first?->approved_at?->toString());
});

it('refuses a member who may not administer', function (): void {
    // A deployment that SERVES the page first, then somebody acting as a tenant on it —
    // otherwise the refusal is the `/admin` prefix not existing, which is a different
    // answer to a different question.
    actAsEnvironmentAdminOfATenant();
    declareLegacy();
    actingAsRole(MembershipRole::Member);

    approveLegacyLogin()->assertRedirectContains('/open/');

    expect(LegacyLoginDeclarationRecord::query()->first()?->isApproved())->toBeFalse();
});

/**
 * WITHOUT THIS AN OPERATOR APPROVES BLIND. The first thing to discover that a declared
 * endpoint does not answer would be a real person's login — failing closed, so they simply
 * cannot sign in and nobody knows why.
 */
it('tests the endpoint before anybody approves it', function (): void {
    Http::fake(['*' => Http::response(['email' => 'ada@legacy.test', 'name' => 'Ada'], 200)]);

    actAsEnvironmentAdminOfATenant();
    declareLegacy();

    confirmEnvironmentStepUp();
    probeLegacyLogin('ada@legacy.test')->assertSessionHasNoErrors();

    // Read BEFORE the page loads, because loading it is what spends the flash.
    $result = session()->get(SessionKey::FLASH_DATA)['probeResult'] ?? '';

    expect($result)->toContain('The integration works');

    // …and the SAME sentence reaches the page. It never becomes a prop: it is an answer
    // about somebody's account at another system, and props are written into the browser's
    // history entry.
    test()->get(route('environment.legacy-login'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->hasFlash('probeResult', $result));

    // Explicitly still unapproved: the probe must not be a back door to enabling it.
    expect(LegacyLoginDeclarationRecord::query()->first()?->isApproved())->toBeFalse();
});

/**
 * `find()` asks whether an address is known and nothing more. A probe that took a password
 * would be a credential-testing oracle with an approve button beside it.
 */
it('never sends a password when testing', function (): void {
    Http::fake(['*' => Http::response(['email' => 'ada@legacy.test'], 200)]);

    actAsEnvironmentAdminOfATenant();
    declareLegacy();

    confirmEnvironmentStepUp();
    probeLegacyLogin('ada@legacy.test');

    Http::assertSent(function ($request): bool {
        return ! str_contains((string) $request->body(), 'password');
    });
});

/**
 * Each failure needs a different action from the operator, so each gets its own sentence.
 * A boolean would send them to the logs to find out which.
 */
it('says what went wrong rather than just failing', function (): void {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    actAsEnvironmentAdminOfATenant();
    declareLegacy();

    confirmEnvironmentStepUp();
    probeLegacyLogin('ada@legacy.test');

    expect(session()->get(SessionKey::FLASH_DATA)['probeResult'] ?? '')
        ->toContain('No answer for that address');
});

it('refuses to probe with something that is not an address', function (): void {
    actAsEnvironmentAdminOfATenant();
    declareLegacy();

    confirmEnvironmentStepUp();

    // On the FIELD, because it is the field that is wrong — and the sentence says what to
    // put there rather than "invalid email".
    probeLegacyLogin('not-an-address')
        ->assertSessionHasErrors(['email' => 'Enter an address that exists in your old system.']);
});

/**
 * THE STEP-UP IS PART OF THE CONTROL, not decoration on the route.
 *
 * A window opened while the confirmation was fresh must not still be able to redirect where
 * passwords go an hour later — which is exactly the shape a hijacked or clickjacked session
 * has. So the write asks again, in its own right, rather than trusting that the page load
 * that produced the button was gated.
 */
it('refuses to approve from a session that has not re-confirmed', function (): void {
    actAsEnvironmentAdminOfATenant();
    declareLegacy();

    // Deliberately no confirmEnvironmentStepUp().
    approveLegacyLogin()->assertRedirect(route('environment.sudo'));

    expect(LegacyLoginDeclarationRecord::query()->first()?->isApproved())->toBeFalse();

    // …and withdrawing is gated the same way: silently turning the migration OFF locks
    // every un-migrated person out, which is an outage an attacker would choose the
    // timing of.
    confirmEnvironmentStepUp();
    approveLegacyLogin()->assertSessionHasNoErrors();
    session()->forget(EnvironmentSudo::SESSION_KEY);

    test()->from(route('environment.legacy-login'))
        ->post(route('environment.legacy-login.revoke'))
        ->assertRedirect(route('environment.sudo'));

    expect(LegacyLoginDeclarationRecord::query()->first()?->isApproved())->toBeTrue();
});

/**
 * THE PAGE IS NOT ON THE ORGANIZATION PLANE, and this is why.
 *
 * The declaration is environment-wide — one row, `environment_id` unique, no organization
 * column. On the organization plane "may this administrator change this" resolves to a
 * membership role, so every organization admin in the environment could approve where the
 * WHOLE environment's un-migrated passwords are sent, including other tenants' users.
 */
it('cannot be reached by an organization administrator at all', function (): void {
    // A deployment that serves the environment plane, then somebody acting as a tenant on
    // it — otherwise the refusal is the `/admin` prefix not existing.
    actAsEnvironmentAdminOfATenant();
    declareLegacy();
    confirmEnvironmentStepUp();
    actingAsRole(MembershipRole::Owner);

    approveLegacyLogin()->assertRedirectContains('/open/');

    expect(LegacyLoginDeclarationRecord::query()->first()?->isApproved())->toBeFalse()
        // And the page is absent rather than present-and-403: a route that exists and
        // refuses reads like a permissions problem somebody will try to fix.
        ->and(Route::has('legacy-login'))->toBeFalse();
});

/**
 * OVER HTTP, THROUGH THE REAL ROUTE — which is the thing every other test in this file
 * cannot see.
 *
 * `Volt::test()` instantiates the component directly and runs none of the route's
 * middleware, so a page can be gated on a step-up its own administrator has no way to
 * satisfy and every test here stays green. That is exactly what happened: the page was
 * moved to the environment plane and gated on the ORGANIZATION plane's `sudo`, which sends
 * the admin to `/sudo`, which resolves the subject under the ambient tenant scope, finds
 * nothing, and bounces to the tenant end-user login. No path back, and nothing red.
 */
it('opens for an environment administrator who has confirmed the environment step-up', function (): void {
    multiTenantDeployment();
    actAsEnvironmentAdminOfATenant();
    declareLegacy();
    confirmEnvironmentStepUp();

    $this->get(route('environment.legacy-login'))
        ->assertOk()
        ->assertSee('legacy.acme.test');
});

it('asks for the environment step-up rather than one the admin cannot give', function (): void {
    multiTenantDeployment();
    actAsEnvironmentAdminOfATenant();
    declareLegacy();

    // No confirmation. The redirect must be to the ENVIRONMENT step-up, not the
    // organization one — the organization one is a door this person cannot open.
    $this->get(route('environment.legacy-login'))
        ->assertRedirect(route('environment.sudo'));
});
