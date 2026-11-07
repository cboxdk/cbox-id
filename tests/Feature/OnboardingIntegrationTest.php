<?php

declare(strict_types=1);

use App\Platform\Onboarding\IntegrationProgress;
use Cbox\Engage\Contracts\ProductEvents;
use Cbox\Engage\Events\ProductEvent;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;
use Cbox\Id\Platform\Contracts\EnvironmentApiKeys;

it('shares tenant scoped facts without calling a configured redirect production ready', function (): void {
    multiTenantDeployment();
    $tenant = provisionAccount();
    app(EnvironmentContext::class)->set(GenericEnvironment::of($tenant['environment']->id));
    app(ClientRegistry::class)->register(new NewClient('App', redirectUris: ['https://app.example/callback']));
    $snapshot = app(IntegrationProgress::class)->read();
    expect($snapshot['environment_id'])->toBe($tenant['environment']->id)
        ->and($snapshot['workspace_id'])->toBe($tenant['organization']->id)
        ->and($snapshot['goals']['create_app'])->toBeTrue()
        ->and($snapshot['goals']['first_sign_in'])->toBeFalse()
        ->and($snapshot['goals']['go_live'])->toBeTrue()
        ->and($snapshot['production_ready'])->toBeNull();
});

it('keeps collection off until an operator configures the intended product', function (): void {
    $events = Mockery::mock(ProductEvents::class);
    $events->shouldNotReceive('record');
    app()->instance(ProductEvents::class, $events);
    $this->artisan('onboarding:sync')->assertFailed();
});

it('publishes only workspace facts and deduplicates the same observation interval', function (): void {
    multiTenantDeployment();
    $tenant = provisionAccount();
    config(['onboarding.engage_enabled' => true, 'engage.url' => 'https://engage.example', 'engage.key' => 'test-only', 'engage.product' => 'cbox-id']);
    $captured = [];
    $events = Mockery::mock(ProductEvents::class);
    $events->shouldReceive('record')->andReturnUsing(function (ProductEvent $event) use (&$captured): void {
        $captured[] = $event;
    });
    app()->instance(ProductEvents::class, $events);
    $this->freezeTime();
    $this->artisan('onboarding:sync')->assertSuccessful();
    $this->artisan('onboarding:sync')->assertSuccessful();
    expect($captured)->toHaveCount(2)
        ->and($captured[0]->eventId)->toBe($captured[1]->eventId)
        ->and($captured[0]->subjectExternalId)->toBe($tenant['organization']->id)
        ->and($captured[0]->props['environment_id'])->toBe($tenant['environment']->id)
        ->and($captured[0]->props['identity_namespace'])->toBe('cbox-id-platform-root')
        ->and(array_keys($captured[0]->props))->not->toContain('email', 'name', 'password');
    app(EnvironmentContext::class)->runAs($tenant['environment'], fn () => app(ClientRegistry::class)->register(new NewClient('Created during same minute')));
    $this->artisan('onboarding:sync')->assertSuccessful();
    expect($captured)->toHaveCount(3)
        ->and($captured[2]->eventId)->not->toBe($captured[0]->eventId)
        ->and($captured[2]->props['goal_create_app'])->toBeTrue();

});

it('makes the customer safe onboarding guide available for Assistant citations', function (): void {
    $this->get('/ai-assistant/guides/id-onboarding')->assertOk()
        ->assertSee('without a credit card')->assertSee('SCIM');
});

it('exposes current progress only through the environment key and required scope', function (): void {
    multiTenantDeployment();
    $tenant = provisionAccount();
    serveOnTestHost($tenant['environment']);
    $key = app(EnvironmentApiKeys::class)->issue($tenant['environment']->id, 'Read onboarding', ['signin:read'])->plaintext;
    $this->getJson('/api/v1/onboarding')->assertUnauthorized();
    $this->withToken($key)->getJson('/api/v1/onboarding')->assertOk()
        ->assertJsonPath('data.environment_id', $tenant['environment']->id)
        ->assertJsonPath('data.production_ready', null);
    $wrong = app(EnvironmentApiKeys::class)->issue($tenant['environment']->id, 'Wrong scope', ['users:read'])->plaintext;
    $this->withToken($wrong)->getJson('/api/v1/onboarding')->assertForbidden();
});
