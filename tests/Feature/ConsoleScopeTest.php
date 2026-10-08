<?php

declare(strict_types=1);

use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Membership;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
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

    $provisioned = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: $email,
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($provisioned->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($provisioned->environment->id));
    actAsEnvironmentAdmin($provisioned->owner->id, $provisioned->environment->id);
}

it('reads the organization from the membership on the organization plane', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    expect(scope()->plane())->toBe(ConsolePlane::Organization)
        ->and(scope()->organizationId())->toBe($org->id);
});

it('refuses to let an organization member bind a different organization', function (): void {
    // The authorization the plane exists to withhold. If a member could set this, they
    // would be picking which organization to administer.
    actingAsRole(MembershipRole::Owner);
    $other = anOrganization('somebody-else', 'Somebody Else');

    expect(fn () => scope()->bindOrganization($other))
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
        ->and(fn () => scope()->bindOrganization($orgId))->toThrow(AuthorizationException::class);
})->group('security');

it('has no organization on the environment plane unless the URL names one', function (): void {
    anOrganization('acme-env');
    actAsRealEnvironmentAdmin();

    expect(scope()->plane())->toBe(ConsolePlane::Environment)
        ->and(scope()->organizationId())->toBeNull();
});

it('refuses a write that names no organization', function (): void {
    // Not merely null: a write attempted with nothing resolved would land in whichever
    // organization a downstream default picked.
    actAsRealEnvironmentAdmin();

    expect(fn () => scope()->requireOrganizationId())
        ->toThrow(AuthorizationException::class, 'none is named here');
})->group('security');

it('acts on the organization the request names', function (): void {
    actAsRealEnvironmentAdmin();
    $orgId = anOrganization('acme-env');

    expect(scope()->bindOrganization($orgId))->toBeTrue()
        ->and(scope()->organizationId())->toBe($orgId)
        ->and(scope()->requireOrganizationId())->toBe($orgId);
});

it('refuses an organization that is not in this environment', function (): void {
    actAsRealEnvironmentAdmin();

    expect(scope()->bindOrganization('01JQZZZZZZZZZZZZZZZZZZZZZZ'))->toBeFalse()
        ->and(scope()->organizationId())->toBeNull();
})->group('security');

it('remembers no organization from one request to the next', function (): void {
    // The "acting organization" this replaced lived in the session, so a choice made once
    // narrowed every page after it. A binding is THIS request's: the next one starts with
    // none, and only its own URL can name one.
    actAsRealEnvironmentAdmin();
    $orgId = anOrganization('acme-env');
    scope()->bindOrganization($orgId);

    expect(scope()->organizationId())->toBe($orgId);

    // The next request, modelled by dropping THIS object.
    app()->forgetInstance(ConsoleScope::class);

    expect(scope()->organizationId())->toBeNull();

    // …and a process that keeps the object (a long-lived worker) has it released at the end.
    scope()->bindOrganization($orgId);
    scope()->releaseOrganization();

    expect(scope()->organizationId())->toBeNull();
})->group('security');

it('does not carry a bound organization into another environment', function (): void {
    // The property the re-validation above exists FOR, asserted directly rather than
    // demonstrated by deleting a row. The session cookie is shared across `*.cboxid.com`,
    // so a selection made on one environment's host travels to the next one; what must not
    // travel is the AUTHORITY to act on it. The environment scope on Organization answers
    // that — the id simply is not there — and this holds WITHIN a request, because the
    // environment can legitimately change inside one ({@see EnvironmentContext::runAs()})
    // and a memo that ignored it would answer for the environment we just left.
    actAsRealEnvironmentAdmin();
    $orgId = anOrganization('acme-env');
    scope()->bindOrganization($orgId);

    expect(scope()->organizationId())->toBe($orgId);

    $elsewhere = Environment::query()->create([
        'name' => 'Elsewhere', 'slug' => 'scope-elsewhere', 'status' => 'active', 'is_default' => false,
    ]);

    app(EnvironmentContext::class)->runAs(GenericEnvironment::of($elsewhere->id), function (): void {
        expect(scope()->organizationId())->toBeNull();
    });

    // …and coming back is not a downgrade: the selection is still this environment's.
    expect(scope()->organizationId())->toBe($orgId);
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
    scope()->bindOrganization($orgId);

    expect(scope()->entitled('sso'))->toBeTrue();

    // And with none named there is no organization to be entitled, so it refuses rather
    // than defaulting open.
    scope()->releaseOrganization();

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

it('attributes to the subject on both planes, not to two id spaces', function (): void {
    // The two consoles disagreed. The organization plane recorded the subject id; the
    // environment plane recorded the Membership row id — a different table. So an
    // access-review certification was attributed to one id space or the other depending
    // which console the reviewer used, in the one feature whose entire output is a trail
    // somebody later has to read.
    [$subjectId] = actingAsRole(MembershipRole::Owner);

    expect(scope()->actorId())->toBe($subjectId);
})->group('security');

it('attributes an environment admin to their subject too', function (): void {
    actAsRealEnvironmentAdmin();

    $actor = scope()->actorId();

    // Specifically NOT the Membership row id, which is what this plane used to record
    // and which lives in a different table from every id the other plane wrote.
    expect($actor)->not->toBe('')
        ->and(Membership::query()->whereKey($actor)->exists())->toBeFalse();

    // It IS a real subject — of the PLATFORM ROOT, where account members live, not of the
    // environment being administered. Worth stating: an environment administrator is not
    // a member of the tenant they are acting on, so a tenant reading its own trail sees a
    // principal from outside it either way. The fix is that there is now ONE id space,
    // not that the id became local.
    $root = app(PlatformRoot::class)->environment();
    $exists = app(EnvironmentContext::class)->runAs(
        $root,
        static fn (): bool => User::query()->whereKey($actor)->exists(),
    );

    expect($exists)->toBeTrue();
})->group('security');
