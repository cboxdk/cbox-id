<?php

declare(strict_types=1);

use App\Platform\Actions\ActionRefused;
use App\Platform\RootSignInGuard;
use Cbox\Id\Federation\Contracts\Connections;
use Cbox\Id\Federation\Enums\ConnectionType;
use Cbox\Id\Federation\Models\Connection;
use Cbox\Id\Identity\Contracts\AuthPolicies;
use Cbox\Id\Identity\Enums\SsoEnforcement;
use Cbox\Id\Identity\Models\User;
use Cbox\Id\Identity\Models\WebAuthnCredential;
use Cbox\Id\Identity\ValueObjects\AuthPolicy;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| NOBODY LOCKS THE OPERATORS OUT OF THE PLATFORM
|--------------------------------------------------------------------------
|
| The platform root's environment is how every operator signs in, and its sign-in can now be
| changed from its console and its API. Each of these changes would leave the deployment with
| no administrator who can sign in, and each is refused — through the ACTION, so the console,
| REST, MCP and the CLI meet the same answer. The same changes in a tenant's environment are
| untouched: the workspace's team fixes a tenant from the root.
*/

const GUARD_ROOT = 'cboxid.com';

beforeEach(function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    multiTenantDeployment(GUARD_ROOT);
    config(['cbox-id.environments.base_domains' => [GUARD_ROOT]]);

    $this->account = provisionAccount('owner@acme.example');
    $this->operator = actAsOperator('ops@platform.test');
    $this->operatorSubject = (string) $this->operator->subject_id;
    $this->root = app(PlatformRoot::class)->model();

    // The operator belongs to the platform's own organization in the root.
    $this->staff = app(PlatformRoot::class)->run(function () {
        $organization = app(Organizations::class)->create(new NewOrganization('Cbox staff', 'cbox-staff'));
        app(Memberships::class)->add($organization->id, $this->operatorSubject, MembershipRole::Owner);

        return $organization;
    });
});

/** Run $callback in the platform root's environment, as its console and API do. */
function inTheRoot(Closure $callback): mixed
{
    return app(EnvironmentContext::class)->runAs(app(PlatformRoot::class)->model(), $callback);
}

/** An SSO connection of $organizationId in the root, active or not. */
function guardSsoConnection(string $organizationId, bool $active): Connection
{
    return inTheRoot(function () use ($organizationId, $active): Connection {
        $connections = app(Connections::class);
        $connection = $connections->create($organizationId, ConnectionType::Saml, 'Staff Okta', []);

        if ($active) {
            $connections->activate($organizationId, $connection->id);
        }

        return $connection->refresh();
    });
}

/** The current policy's fields as the console's form submits them, with $changes. */
function rootPolicyForm(array $changes): array
{
    $current = (array) test()->get('https://'.GUARD_ROOT.'/admin/sign-in-rules')->assertOk()->inertiaProps('policy');

    return [...$current, ...$changes];
}

it('refuses to require SSO of everybody on the platform environment while an operator has no connection to use', function (): void {
    $this->from('https://'.GUARD_ROOT.'/admin/sign-in-rules')
        ->put('https://'.GUARD_ROOT.'/admin/sign-in-rules', rootPolicyForm(['sso' => 'required']))
        ->assertSessionHasErrors('sso');

    expect(inTheRoot(fn () => app(AuthPolicies::class)->forEnvironment()->sso))->toBe(SsoEnforcement::Off);

    // The sentence says what would happen and what to do instead.
    $error = (string) session('errors')?->first('sso');
    expect($error)->toContain('ops@platform.test')->toContain('no way to sign in');
})->group('security');

it('allows it once every operator has an active connection to sign in with', function (): void {
    guardSsoConnection($this->staff->id, active: true);

    $this->from('https://'.GUARD_ROOT.'/admin/sign-in-rules')
        ->put('https://'.GUARD_ROOT.'/admin/sign-in-rules', rootPolicyForm(['sso' => 'required']))
        ->assertSessionHasNoErrors();

    expect(inTheRoot(fn () => app(AuthPolicies::class)->forEnvironment()->sso))->toBe(SsoEnforcement::Required);
})->group('security');

it('refuses to require SSO for the operators\' organization on a connection that does not work yet', function (): void {
    $draft = guardSsoConnection($this->staff->id, active: false);

    $this->post('https://'.GUARD_ROOT.'/admin/single-sign-on/'.$draft->id.'/require-sso')
        ->assertSessionHasErrors();

    expect(implode(' ', session('errors')?->all() ?? []))->toContain('no way to sign in');

    expect(inTheRoot(fn () => app(AuthPolicies::class)->overrideFor($this->staff->id)?->sso))->not->toBe(SsoEnforcement::Required);
})->group('security');

it('refuses to switch off the one connection operators who must use SSO can sign in with', function (): void {
    $connection = guardSsoConnection($this->staff->id, active: true);
    inTheRoot(fn () => app(AuthPolicies::class)->setForOrganization($this->staff->id, new AuthPolicy(sso: SsoEnforcement::Required)));

    expect(fn () => inTheRoot(fn () => app(RootSignInGuard::class)->assertOperatorsKeepAWayIn(['connections_gone' => [$connection->id]])))
        ->toThrow(ActionRefused::class);
})->group('security');

it('refuses to turn off passkeys and magic links for an operator who has nothing else', function (): void {
    // An operator who signed up with a passkey alone: no password to fall back on.
    inTheRoot(function (): void {
        User::query()->whereKey($this->operatorSubject)->update(['password' => null]);
        WebAuthnCredential::query()->create([
            'user_id' => $this->operatorSubject, 'credential_id' => 'cred-op', 'public_key' => 'key',
            'sign_count' => 0, 'transports' => ['internal'], 'name' => 'Laptop',
        ]);
    });

    $guard = fn (array $change) => inTheRoot(fn () => app(RootSignInGuard::class)->assertOperatorsKeepAWayIn($change));

    // Either alone leaves a way in…
    $guard(['passkeys' => false, 'magic_link' => true]);
    $guard(['passkeys' => true, 'magic_link' => false]);

    // …both together do not.
    expect(fn () => $guard(['passkeys' => false, 'magic_link' => false]))->toThrow(ActionRefused::class);
})->group('security');

it('leaves the same changes to a tenant\'s environment alone', function (): void {
    // In a tenant, the people locked out are the tenant's users, and the workspace's team —
    // root accounts — fixes it from the root. Nothing here concerns the operators.
    expect(app(EnvironmentContext::class)->runAs($this->account['environment'], fn (): bool => app(RootSignInGuard::class)->guards()))->toBeFalse();

    app(EnvironmentContext::class)->runAs($this->account['environment'], fn () => app(RootSignInGuard::class)->assertOperatorsKeepAWayIn(['environment_sso' => SsoEnforcement::Required]));
})->group('security');
