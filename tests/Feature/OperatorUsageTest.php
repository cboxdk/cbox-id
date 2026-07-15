<?php

declare(strict_types=1);

use App\Platform\OperatorAuth;
use Cbox\Id\Federation\Models\VerifiedDomain;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Models\MfaFactor;
use Cbox\Id\Identity\Models\Session;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Testing\InteractsWithTenancy;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Models\PlatformOperator;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;

uses(InteractsWithTenancy::class);

/** Sign a fresh operator into the session — reads are pinned to the default plane. */
function usageOperatorSignIn(string $email = 'usage-op@platform.test'): PlatformOperator
{
    $op = app(PlatformOperators::class)->create($email, 'a-strong-operator-pass', 'Op');
    session([OperatorAuth::SESSION_KEY => $op->id]);

    return $op;
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
        app(Memberships::class)->add($orgA1->id, $uA1->id, 'owner');

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
        app(Memberships::class)->add($orgB1->id, $uB1->id, 'owner');
        activeSessionFor($uB1->id);
    });

    usageOperatorSignIn();

    Volt::test('operator.usage')
        // Totals sum across BOTH planes (proving EnvironmentContext::withoutScope).
        ->assertViewHas('totals', fn (array $t): bool => $t['environments'] === 2
            && $t['organizations'] === 3
            && $t['users'] === 3
            && $t['sessions'] === 2)
        // Per-environment breakdown shows each plane's own counts.
        ->assertViewHas('breakdown', function (array $rows): bool {
            $byName = collect($rows)->keyBy('name');
            $a = $byName->get('Plane A');
            $b = $byName->get('Plane B');

            return $a !== null && $a['organizations'] === 2 && $a['users'] === 2 && $a['sessions'] === 1
                && $b !== null && $b['organizations'] === 1 && $b['users'] === 1 && $b['sessions'] === 1;
        })
        ->assertSee('Plane A')
        ->assertSee('Plane B');
});

it('ranks top organizations by member count across planes, each linking to its plane', function (): void {
    $planeA = usagePlane('Plane A', 'plane-a');
    $planeB = usagePlane('Plane B', 'plane-b');

    $orgA1Id = $this->runAsEnvironment($planeA, function (): string {
        $orgA1 = app(Organizations::class)->create(new NewOrganization('Acme A', 'acme-a'));
        $u1 = app(Subjects::class)->create('m1@acme.test', 'M1', 'supersecret123');
        $u2 = app(Subjects::class)->create('m2@acme.test', 'M2', 'supersecret123');
        app(Memberships::class)->add($orgA1->id, $u1->id, 'owner');
        app(Memberships::class)->add($orgA1->id, $u2->id, 'member');

        return $orgA1->id;
    });

    $orgB1Id = $this->runAsEnvironment($planeB, function (): string {
        $orgB1 = app(Organizations::class)->create(new NewOrganization('Gamma B', 'gamma-b'));
        $u3 = app(Subjects::class)->create('m3@gamma.test', 'M3', 'supersecret123');
        app(Memberships::class)->add($orgB1->id, $u3->id, 'owner');

        return $orgB1->id;
    });

    usageOperatorSignIn();

    Volt::test('operator.usage')
        ->assertViewHas('topOrganizations', function (array $rows) use ($orgA1Id, $orgB1Id): bool {
            $byId = collect($rows)->keyBy('id');
            $a = $byId->get($orgA1Id);
            $b = $byId->get($orgB1Id);

            // Cross-plane roll-up: both orgs present, correct member counts + planes,
            // and the most-members org ranks first.
            return $a !== null && $a['members'] === 2 && $a['plane'] === 'Plane A'
                && $b !== null && $b['members'] === 1 && $b['plane'] === 'Plane B'
                && $rows[0]['id'] === $orgA1Id;
        })
        // Each row links to the cross-plane jump route (opens in the right plane).
        ->assertSee(route('operator.search.jump', $orgA1Id), escape: false);
});

it('refuses a non-operator request with a 403', function (): void {
    // No operator session — boot()'s per-request auth re-check aborts every request.
    Volt::test('operator.usage')->assertForbidden();
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
        app(Memberships::class)->add($org->id, $user->id, $i === 1 ? 'owner' : 'member');
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

    Volt::test('operator.organization', ['organization' => $org->id])
        ->assertViewHas('usage', function (array $u): bool {
            return $u['members'] === 4
                && $u['mfaUsers'] === 2
                && $u['mfaAdoption'] === 50
                && $u['sessions'] === 1
                && $u['domains'] === 1
                && $u['signIns'] === 2;
        })
        ->assertSee('MFA adoption')
        ->assertSee('50%')
        ->assertSee('Sign-ins (30d)');
});

it('refuses the per-tenant panel for a non-operator request with a 403', function (): void {
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme'));

    Volt::test('operator.organization', ['organization' => $org->id])->assertForbidden();
});
