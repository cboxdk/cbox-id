<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/**
 * The console read `AuditEntry::query()` with no filter whatsoever, against a table that
 * had no environment column — so a free self-serve signup could create an environment,
 * open /admin/audit, and page through every other customer's security trail.
 *
 * The model is environment-owned now, so this asserts the CONSOLE actually inherits that
 * isolation rather than trusting the scope in the abstract.
 */
it('shows an environment admin only their own environment\'s audit trail', function (): void {
    // Victim environment, with an entry that must never be visible elsewhere.
    $victim = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Victim Co',
        ownerEmail: 'owner@victim.example',
        ownerName: 'Victim Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    app(EnvironmentContext::class)->set(GenericEnvironment::of($victim->environment->id));
    app(AuditLog::class)->record(new AuditEvent(
        action: 'victim.secret_action',
        actorType: ActorType::System,
        organizationId: null,
    ));

    // Attacker signs up for their own environment — no special privilege.
    $attacker = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Attacker Co',
        ownerEmail: 'owner@attacker.example',
        ownerName: 'Attacker Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    config(['cbox-id.environments.default' => $attacker->environment->id]);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($attacker->environment->id));
    session()->put(EnvironmentAdminAuth::SESSION_KEY, $attacker->member->id);
    session()->put(EnvironmentAdminAuth::ENV_KEY, $attacker->environment->id);

    $actions = fn ($component) => collect($component->viewData('entries')->items())
        ->pluck('action')
        ->all();

    expect($actions(Volt::test('environment.audit')))->not->toContain('victim.secret_action');

    // …and the search box is not an enumeration oracle either. Asserted on the DATA, not
    // the rendered HTML: the search term is echoed back into the input's value, so a
    // markup assertion would match the query string rather than a leaked row.
    expect($actions(Volt::test('environment.audit')->set('search', 'victim.secret_action')))
        ->toBe([]);
});
