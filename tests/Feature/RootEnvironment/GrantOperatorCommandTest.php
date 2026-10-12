<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Platform\Contracts\PlatformOperators;
use Cbox\Id\Platform\Enums\OperatorStatus;
use Cbox\Id\Platform\Models\PlatformOperator;
use Cbox\Id\Platform\PlatformRoot;

/*
|--------------------------------------------------------------------------
| `php artisan cbox-id:operator:grant {email}`
|--------------------------------------------------------------------------
|
| The way in for an owner who is not an operator — somebody who signed up before the
| deployment was claimed — from a shell on the deployment, which is already its highest
| authority. Idempotent, audited, and it never creates a person.
*/

beforeEach(function (): void {
    multiTenantDeployment('cboxid.com');
    $this->account = provisionAccount('owner@acme.example');
});

it('makes an existing person of the root an operator, once, and says so in the trail', function (): void {
    $this->artisan('cbox-id:operator:grant', ['email' => 'Owner@Acme.example'])->assertSuccessful();

    $operator = app(PlatformOperators::class)->findBySubject($this->account['subjectId']);

    expect($operator)->not->toBeNull()
        ->and($operator?->status)->toBe(OperatorStatus::Active);

    // Again: nothing changes, and it says so.
    $this->artisan('cbox-id:operator:grant', ['email' => 'owner@acme.example'])
        ->expectsOutputToContain('already a platform operator')
        ->assertSuccessful();

    expect(PlatformOperator::query()->count())->toBe(1);

    $granted = app(EnvironmentContext::class)->runAs(
        app(PlatformRoot::class)->model(),
        static fn () => AuditEntry::query()->where('action', 'operator.granted')->get(),
    );

    expect($granted)->toHaveCount(1)
        ->and($granted->first()?->target_id)->toBe((string) $operator?->id);
});

it('keeps the person\'s own credential: the grant links to the subject that already signs in', function (): void {
    $this->artisan('cbox-id:operator:grant', ['email' => 'owner@acme.example'])->assertSuccessful();

    expect(app(PlatformRoot::class)->run(fn () => app(Subjects::class)->verifyPassword($this->account['subjectId'], 'a-strong-unbreached-passphrase')))->toBeTrue();
});

it('never creates a person', function (): void {
    $this->artisan('cbox-id:operator:grant', ['email' => 'nobody@nowhere.example'])->assertFailed();

    expect(PlatformOperator::query()->count())->toBe(0);
});

it('does not undo a suspension unless told to', function (): void {
    $this->artisan('cbox-id:operator:grant', ['email' => 'owner@acme.example'])->assertSuccessful();
    $other = actAsOperator('second@platform.test');
    $operator = app(PlatformOperators::class)->findBySubject($this->account['subjectId']);
    app(PlatformOperators::class)->suspend((string) $operator?->id, (string) $other->id);

    $this->artisan('cbox-id:operator:grant', ['email' => 'owner@acme.example'])->assertFailed();
    expect(app(PlatformOperators::class)->findBySubject($this->account['subjectId']))->toBeNull();

    $this->artisan('cbox-id:operator:grant', ['email' => 'owner@acme.example', '--reactivate' => true])->assertSuccessful();
    expect(app(PlatformOperators::class)->findBySubject($this->account['subjectId']))->not->toBeNull();
});

it('opens the platform environment\'s console to the person it was granted to', function (): void {
    signInAsSubject($this->account['subjectId']);
    $this->get('https://cboxid.com/admin')->assertNotFound();

    $this->artisan('cbox-id:operator:grant', ['email' => 'owner@acme.example'])->assertSuccessful();

    app()->forgetScopedInstances();
    $this->get('https://cboxid.com/admin')->assertOk();
});
