<?php

declare(strict_types=1);

use App\Platform\Console\ConsolePlane;
use App\Platform\Console\ConsoleScope;
use Carbon\CarbonImmutable;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Support\ClientAudit;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\OAuthServer\ValueObjects\RegisteredClient;
use Cbox\Id\Organization\Enums\MembershipRole;
use Inertia\Support\SessionKey;

/*
|--------------------------------------------------------------------------
| An app's secrets: rotated with an overlap, revoked one at a time
|--------------------------------------------------------------------------
| Rotation was a cut-over — the old secret died the instant the new one existed — and the
| page said so, because the console passed a grace of 0. The registry can keep the replaced
| secret alive for an overlap; the page offers one, bounded by the install's ceiling, and
| lets an administrator cut a single leaked secret off without replacing the rest.
*/

beforeEach(function (): void {
    installedDeployment();
});

function secretsApp(?string $organizationId): RegisteredClient
{
    return app(ClientRegistry::class)->register(new NewClient(
        name: 'Billing',
        type: ClientType::Confidential,
        redirectUris: ['https://billing.example/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        organizationId: $organizationId,
    ));
}

function flashedSecret(): ?string
{
    $flash = session()->get(SessionKey::FLASH_DATA, []);
    $secret = is_array($flash) ? ($flash['revealedSecret'] ?? null) : null;

    return is_string($secret) ? $secret : null;
}

it('lists an app\'s live secrets by their last four characters, never the secret', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $registered = secretsApp($org->id);

    $response = test()->get(route('clients.secrets', $registered->client->id))->assertOk();
    $secrets = (array) $response->inertiaProps('secrets');

    expect($secrets)->toHaveCount(1)
        ->and($secrets[0]['hint'] ?? null)->toBe(substr((string) $registered->secret, -4))
        ->and($secrets[0]['expiring'] ?? null)->toBeFalse()
        // The only live secret is rotated, not revoked.
        ->and($secrets[0])->toHaveKey('revokeHref', null)
        ->and((string) $response->getContent())->not->toContain((string) $registered->secret);
});

it('rotates with the overlap chosen, so the old secret keeps working until then', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    confirmConsoleStepUp();
    $registered = secretsApp($org->id);
    $client = $registered->client;

    test()->from(route('clients.secrets', $client->id))
        ->post(route('clients.rotate', $client->id), ['grace' => 3600])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Secret rotated. The previous one keeps working for another hour. Copy the new one now — it will not be shown again.');

    $registry = app(ClientRegistry::class);
    $fresh = $client->fresh() ?? $client;
    $new = (string) flashedSecret();

    expect($registry->verifySecret($fresh, $new))->toBeTrue()
        ->and($registry->verifySecret($fresh, (string) $registered->secret))->toBeTrue()
        ->and($registry->secrets($fresh))->toHaveCount(2);

    // An hour on, only the new one works.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(3601));

    expect($registry->verifySecret($fresh, (string) $registered->secret))->toBeFalse()
        ->and($registry->verifySecret($fresh, $new))->toBeTrue();

    CarbonImmutable::setTestNow();

    $rotated = AuditEntry::query()->where('action', ClientAudit::SECRET_ROTATED)->sole();

    expect($rotated->context['grace_seconds'] ?? null)->toBe(3600);
});

it('still cuts a secret off at once when that is what is chosen', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    confirmConsoleStepUp();
    $registered = secretsApp($org->id);

    test()->from(route('clients.secrets', $registered->client->id))
        ->post(route('clients.rotate', $registered->client->id), ['grace' => 0])
        ->assertSessionHasNoErrors();

    expect(app(ClientRegistry::class)->verifySecret($registered->client->fresh() ?? $registered->client, (string) $registered->secret))->toBeFalse();
});

it('offers and accepts only the overlaps this install allows', function (): void {
    config(['cbox-id.oauth.client_secrets.max_rotation_grace' => 3600]);

    [, $org] = actingAsRole(MembershipRole::Owner);
    confirmConsoleStepUp();
    $registered = secretsApp($org->id);

    $offered = collect((array) test()->get(route('clients.secrets', $registered->client->id))->inertiaProps('graces'))
        ->pluck('value')
        ->all();

    expect($offered)->toBe([0, 3600]);

    // A day is a choice the page never drew. Refused in the page's words, and nothing minted.
    test()->from(route('clients.secrets', $registered->client->id))
        ->post(route('clients.rotate', $registered->client->id), ['grace' => 86400])
        ->assertSessionHasErrors(['grace' => 'Choose how long the current secret keeps working from the periods offered.']);

    expect(app(ClientRegistry::class)->secrets($registered->client))->toHaveCount(1);
});

