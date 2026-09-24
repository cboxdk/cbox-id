<?php

declare(strict_types=1);

use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\Models\AuditEntry;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\ClientType;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\OAuthServer\Support\ClientAudit;
use Cbox\Id\OAuthServer\ValueObjects\ClientBlueprint;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\ProvisionedTenant;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Support\Facades\Route;
use Inertia\Support\SessionKey;

/*
|--------------------------------------------------------------------------
| Taking an app to another environment: its blueprint, or a copy
|--------------------------------------------------------------------------
| Staging and production of one product each need their own registration of the same app,
| and the second one used to be typed in by hand from the first. The blueprint is that app
| without its identity or credentials; the copy imports it into another environment of the
| same project with a client id and secret of its own. Where it may go is an authorization
| question — the same project, an environment this person administers — asked the same way
| for the list the dialog offers and for the write.
*/

beforeEach(function (): void {
    installedDeployment();
});

/**
 * A workspace with one project holding Production and Staging, its owner administering
 * Production.
 *
 * @return array{tenant: ProvisionedTenant, staging: Environment}
 */
function promotionWorkspace(): array
{
    multiTenantDeployment();
    platformRootEnvironment();

    $provisioner = app(TenantProvisioner::class);
    $tenant = $provisioner->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
        environmentLimit: 3,
    ));
    $staging = $provisioner->addEnvironment($tenant->project, 'Staging', null, EnvironmentType::Sandbox);

    serveOnTestHost($tenant->environment);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($tenant->environment->id));
    actAsEnvironmentAdmin($tenant->owner->id, $tenant->environment->id);

    return ['tenant' => $tenant, 'staging' => $staging];
}

function promotableApp(?string $organizationId = null): Client
{
    return app(ClientRegistry::class)->register(new NewClient(
        name: 'Storefront',
        type: ClientType::Confidential,
        redirectUris: ['https://shop.acme.example/callback'],
        grantTypes: ['authorization_code', 'refresh_token'],
        scopes: ['openid', 'profile', 'orders.read'],
        organizationId: $organizationId,
        accessTokenTtl: 1800,
    ))->client;
}

/** Every app in an environment, read in that environment. @return list<Client> */
function appsIn(Environment $environment): array
{
    return app(EnvironmentContext::class)->runAs($environment, fn (): array => array_values(Client::query()->get()->all()));
}

/** @return array<string, mixed>|null */
function copiedFlash(): ?array
{
    $flash = session()->get(SessionKey::FLASH_DATA, []);
    $copied = is_array($flash) ? ($flash['copiedApp'] ?? null) : null;

    return is_array($copied) ? $copied : null;
}

it('downloads an app\'s blueprint with no client id and no secret in it', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $registered = app(ClientRegistry::class)->register(new NewClient(
        name: 'Storefront',
        type: ClientType::Confidential,
        redirectUris: ['https://shop.acme.example/callback'],
        grantTypes: ['authorization_code'],
        scopes: ['openid'],
        organizationId: $org->id,
    ));

    $response = test()->get(route('clients.blueprint', $registered->client->id))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json; charset=utf-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="storefront.blueprint.json"');

    $body = (string) $response->getContent();

    expect(ClientBlueprint::fromJson($body)->name)->toBe('Storefront')
        ->and($body)->not->toContain($registered->client->client_id)
        ->and($body)->not->toContain((string) $registered->secret)
        ->and($body)->not->toContain('csec_');
});

