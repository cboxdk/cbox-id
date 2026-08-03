<?php

declare(strict_types=1);
use Cbox\Id\Platform\AccountProvisioner;
use Cbox\Id\Platform\ValueObjects\AccountBlueprint;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    // These render product pages, which presuppose an installed deployment.
    installedDeployment();
});

/**
 * The environment-admin console is a multi-tenant surface.
 *
 * It is gated on an account member administering one of their ACCOUNT's environments. A
 * single-tenant install has one environment and it is the platform root, which belongs to
 * no account — so the door could be opened by nobody, ever, while still rendering a form,
 * taking correct credentials and refusing them with a message that blamed the credentials.
 */
it('404s the admin console on a single-tenant deployment', function (): void {
    config()->set('cbox-id.tenancy.multi_tenant', false);

    $this->get('/admin/login')->assertNotFound();
    $this->get('/admin/single-sign-on')->assertNotFound();
})->group('security');

it('serves the admin console when the deployment is multi-tenant', function (): void {
    // The other half: a 404 that fired in both shapes would be a console nobody can reach
    // at all, which is a worse version of the bug it replaces.
    //
    // The Host matters, and asserting on the default one was the reason this half sat red:
    // `/admin` also carries `plane:subject`, and the subject plane is every host EXCEPT
    // the platform root. An unmapped test host falls back to the root — so the request
    // arrived on the account plane and was refused by the bulkhead, not by the gate under
    // test. A tenant environment with a real domain row is what an admin actually comes
    // through.
    config()->set('cbox-id.tenancy.multi_tenant', true);
    config()->set('cbox-id.tenancy.account_host', 'cboxid.com');

    $env = app(AccountProvisioner::class)->provision(new AccountBlueprint(
        accountName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ))->environment;

    $env->forceFill(['domain' => 'tenant.cboxid.com'])->save();

    // Reachable means it hands off, not that it renders a form. `/admin/login` no longer
    // has a form of its own: sign-in was unified onto the account plane, so a tenant host
    // sends the browser to the account host's door with the environment named. Asserting
    // the destination proves both halves — the bulkhead let the request through, AND the
    // door it opens is the unified one rather than a second credential store.
    $this->get('https://tenant.cboxid.com/admin/login')
        ->assertRedirect('https://cboxid.com/workspace/open/'.$env->id);
})->group('security');

it('leaves organization creation reachable on a single-tenant deployment', function (): void {
    // The capability that made this decision costly: creating an organization lived only
    // on the environment plane. It is on the operator console too, so removing the admin
    // console on this shape takes nothing with it — verified rather than assumed, because
    // "the operator console probably covers it" is how a capability goes missing.
    config()->set('cbox-id.tenancy.multi_tenant', false);

    expect(Route::has('operator.organizations'))->toBeTrue()
        ->and((string) file_get_contents(resource_path('views/livewire/operator/organizations.blade.php')))
        ->toContain('public function create(');
})->group('security');
