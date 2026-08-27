<?php

declare(strict_types=1);

use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\MfaFactor;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Testing\InteractsWithTenancy;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Models\PlatformOperator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

uses(InteractsWithTenancy::class);

/** Sign a fresh operator into the session — reads are pinned to the default plane. */
function usageOperatorSignIn(string $email = 'usage-op@platform.test'): PlatformOperator
{
    return actAsOperator($email);
}

/** A real environment row so a plane can be resolved to a human label. */
function usagePlane(string $name, string $slug): Environment
{
    return Environment::query()->create(['name' => $name, 'slug' => $slug, 'status' => 'active']);
}

/** An active (non-revoked, future-expiry) session for a user in the current plane. */
function activeSessionFor(string $userId): void
{
    Session::query()->create([
        'user_id' => $userId,
        'amr' => ['pwd'],
        'expires_at' => Carbon::now()->addDay(),
    ]);
}

/*
|--------------------------------------------------------------------------
| Platform usage dashboard — cross-plane aggregation.
|--------------------------------------------------------------------------
*/

it('sums headline totals and breaks them down per environment across every plane', function (): void {
    $planeA = usagePlane('Plane A', 'plane-a');
    $planeB = usagePlane('Plane B', 'plane-b');

    // Plane A: two orgs, two users, one ACTIVE + one REVOKED session.
    $this->runAsEnvironment($planeA, function (): void {
        $orgA1 = app(Organizations::class)->create(new NewOrganization('Acme A', 'acme-a'));
        app(Organizations::class)->create(new NewOrganization('Beta A', 'beta-a'));

        $uA1 = app(Subjects::class)->create('a1@acme.test', 'A One', 'supersecret123');
        $uA2 = app(Subjects::class)->create('a2@acme.test', 'A Two', 'supersecret123');
        app(Memberships::class)->add($orgA1->id, $uA1->id, MembershipRole::Owner);

        activeSessionFor($uA1->id);
        // A revoked session must NOT count towards "active".
        Session::query()->create([
            'user_id' => $uA2->id,
            'amr' => ['pwd'],
            'expires_at' => Carbon::now()->addDay(),
            'revoked_at' => Carbon::now(),
        ]);
    });

    // Plane B: one org, one user, one active session.
    $this->runAsEnvironment($planeB, function (): void {
        $orgB1 = app(Organizations::class)->create(new NewOrganization('Gamma B', 'gamma-b'));
        $uB1 = app(Subjects::class)->create('b1@gamma.test', 'B One', 'supersecret123');
        app(Memberships::class)->add($orgB1->id, $uB1->id, MembershipRole::Owner);
        activeSessionFor($uB1->id);
    });

    usageOperatorSignIn();

    $page = test()->get(route('platform.usage'))->assertOk();

    $page
        // Totals sum across EVERY plane (proving EnvironmentContext::withoutScope) —
        // including the PLATFORM ROOT, which is a plane like any other and now holds the
        // operator themselves: a subject with a live session, because an operator is a
        // person who signed in rather than a row in a second credential table. Three
        // environments, four users, three active sessions; the two tenant planes below
        // are unchanged.
        ->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->where('totals', fn (Collection $t): bool => $t['environments'] === 3
                && $t['organizations'] === 3
                && $t['users'] === 4
                && $t['sessions'] === 3)
            // Per-environment breakdown shows each plane's own counts.
            ->where('breakdown', function (Collection $rows): bool {
                $byName = $rows->keyBy('name');
                $a = $byName->get('Plane A');
                $b = $byName->get('Plane B');

                return $a !== null && $a['organizations'] === 2 && $a['users'] === 2 && $a['sessions'] === 1
                    && $b !== null && $b['organizations'] === 1 && $b['users'] === 1 && $b['sessions'] === 1;
            })
            // Both planes are NAMED, or the counts above could be describing a table with
            // no rows in it.
            ->where('breakdown', fn (Collection $rows): bool => $rows->pluck('name')->contains('Plane A'))
            ->where('breakdown', fn (Collection $rows): bool => $rows->pluck('name')->contains('Plane B')));
});