it('copies an environment\'s app into another environment of its project, with its own credentials', function (): void {
    ['tenant' => $tenant, 'staging' => $staging] = promotionWorkspace();
    confirmConsoleStepUp();
    $app = promotableApp();

    // The dialog offers the project's other environment.
    $copy = (array) (test()->get(route('environment.clients.show', $app->id))->assertOk()->inertiaProps('appHeader'))['copy'];

    expect(array_column($copy['targets'], 'id'))->toBe([$staging->id])
        ->and($copy['unavailable'])->toBeNull();

    test()->from(route('environment.clients.show', $app->id))
        ->post(route('environment.clients.copy', $app->id), [
            'environment' => $staging->id,
            'name' => 'Storefront (staging)',
            'redirectUris' => 'https://staging.shop.acme.example/callback',
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Copied to Staging. Copy its credentials now — the secret will not be shown again.');

    $copies = appsIn($staging);

    expect($copies)->toHaveCount(1);

    $copied = $copies[0];
    $flash = (array) copiedFlash();

    expect($copied->name)->toBe('Storefront (staging)')
        ->and($copied->client_id)->not->toBe($app->client_id)
        ->and($copied->redirect_uris)->toBe(['https://staging.shop.acme.example/callback'])
        ->and($copied->scopes)->toBe(['openid', 'orders.read', 'profile'])
        ->and($copied->access_token_ttl)->toBe(1800)
        ->and($copied->organization_id)->toBeNull()
        ->and($flash['clientId'] ?? null)->toBe($copied->client_id)
        ->and($flash['environment'] ?? null)->toBe('Staging');

    $verifies = app(EnvironmentContext::class)->runAs(
        $staging,
        fn (): bool => app(ClientRegistry::class)->verifySecret($copied, (string) ($flash['secret'] ?? '')),
    );

    expect($verifies)->toBeTrue();

    // Recorded in the environment it landed in, attributed to the person — not to the
    // system, which is who the session resolves to once the environment has moved.
    $created = app(EnvironmentContext::class)->runAs($staging, fn (): ?AuditEntry => AuditEntry::query()
        ->where('action', ClientAudit::CREATED)
        ->where('target_id', $copied->client_id)
        ->first());

    expect($created?->actor_type)->toBe(ActorType::OrganizationMember)
        ->and($created?->actor_id)->toBe($tenant->owner->id);

    // Nothing changed here.
    expect(appsIn($tenant->environment))->toHaveCount(1);
});

it('copies only into an environment of this project that the person administers', function (): void {
    ['tenant' => $tenant, 'staging' => $staging] = promotionWorkspace();
    confirmConsoleStepUp();
    $app = promotableApp();

    // Another project of the same workspace: another product, with its own plan.
    $otherProject = app(TenantProvisioner::class)->addProject($tenant->organization, 'Other product');
    $elsewhere = app(TenantProvisioner::class)->addEnvironment($otherProject, 'Production');

    $refusal = 'Choose one of the environments offered. It has to be in this project, and one you administer.';

    foreach ([$elsewhere->id, $tenant->environment->id, 'nonsense'] as $target) {
        test()->from(route('environment.clients.show', $app->id))
            ->post(route('environment.clients.copy', $app->id), ['environment' => $target, 'name' => 'Copy', 'redirectUris' => ''])
            ->assertSessionHasErrors(['environment' => $refusal]);
    }

    // …and the project's own Staging, once this person's access no longer reaches it.
    app(PlatformRoot::class)->run(fn () => app(Memberships::class)->setEnvironmentAccess(
        $tenant->organization->id,
        $tenant->owner->id,
        false,
        [$tenant->environment->id],
    ));

    expect(array_column((array) (test()->get(route('environment.clients.show', $app->id))->inertiaProps('appHeader'))['copy']['targets'], 'id'))->toBe([]);

    test()->from(route('environment.clients.show', $app->id))
        ->post(route('environment.clients.copy', $app->id), ['environment' => $staging->id, 'name' => 'Copy', 'redirectUris' => ''])
        ->assertSessionHasErrors(['environment' => $refusal]);

    expect(appsIn($staging))->toBe([])
        ->and(appsIn($elsewhere))->toBe([]);
})->group('security');

it('asks for a password before copying an app', function (): void {
    ['staging' => $staging] = promotionWorkspace();
    $app = promotableApp();

    test()->from(route('environment.clients.show', $app->id))
        ->post(route('environment.clients.copy', $app->id), ['environment' => $staging->id, 'name' => 'Copy', 'redirectUris' => ''])
        ->assertRedirect(route('environment.sudo'));

    expect(appsIn($staging))->toBe([]);
})->group('security');

it('does not copy an organization\'s app, and says why', function (): void {
    ['staging' => $staging] = promotionWorkspace();
    confirmConsoleStepUp();
    $org = app(Organizations::class)->create(new NewOrganization('Retail', 'retail'));
    $app = promotableApp($org->id);

    $copy = (array) (test()->get(route('environment.clients.show', $app->id))->inertiaProps('appHeader'))['copy'];

    expect($copy['unavailable'])->toStartWith('This app belongs to an organization, and an organization exists in one environment only.');

    test()->from(route('environment.clients.show', $app->id))
        ->post(route('environment.clients.copy', $app->id), ['environment' => $staging->id, 'name' => 'Copy', 'redirectUris' => ''])
        ->assertSessionHas('error', $copy['unavailable']);

    expect(appsIn($staging))->toBe([]);
});

it('offers no copy on an organization\'s console, which has no other environment', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $app = promotableApp($org->id);

    $header = (array) test()->get(route('clients.show', $app->id))->assertOk()->inertiaProps('appHeader');

    expect($header['copy'])->toBeNull()
        ->and($header['blueprintHref'])->toBe(route('clients.blueprint', $app->id))
        ->and(Route::has('clients.copy'))->toBeFalse();
});
