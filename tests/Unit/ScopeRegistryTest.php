<?php

declare(strict_types=1);

use App\Platform\Scopes\ScopeRegistry;

/**
 * v3 names every Cbox ID scope `id:<resource>:<read|write>`; v2's names keep working until
 * v3.1. These pin the rules that keep the rename from widening or narrowing a grant.
 */
it('names every new scope id:<resource>:<read|write>', function (): void {
    foreach (ScopeRegistry::RENAMED as $old => $new) {
        foreach ($new as $name) {
            expect($name)->toMatch(ScopeRegistry::PATTERN, "{$old} → {$name}");
        }
    }
});

it('lets an old name and its new name satisfy each other, both ways', function (): void {
    expect(ScopeRegistry::satisfies(['workspace:read'], 'id:organization:read'))->toBeTrue()
        ->and(ScopeRegistry::satisfies(['id:organization:read'], 'workspace:read'))->toBeTrue()
        ->and(ScopeRegistry::satisfies(['account:sign_in:write'], 'id:my-sign-in:write'))->toBeTrue()
        ->and(ScopeRegistry::satisfies(['id:organization:write'], 'id:organization:read'))->toBeFalse();
});

it('never lets two old names that collapse into one new name satisfy each other', function (): void {
    expect(ScopeRegistry::satisfies(['vault.manage'], 'token_vault:write'))->toBeFalse()
        ->and(ScopeRegistry::satisfies(['token_vault:write'], 'vault.manage'))->toBeFalse()
        // Each still reaches the new name.
        ->and(ScopeRegistry::satisfies(['vault.manage'], 'id:vault-secrets:write'))->toBeTrue()
        ->and(ScopeRegistry::satisfies(['token_vault:write'], 'id:vault-secrets:write'))->toBeTrue();
})->group('security');

it('splits keys:write without letting the organization-keys half mint environment keys', function (): void {
    // A v2 key with keys:write did both; it still does both.
    expect(ScopeRegistry::satisfies(['keys:write'], 'id:environment-keys:write'))->toBeTrue()
        ->and(ScopeRegistry::satisfies(['keys:write'], 'id:organization-keys:write'))->toBeTrue()
        // The environment plane still declares keys:write: only the environment-keys name stands in for it.
        ->and(ScopeRegistry::satisfies(['id:environment-keys:write'], 'keys:write'))->toBeTrue()
        ->and(ScopeRegistry::satisfies(['id:organization-keys:write'], 'keys:write'))->toBeFalse()
        ->and(ScopeRegistry::canonical('keys:write'))->toBe('id:environment-keys:write');
})->group('security');

it('normalises a stored list to the new names, and widens a published list with the old ones', function (): void {
    expect(ScopeRegistry::normalise(['workspace:read', 'keys:write', 'openid']))
        ->toBe(['id:organization:read', 'id:environment-keys:write', 'id:organization-keys:write', 'openid'])
        ->and(ScopeRegistry::withLegacy(['id:organization:read']))->toContain('id:organization:read', 'workspace:read');
});

it('does not rename the OIDC scopes or the directory scopes laravel-id reserves', function (): void {
    foreach (['openid', 'profile', 'email', 'offline_access', 'directories:read'] as $scope) {
        expect(ScopeRegistry::canonical($scope))->toBe($scope)
            ->and(ScopeRegistry::isLegacy($scope))->toBeFalse();
    }
});