it('ranks top organizations by member count across planes, each linking to its plane', function (): void {
    $planeA = usagePlane('Plane A', 'plane-a');
    $planeB = usagePlane('Plane B', 'plane-b');

    $orgA1Id = $this->runAsEnvironment($planeA, function (): string {
        $orgA1 = app(Organizations::class)->create(new NewOrganization('Acme A', 'acme-a'));
        $u1 = app(Subjects::class)->create('m1@acme.test', 'M1', 'supersecret123');
        $u2 = app(Subjects::class)->create('m2@acme.test', 'M2', 'supersecret123');
        app(Memberships::class)->add($orgA1->id, $u1->id, MembershipRole::Owner);
        app(Memberships::class)->add($orgA1->id, $u2->id, MembershipRole::Member);

        return $orgA1->id;
    });

    $orgB1Id = $this->runAsEnvironment($planeB, function (): string {
        $orgB1 = app(Organizations::class)->create(new NewOrganization('Gamma B', 'gamma-b'));
        $u3 = app(Subjects::class)->create('m3@gamma.test', 'M3', 'supersecret123');
        app(Memberships::class)->add($orgB1->id, $u3->id, MembershipRole::Owner);

        return $orgB1->id;
    });

    usageOperatorSignIn();

    test()->get(route('platform.usage'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('topOrganizations', function (Collection $rows) use ($orgA1Id, $orgB1Id): bool {
                $byId = $rows->keyBy('id');
                $a = $byId->get($orgA1Id);
                $b = $byId->get($orgB1Id);

                // Cross-plane roll-up: both orgs present, correct member counts and planes,
                // and the most-members org ranks first.
                return $a !== null && $a['members'] === 2 && $a['plane'] === 'Plane A'
                    && $b !== null && $b['members'] === 1 && $b['plane'] === 'Plane B'
                    && $rows->first()['id'] === $orgA1Id;
            })
            // Each row carries the cross-plane JUMP route rather than the detail page: the
            // tenant lives in another plane, and opening its page without re-pointing the
            // console first is how a plane-scoped page 404s on a row that exists.
            ->where('topOrganizations', fn (Collection $rows): bool => $rows
                ->firstWhere('id', $orgA1Id)['href'] === route('platform.search.jump', $orgA1Id)));
});

it('refuses a non-operator request with a 404, and an anonymous one with a sign-in', function (): void {
    /*
     * TWO REFUSALS, and the difference between them is the point — it is spelled out on the
     * route group and this is where it is checked.
     *
     * No session at all is a step the visitor can TAKE, so they are sent to sign in. A
     * session that simply is not an operator's must 404 rather than confirm that this
     * deployment has a staff console at that address.
     *
     * The component-driven version of this test could only ever see the second: it bypassed
     * the middleware entirely, so the redirect it is now asserting had nothing to produce it.
     */
    // On an install with nothing in it that redirect lands on the installer rather than the
    // sign-in page, which is the same answer one step earlier — so the world exists first.
    actingAsRole(MembershipRole::Owner);
    signOutOfConsole();

    test()->get(route('platform.usage'))->assertRedirect(route('login'));

    // And a real person, signed in, who does not run this deployment.
    actingAsRole(MembershipRole::Admin);

    test()->get(route('platform.usage'))->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Per-tenant usage panel — read directly, in-plane.
|--------------------------------------------------------------------------
*/

it('shows the per-tenant usage panel with member, MFA, domain and sign-in metrics', function (): void {
    usageOperatorSignIn();

    // Seeded in the operator's CURRENT (default) plane — the detail page reads it
    // directly, no scope escape, because the operator reached the org in-plane.
    $org = app(Organizations::class)->create(new NewOrganization('Acme Inc', 'acme-inc'));

    // Four members, two of whom have a CONFIRMED MFA factor → 50% adoption.
    $userIds = [];
    foreach (range(1, 4) as $i) {
        $user = app(Subjects::class)->create("member{$i}@acme.test", "Member {$i}", 'supersecret123');
        app(Memberships::class)->add($org->id, $user->id, $i === 1 ? MembershipRole::Owner : MembershipRole::Member);
        $userIds[] = $user->id;
    }
    foreach ([$userIds[0], $userIds[1]] as $mfaUserId) {
        MfaFactor::query()->create([
            'user_id' => $mfaUserId,
            'type' => 'totp',
            'secret_encrypted' => 'sealed',
            'confirmed_at' => Carbon::now(),
        ]);
    }
    // An UNconfirmed factor must not count towards adoption.
    MfaFactor::query()->create([
        'user_id' => $userIds[2],
        'type' => 'totp',
        'secret_encrypted' => 'sealed',
        'confirmed_at' => null,
    ]);

    // One active session for a member.
    activeSessionFor($userIds[0]);

    // A verified domain.
    VerifiedDomain::query()->create([
        'organization_id' => $org->id,
        'domain' => 'acme.test',
        'verification_token' => 'tok',
        'verified_at' => Carbon::now(),
    ]);

    // Two recent user.login audit events on the tenant's trail (recorded now → 30d).
    $audit = app(AuditLog::class);
    $audit->record(AuditEvent::forUser('user.login', $userIds[0], $org->id));
    $audit->record(AuditEvent::forUser('user.login', $userIds[1], $org->id));

    $usage = (array) platformOrganization($org->id)['usage'];

    expect($usage['members'])->toBe(4)
        // Two CONFIRMED factors out of four members. The third member's unconfirmed factor
        // is the one that must not count — an enrolment nobody completed is not MFA.
        ->and($usage['mfaUsers'])->toBe(2)
        ->and($usage['mfaAdoption'])->toBe(50)
        ->and($usage['sessions'])->toBe(1)
        ->and($usage['domains'])->toBe(1)
        ->and($usage['signIns'])->toBe(2);
});

it('refuses the per-tenant panel for a non-operator request with a 404', function (): void {
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme'));

    // A session that IS signed in and simply is not an operator's — 404 rather than 403,
    // because a 403 confirms to any account holder that this deployment has a staff
    // console at that address.
    ['subjectId' => $subjectId] = provisionAccount('not-an-operator@acme.example');
    signInAsMember($subjectId);

    test()->get(route('platform.organization', $org->id))->assertNotFound();
})->group('security');
