<?php

declare(strict_types=1);

use Cbox\Id\AccessControl\Models\Role;
use Cbox\Id\OAuthServer\Contracts\TokenIntrospector;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Fake introspector: opaque test tokens → a client id + scopes, so the real
    // auth path runs without minting signed JWTs.
    $this->app->instance(TokenIntrospector::class, new class implements TokenIntrospector
    {
        public function introspect(string $token): Introspection
        {
            return match ($token) {
                'manifest-tok' => Introspection::active(null, 'app_billing', ['apps.manifest'], []),
                'noscope-tok' => Introspection::active(null, 'app_billing', [], []),
                default => Introspection::inactive(),
            };
        }

        public function revoke(string $jti): void {}
    });
});

/**
 * @return array<string, mixed>
 */
function pushBody(): array
{
    return [
        'version' => '1',
        'permissions' => [['key' => 'invoices:read', 'description' => 'View invoices']],
        'roles' => [['key' => 'viewer', 'name' => 'Viewer', 'permissions' => ['invoices:read']]],
    ];
}

it('pushes a manifest and syncs the app catalog under its own client id', function (): void {
    $this->postJson('/api/v1/apps/manifest', pushBody(), ['Authorization' => 'Bearer manifest-tok'])
        ->assertOk()
        ->assertJson(['roles_declared' => 1, 'permissions_declared' => 1, 'unchanged' => false]);

    expect(Role::query()->where('client_id', 'app_billing')->where('key', 'viewer')->exists())->toBeTrue();
});

it('rejects a push missing the apps.manifest scope', function (): void {
    $this->postJson('/api/v1/apps/manifest', pushBody(), ['Authorization' => 'Bearer noscope-tok'])
        ->assertStatus(403);
});

it('rejects a malformed manifest with 422', function (): void {
    $this->postJson('/api/v1/apps/manifest', [
        'version' => '1',
        'permissions' => [],
        'roles' => [['key' => 'x', 'name' => 'X', 'permissions' => ['undeclared:read']]],
    ], ['Authorization' => 'Bearer manifest-tok'])->assertStatus(422);
});

it('rejects a push with no token', function (): void {
    $this->postJson('/api/v1/apps/manifest', pushBody())->assertStatus(401);
});
