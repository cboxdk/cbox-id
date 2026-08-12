<?php

declare(strict_types=1);

use App\Platform\Migration\LegacyLoginApprovals;
use Cbox\Id\AccessControl\Manifest\Manifest;
use Cbox\Id\AccessControl\ManifestSyncService;
use Cbox\Id\Migration\Contracts\LegacyCredentialSource;
use Cbox\Id\Migration\Models\LegacyLoginDeclarationRecord;
use Cbox\Id\Migration\ValueObjects\LegacyLoginDeclaration;
use Cbox\Id\Organization\Enums\MembershipRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('cbox-id.migration.verify_url', false);
});

function declareLegacy(string $url = 'https://legacy.acme.test/verify'): void
{
    app(ManifestSyncService::class)->sync('client-a', new Manifest(
        version: 'v'.mt_rand(),
        permissions: [],
        roles: [],
        legacyLogin: new LegacyLoginDeclaration($url, str_repeat('s', 40)),
    ));
}

it('shows an operator what was declared and who declared it', function (): void {
    actAsEnvironmentAdminOfATenant();
    declareLegacy();

    Volt::test('console.legacy-login')
        ->assertSee('legacy.acme.test')
        ->assertSee('Awaiting approval');
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

    confirmStepUp();
    Volt::test('console.legacy-login')->call('approve');

    expect($source->verify('ada@legacy.test', 'pw')?->email)->toBe('ada@legacy.test');
});

it('withdraws it again, leaving the declaration visible', function (): void {
    actAsEnvironmentAdminOfATenant();
    declareLegacy();
    confirmStepUp();

    Volt::test('console.legacy-login')->call('approve');
    expect(LegacyLoginDeclarationRecord::query()->first()?->isApproved())->toBeTrue();

    Volt::test('console.legacy-login')->call('revoke');

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
    confirmStepUp();

    Volt::test('console.legacy-login')->call('approve');
    $first = LegacyLoginDeclarationRecord::query()->first();

    expect($first?->approved_by)->not->toBeNull();

    // A second click must not overwrite when the URL joined the login path.
    app(LegacyLoginApprovals::class)->approve('somebody-else');

    expect(LegacyLoginDeclarationRecord::query()->first()?->approved_at?->toString())
        ->toBe($first?->approved_at?->toString());
});

it('refuses a member who may not administer', function (): void {
    actingAsRole(MembershipRole::Member);
    declareLegacy();

    try {
        Volt::test('console.legacy-login')->call('approve');
    } catch (Throwable) {
        // Either the authorization exception or Livewire's report of it.
    }

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

    Volt::test('console.legacy-login')
        ->set('probeEmail', 'ada@legacy.test')
        ->call('probe')
        ->assertSee('The integration works');

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

    Volt::test('console.legacy-login')->set('probeEmail', 'ada@legacy.test')->call('probe');

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

    Volt::test('console.legacy-login')
        ->set('probeEmail', 'ada@legacy.test')
        ->call('probe')
        ->assertSee('No answer for that address');
});

it('refuses to probe with something that is not an address', function (): void {
    actAsEnvironmentAdminOfATenant();
    declareLegacy();

    Volt::test('console.legacy-login')
        ->set('probeEmail', 'not-an-address')
        ->call('probe')
        ->assertSee('Enter an address');
});

/**
 * THE STEP-UP IS PART OF THE CONTROL, not decoration on the route.
 *
 * Route middleware runs on the first page load; every Livewire action after it is a POST to
 * the component endpoint. A console window opened while the confirmation was fresh could
 * otherwise still redirect where passwords go an hour later — which is exactly the shape a
 * hijacked or clickjacked session has.
 */
it('refuses to approve from a session that has not re-confirmed', function (): void {
    actAsEnvironmentAdminOfATenant();
    declareLegacy();

    // Deliberately no confirmStepUp().
    try {
        Volt::test('console.legacy-login')->call('approve');
    } catch (Throwable) {
        // Either the authorization exception or Livewire's report of it.
    }

    expect(LegacyLoginDeclarationRecord::query()->first()?->isApproved())->toBeFalse();
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
    actingAsRole(MembershipRole::Owner);
    declareLegacy();
    confirmStepUp();

    try {
        Volt::test('console.legacy-login')->call('approve');
    } catch (Throwable) {
        // The scope refuses on this plane before the action runs.
    }

    expect(LegacyLoginDeclarationRecord::query()->first()?->isApproved())->toBeFalse()
        // And the page is absent rather than present-and-403: a route that exists and
        // refuses reads like a permissions problem somebody will try to fix.
        ->and(Route::has('legacy-login'))->toBeFalse();
});
