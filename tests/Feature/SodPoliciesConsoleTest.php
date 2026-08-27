<?php

declare(strict_types=1);

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use Cbox\Id\AccessControl\Contracts\Roles;
use Cbox\Id\Governance\Contracts\SegregationOfDuties;
use Cbox\Id\Governance\Models\SodPolicy;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

function sodAdmin(MembershipRole $role = MembershipRole::Owner): string
{
    $subject = app(Subjects::class)->create('sod@acme.test', 'Sod Admin', 'supersecret123');
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-sod'));
    app(Memberships::class)->add($org->id, $subject->id, $role);
    $session = app(SessionManager::class)->start($subject->id, $org->id, ['pwd']);
    app(CurrentUser::class)->set($subject, $session, $org, $role);

    // AND THE SESSION KEY THE CONSOLE'S GUARD READS ON THE WAY IN — without it every
    // request below answers a redirect to /login rather than the page under test.
    session([PlatformAuth::SESSION_KEY => $session->id]);

    return $org->id;
}

it('defines a policy over two roles', function (): void {
    $orgId = sodAdmin();
    $a = app(Roles::class)->define($orgId, 'create-po');
    $b = app(Roles::class)->define($orgId, 'approve-pay');

    // One component on both planes now, and the routable list → new → detail shape won
    // over this plane's single page — so defining happens on the create page.
    defineRoleConflict(['roles' => [$a->id, $b->id]])->assertSessionHasNoErrors();

    $policy = SodPolicy::query()->where('organization_id', $orgId)->firstOrFail();
    expect($policy->name)->toBe('PO vs pay');
    expect($policy->role_ids)->toEqualCanonicalizing([$a->id, $b->id]);
});

/**
 * The rule is written down AFTER the two roles are already held, which from laravel-id
 * 0.71.0 is the only way a violation can come into existence — the preventive gate now
 * runs inside RoleService::assign(), so the framework refuses to create one. It is also
 * how this happens in practice: someone writes a rule about a combination people have.
 *
 * That is exactly why the detective scan still has a job, and why this page still needs
 * to render a violation.
 */
it('detects a violation', function (): void {
    $orgId = sodAdmin();
    $a = app(Roles::class)->define($orgId, 'create-po');
    $b = app(Roles::class)->define($orgId, 'approve-pay');

    app(Roles::class)->assign($orgId, 'user-1', $a->id);
    app(Roles::class)->assign($orgId, 'user-1', $b->id);

    app(SegregationOfDuties::class)->definePolicy($orgId, 'PO vs pay', [$a->id, $b->id]);

    expect(app(SegregationOfDuties::class)->scan($orgId))->toHaveCount(1);

    // NAMED, not a bare id. The whole output of the detective half is a list of people
    // somebody has to go and talk to, and the page printed a ULID.
    test()->get(route('sod-policies'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'violations',
            fn (Collection $found): bool => $found->pluck('subjectId')->all() === ['user-1'],
        ));
});

it('refuses to define a rule over fewer than two roles', function (): void {
    $orgId = sodAdmin();
    $a = app(Roles::class)->define($orgId, 'create-po');

    // A "mutually exclusive set" of one conflicts with nothing. It would sit in the list
    // looking like a control while blocking no grant at all — worse than no rule, because
    // somebody wrote it down and believes it is working.
    defineRoleConflict(['roles' => [$a->id]])->assertSessionHasErrors('roles');
    defineRoleConflict(['roles' => []])->assertSessionHasErrors('roles');

    expect(SodPolicy::query()->exists())->toBeFalse();
});

it('forbids a non-admin member', function (): void {
    sodAdmin(MembershipRole::Member);

    test()->get(route('sod-policies'))->assertForbidden();
});
