<?php

declare(strict_types=1);

use App\Platform\OperatorAuth;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Testing\FakeAuditLog;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\OrganizationStatus;
use Cbox\Id\Organization\Models\Organization;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Exceptions\CannotSuspendLastOperator;
use Cbox\Id\Platform\Models\PlatformOperator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

/** Swap in the assertable audit fake, then sign in as a fresh operator. */
function fakeAuditAndSignIn(string $email = 'auditor@platform.test'): array
{
    $audit = new FakeAuditLog;
    app()->instance(AuditLog::class, $audit);

    $op = app(PlatformOperators::class)->create($email, 'a-strong-operator-pass', 'Auditor');
    session([OperatorAuth::SESSION_KEY => $op->id]);

    return [$audit, $op];
}

it('records an audit event when an organization is suspended via the console', function (): void {
    [$audit, $op] = fakeAuditAndSignIn();
    $org = app(Organizations::class)->create(new NewOrganization('Acme', 'acme-audit'));

    Volt::test('operator.organizations')->call('toggleStatus', $org->id);

    expect(Organization::query()->find($org->id)->status)->toBe(OrganizationStatus::Suspended);
    $audit->assertRecorded('organization.suspended', fn (AuditEvent $e): bool => $e->actorId === $op->id && $e->targetId === $org->id);

    // Reactivating routes through the contract too, and is likewise audited.
    Volt::test('operator.organizations')->call('toggleStatus', $org->id);
    expect(Organization::query()->find($org->id)->status)->toBe(OrganizationStatus::Active);
    $audit->assertRecorded('organization.reactivated');
});

it('records an audit event when an operator is suspended via the console', function (): void {
    [$audit, $me] = fakeAuditAndSignIn('me-audit@platform.test');
    $target = app(PlatformOperators::class)->create('target-audit@platform.test', 'a-strong-operator-pass', 'Target');

    Volt::test('operator.operators')->call('toggleStatus', $target->id);

    expect(PlatformOperator::query()->whereKey($target->id)->value('status'))->toBe('suspended');
    $audit->assertRecorded('operator.suspended', fn (AuditEvent $e): bool => $e->actorId === $me->id && $e->targetId === $target->id);

    Volt::test('operator.operators')->call('toggleStatus', $target->id);
    expect(PlatformOperator::query()->whereKey($target->id)->value('status'))->toBe('active');
    $audit->assertRecorded('operator.reactivated');
});

it('surfaces the last-operator guard as a friendly message, not a 500', function (): void {
    $me = app(PlatformOperators::class)->create('solo@platform.test', 'a-strong-operator-pass', 'Solo');
    $target = app(PlatformOperators::class)->create('victim@platform.test', 'a-strong-operator-pass', 'Victim');
    session([OperatorAuth::SESSION_KEY => $me->id]);

    // The console's own guards make this path unreachable with a real repo, but the
    // component must still degrade gracefully if the contract ever refuses. Stub the
    // interface: lookups pass through to the real records, suspend() refuses.
    $mock = Mockery::mock(PlatformOperators::class);
    $mock->shouldReceive('find')->andReturnUsing(fn (string $id) => PlatformOperator::query()->find($id));
    $mock->shouldReceive('suspend')->andThrow(CannotSuspendLastOperator::make($target->id));
    app()->instance(PlatformOperators::class, $mock);

    // No exception propagates (would be a 500) — the component handles it inline.
    Volt::test('operator.operators')
        ->call('toggleStatus', $target->id)
        ->assertHasNoErrors();

    expect(PlatformOperator::query()->whereKey($target->id)->value('status'))->toBe('active');
});

it('refuses self-suspension without touching the audit trail', function (): void {
    [$audit, $me] = fakeAuditAndSignIn('self@platform.test');

    Volt::test('operator.operators')
        ->call('toggleStatus', $me->id)
        ->assertHasNoErrors();

    expect(PlatformOperator::query()->whereKey($me->id)->value('status'))->toBe('active');
    $audit->assertNotRecorded('operator.suspended');
});
