<?php

declare(strict_types=1);

use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

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
    // The environment console lives at `/admin`, which 404s unless the deployment is
    // multi-tenant — and two environments is the whole premise of this test.
    multiTenantDeployment();

    // Victim environment, with an entry that must never be visible elsewhere.
    platformRootEnvironment();
    $victim = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Victim Co',
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
    platformRootEnvironment();
    $attacker = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Attacker Co',
        ownerEmail: 'owner@attacker.example',
        ownerName: 'Attacker Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));

    serveOnTestHost($attacker->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($attacker->environment->id));
    actAsEnvironmentAdmin($attacker->owner->id, $attacker->environment->id);

    $actions = function (array $query = []): array {
        $props = test()->get(route('environment.audit', $query))->assertOk()->inertiaProps('entries');

        return collect(is_array($props) ? $props : [])->pluck('action')->all();
    };

    expect($actions())->not->toContain('victim.secret_action');

    // …and the search box is not an enumeration oracle either. Asserted on the PROPS, not
    // the rendered page: the search term is echoed back into the input's value, so a
    // markup assertion would match the query string rather than a leaked row.
    expect($actions(['q' => 'victim.secret_action']))->toBe([]);
});
