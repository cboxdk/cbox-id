<?php

declare(strict_types=1);

use App\Platform\OperatorAuth;
use Cbox\Id\Federation\Testing\InteractsWithFederation;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Testing\FakeAuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Authorization\Contracts\EntitlementWriter;
use Cbox\Id\Kernel\Authorization\Enums\EntitlementSource;
use Cbox\Id\Kernel\Authorization\ValueObjects\EntitlementInput;
use Cbox\Id\Kernel\Tenancy\Testing\InteractsWithTenancy;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Models\PlatformOperator;
use Livewire\Volt\Volt;

uses(InteractsWithFederation::class, InteractsWithTenancy::class);

/** Sign in a fresh operator, whose reads are pinned to the default test plane. */
function detailOperatorSignIn(string $email = 'detail-op@platform.test'): PlatformOperator
{
    $op = app(PlatformOperators::class)->create($email, 'a-strong-operator-pass', 'Op');
    session([OperatorAuth::SESSION_KEY => $op->id]);

    return $op;
}

it('shows a tenant\'s name, members, verified domain and entitlement in the current plane', function (): void {
    detailOperatorSignIn();

    $org = app(Organizations::class)->create(new NewOrganization('Acme Inc', 'acme-inc'));
    $member = app(Subjects::class)->create('member@acme.test', 'Member One', 'supersecret123');
    app(Memberships::class)->add($org->id, $member->id, 'owner');
    $this->makeVerifiedDomain($org->id, 'acme.test');
    app(EntitlementWriter::class)->set($org->id, new EntitlementInput('sso', ['enabled' => true]), EntitlementSource::Manual);

    Volt::test('operator.organization', ['organization' => $org->id])
        ->assertSee('Acme Inc')
        ->assertSee('member@acme.test')
        ->assertSee('owner')
        ->assertSee('acme.test')
        ->assertSee('sso');
});

it('refuses a non-operator request with a 403', function (): void {
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme'));

    // No operator session — boot()'s per-request auth re-check aborts before mount.
    Volt::test('operator.organization', ['organization' => $org->id])->assertForbidden();
});

it('returns 404 for an org that lives in another environment', function (): void {
    detailOperatorSignIn();

    // Create the org entirely inside a DIFFERENT plane. From the operator's current
    // (default) plane the scoped lookup returns null → deny-by-default 404, so the
    // page never leaks a tenant from another environment.
    $foreignId = $this->runAsEnvironment('other-env', fn (): string => app(Organizations::class)
        ->create(new NewOrganization('Foreign', 'foreign'))->id);

    Volt::test('operator.organization', ['organization' => $foreignId])->assertNotFound();
});

it('suspends and reactivates the tenant from the detail page, recording audit', function (): void {
    $audit = new FakeAuditLog;
    app()->instance(AuditLog::class, $audit);
    $op = detailOperatorSignIn('audit-op@platform.test');

    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-audit'));

    Volt::test('operator.organization', ['organization' => $org->id])->call('toggleStatus');
    expect(Organization::query()->find($org->id)->status)->toBe(OrganizationStatus::Suspended);
    $audit->assertRecorded('organization.suspended', fn (AuditEvent $e): bool => $e->actorId === $op->id && $e->targetId === $org->id);

    Volt::test('operator.organization', ['organization' => $org->id])->call('toggleStatus');
    expect(Organization::query()->find($org->id)->status)->toBe(OrganizationStatus::Active);
    $audit->assertRecorded('organization.reactivated');
});
