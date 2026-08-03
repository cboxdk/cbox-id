<?php

declare(strict_types=1);

use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The seam that replaced thirteen pairs of components answering the same three questions
 * differently. Everything here is a behaviour one of those pairs got wrong.
 */
function scope(): ConsoleScope
{
    return app(ConsoleScope::class);
}

function anOrganization(string $slug, string $name = 'Acme'): string
{
    return app(Organizations::class)->create(new NewOrganization($name, $slug))->id;
}

/**
 * A REAL environment-admin session. EnvironmentAdminAuth resolves the account member on
 * every read and refuses a session whose subject is not one, so a hand-written session
 * key proves nothing — it just fails closed, which is the correct behaviour and a
 * useless fixture.
 */
function actAsRealEnvironmentAdmin(string $email = 'env-owner@acme.example'): void
{
    platformRootEnvironment();

    $provisioned = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: $email,
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($provisioned->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($provisioned->environment->id));
    actAsEnvironmentAdmin($provisioned->member, $provisioned->environment->id);
}

it('reads the organization from the membership on the organization plane', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    expect(scope()->plane())->toBe(ConsolePlane::Organization)
        ->and(scope()->organizationId())->toBe($org->id);
});

it('refuses to let an organization member choose a different organization', function (): void {
    // The authorization the plane exists to withhold. If a member could set this, they
    // would be picking which organization to administer.
    actingAsRole(MembershipRole::Owner);
    $other = anOrganization('somebody-else', 'Somebody Else');

    expect(fn () => scope()->chooseOrganization($other))
        ->toThrow(AuthorizationException::class);
})->group('security');

it('lets the subject session win when a browser holds both', function (): void {
    // The two session stores are independent and a browser can hold both — an account
    // member who also has a subject account here. If the environment plane won that tie,
    // an ordinary member with a stale account session would silently gain the ability to
    // act on every organization in the environment.
    //
    // BOTH sessions have to be genuinely valid or there is no tie to break. The first
    // version of this test wrote raw session keys that never resolved to an account
    // member, so EnvironmentAdminAuth::check() was false either way and the test passed
    // with the tie-break inverted.
    actAsRealEnvironmentAdmin();
    expect(app(EnvironmentAdminAuth::class)->check())->toBeTrue();

    $orgId = anOrganization('tie-break', 'Tie Break');
    $subject = app(Subjects::class)->create('member@tie.test', 'Member', 'a-strong-unbreached-passphrase');
    app(Memberships::class)->add($orgId, $subject->id, MembershipRole::Member);
    $session = app(SessionManager::class)->start($subject->id, $orgId, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, Organization::query()->find($orgId), MembershipRole::Member);

    // Now both are live. The subject must win, and must see only their own organization.
    expect(scope()->plane())->toBe(ConsolePlane::Organization)
        ->and(scope()->organizationId())->toBe($orgId)
        ->and(array_keys(scope()->availableOrganizations()))->toBe([$orgId])
        // And they must not be able to reach across the environment.
        ->and(fn () => scope()->chooseOrganization($orgId))->toThrow(AuthorizationException::class);
})->group('security');

it('has no organization on the environment plane until one is chosen', function (): void {
    anOrganization('acme-env');
    actAsRealEnvironmentAdmin();

    expect(scope()->plane())->toBe(ConsolePlane::Environment)
        ->and(scope()->organizationId())->toBeNull();
});

it('refuses a write before an organization is chosen', function (): void {
    // Not merely null: a write attempted with nothing resolved would land in whichever
    // organization a downstream default picked.
    actAsRealEnvironmentAdmin();

    expect(fn () => scope()->requireOrganizationId())
        ->toThrow(AuthorizationException::class, 'Choose an organization');
})->group('security');

it('acts on the organization an environment admin chose', function (): void {
    actAsRealEnvironmentAdmin();
    $orgId = anOrganization('acme-env');

    scope()->chooseOrganization($orgId);

    expect(scope()->organizationId())->toBe($orgId)
        ->and(scope()->requireOrganizationId())->toBe($orgId);
});

it('refuses an organization that is not in this environment', function (): void {
    actAsRealEnvironmentAdmin();

    expect(fn () => scope()->chooseOrganization('01JQZZZZZZZZZZZZZZZZZZZZZZ'))
        ->toThrow(AuthorizationException::class, 'not in this environment');
})->group('security');

it('re-validates the chosen organization on every read', function (): void {
    // Not trusted because it was valid when chosen. A session carried to a different
    // host must not act on the organization it names — the environment scope on the
    // model is what makes that true, and this is what consults it.
    actAsRealEnvironmentAdmin();
    $orgId = anOrganization('acme-env');
    scope()->chooseOrganization($orgId);

    expect(scope()->organizationId())->toBe($orgId);

    Organization::query()->whereKey($orgId)->delete();

    expect(scope()->organizationId())->toBeNull();
})->group('security');

it('enforces entitlements on the organization plane', function (): void {
    actingAsRole(MembershipRole::Owner);

    // OpenEntitlements is bound by default, so this passes today. The test states the
    // contract rather than the current binding.
    expect(scope()->entitled('sso'))->toBeTrue();
});

it('enforces entitlements on the environment plane too', function (): void {
    // The hole this closed: fifteen guardEntitled() calls lived on the organization
    // plane and none on the environment plane, so switching consoles walked past the
    // gate entirely. An entitlement belongs to the organization, not to the door.
    actAsRealEnvironmentAdmin();
    $orgId = anOrganization('acme-env');
    scope()->chooseOrganization($orgId);

    expect(scope()->entitled('sso'))->toBeTrue();

    // And with nothing chosen there is no organization to be entitled, so it refuses
    // rather than defaulting open.
    session()->forget(ConsoleScope::SELECTION_KEY);

    expect(scope()->entitled('sso'))->toBeFalse();
})->group('security');

it('treats a non-admin member as unable to change things', function (): void {
    actingAsRole(MembershipRole::Member);

    expect(scope()->mayAdminister())->toBeFalse()
        ->and(fn () => scope()->assertMayAdminister())->toThrow(AuthorizationException::class);
})->group('security');

it('treats an environment admin as able to change things', function (): void {
    actAsRealEnvironmentAdmin();

    expect(scope()->mayAdminister())->toBeTrue();
});

it('offers only the member own organization on the organization plane', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    anOrganization('not-theirs', 'Not Theirs');

    expect(array_keys(scope()->availableOrganizations()))->toBe([$org->id]);
})->group('security');
