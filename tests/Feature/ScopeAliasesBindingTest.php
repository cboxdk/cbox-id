<?php

declare(strict_types=1);

use App\Platform\Scopes\RegistryScopeAliases;
use App\Platform\Scopes\ScopeRegistry;
use Cbox\Id\Kernel\Authorization\Contracts\ScopeAliases;
use Cbox\Id\Kernel\Authorization\Support\Scopes;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Support\GrantedScopes;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * RegistryScopeAliases hands laravel-id's ScopeAliases hook the app's ScopeRegistry, so
 * the framework's own scope checks (a client's registration, a key, an introspected token)
 * treat an old and a v3 name as one scope — and never wider than the registry allows.
 */

uses(RefreshDatabase::class);

// Not bound by the app yet (see AppServiceProvider): these pin the adapter for the day it is.
beforeEach(fn () => app()->instance(ScopeAliases::class, new RegistryScopeAliases));

it('lets an old name and its v3 name satisfy each other in the framework, both ways', function (): void {
    expect(Scopes::satisfies(['workspace:read'], 'id:organization:read'))->toBeTrue()
        ->and(Scopes::satisfies(['id:organization:read'], 'workspace:read'))->toBeTrue()
        ->and(Scopes::satisfies(['users:read'], 'id:users:read'))->toBeTrue()
        ->and(Scopes::satisfies(['id:users:read'], 'users:read'))->toBeTrue()
        ->and(Scopes::satisfies(['id:users:read'], 'id:users:write'))->toBeFalse()
        // Protocol scopes and a customer's own scopes are only themselves.
        ->and(Scopes::satisfies(['openid'], 'openid'))->toBeTrue()
        ->and(Scopes::satisfies(['tax.quote'], 'tax.assess'))->toBeFalse();
});

it('never lets the framework grant wider than the registry', function (): void {
    // Two old names that collapse into one new name do not satisfy each other.
    expect(Scopes::satisfies(['vault.manage'], 'token_vault:write'))->toBeFalse()
        ->and(Scopes::satisfies(['token_vault:write'], 'vault.manage'))->toBeFalse()
        ->and(Scopes::satisfies(['approvals.read'], 'approvals:read'))->toBeFalse()
        // keys:write split: the organization-keys half never stands in for it.
        ->and(Scopes::satisfies(['id:organization-keys:write'], 'keys:write'))->toBeFalse()
        ->and(Scopes::satisfies(['keys:write'], 'id:environment-keys:write'))->toBeTrue()
        ->and(Scopes::satisfies(['id:environment-keys:write'], 'keys:write'))->toBeTrue();
})->group('security');

it('is never wider than the registry, for every name it knows', function (): void {
    $names = [...array_keys(ScopeRegistry::RENAMED), ...ScopeRegistry::names()];

    foreach ($names as $held) {
        foreach ($names as $required) {
            if (Scopes::satisfies([$held], $required)) {
                expect(ScopeRegistry::satisfies([$held], $required))->toBeTrue("{$held} satisfies {$required} in the framework only");
            }
        }
    }
})->group('security');

it('lets a client registered under an old name ask for the v3 name, and grants the v3 name', function (): void {
    $client = app(ClientRegistry::class)->register(new NewClient(
        name: 'Old agent',
        type: ClientType::Confidential,
        grantTypes: ['client_credentials'],
        scopes: ['workspace:read', 'users:read'],
    ))->client;

    expect($client->allows('id:organization:read'))->toBeTrue()
        ->and($client->allows('id:users:write'))->toBeFalse()
        ->and(GrantedScopes::for($client, ['workspace:read']))->toBe(['id:organization:read'])
        ->and(GrantedScopes::for($client, ['id:users:read']))->toBe(['id:users:read']);
});