it('revokes one secret and leaves the others working', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    confirmConsoleStepUp();
    $registered = secretsApp($org->id);
    $client = $registered->client;
    $registry = app(ClientRegistry::class);

    $registry->rotateSecret($client, 86400);
    $old = collect($registry->secrets($client))->firstWhere('expiresAt', '!==', null);

    test()->from(route('clients.secrets', $client->id))
        ->delete(route('clients.secrets.revoke', [$client->id, $old?->id]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Secret ending in '.substr((string) $registered->secret, -4).' revoked. It no longer works.');

    expect($registry->verifySecret($client->fresh() ?? $client, (string) $registered->secret))->toBeFalse()
        ->and($registry->secrets($client))->toHaveCount(1)
        ->and(AuditEntry::query()->where('action', ClientAudit::SECRET_REVOKED)->count())->toBe(1);

    // The one left is the app's only secret, and is not revoked on its own.
    $last = $registry->secrets($client)[0];

    test()->from(route('clients.secrets', $client->id))
        ->delete(route('clients.secrets.revoke', [$client->id, $last->id]))
        ->assertSessionHas('error', 'This is the app\'s only live secret. Rotate it to replace it, or delete the app to switch it off.');

    expect($registry->secrets($client))->toHaveCount(1);
});

it('asks for a password before revoking a secret', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $registered = secretsApp($org->id);
    $client = $registered->client;
    $registry = app(ClientRegistry::class);
    $registry->rotateSecret($client, 86400);
    $old = collect($registry->secrets($client))->firstWhere('expiresAt', '!==', null);

    test()->from(route('clients.secrets', $client->id))
        ->delete(route('clients.secrets.revoke', [$client->id, $old?->id]))
        ->assertRedirect(route('sudo'));

    expect($registry->secrets($client))->toHaveCount(2);
})->group('security');

it('never lets an organization revoke a secret of an app it does not own', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    confirmConsoleStepUp();

    // The environment's first-party app: in this organization's launcher, visible on its
    // console, and not its to change.
    $platform = app(ClientRegistry::class)->register(new NewClient(
        name: 'Platform app',
        type: ClientType::Confidential,
        redirectUris: ['https://platform.example/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        firstParty: true,
    ))->client;
    $registry = app(ClientRegistry::class);
    $registry->rotateSecret($platform, 86400);
    $old = collect($registry->secrets($platform))->firstWhere('expiresAt', '!==', null);

    test()->get(route('clients.secrets', $platform->id))->assertForbidden();

    test()->delete(route('clients.secrets.revoke', [$platform->id, $old?->id]))->assertForbidden();

    expect($registry->secrets($platform))->toHaveCount(2)
        ->and($org->id)->not->toBe('');
})->group('security');

it('has no secrets page for an app with no secret', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);

    $spa = app(ClientRegistry::class)->register(new NewClient(
        name: 'Storefront',
        type: ClientType::Public,
        redirectUris: ['https://shop.example/cb'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        organizationId: $org->id,
    ))->client;

    test()->get(route('clients.secrets', $spa->id))->assertNotFound();

    $tabs = collect((array) (test()->get(route('clients.show', $spa->id))->inertiaProps('appHeader'))['tabs']);

    expect($tabs->pluck('key')->all())->toBe(['overview', 'scopes', 'settings']);
});

it('rotates from the environment console for an environment-owned app', function (): void {
    crudSetup();
    confirmConsoleStepUp();

    expect(app(ConsoleScope::class)->plane())->toBe(ConsolePlane::Environment);

    $registered = secretsApp(null);

    test()->from(route('environment.clients.secrets', $registered->client->id))
        ->post(route('environment.clients.rotate', $registered->client->id), ['grace' => 604800])
        ->assertSessionHasNoErrors();

    expect(app(ClientRegistry::class)->secrets(Client::query()->findOrFail($registered->client->id)))->toHaveCount(2);
});
