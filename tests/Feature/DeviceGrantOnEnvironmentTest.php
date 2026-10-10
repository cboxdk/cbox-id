<?php

declare(strict_types=1);

use App\Platform\AppKind;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\ValueObjects\NewClient;

/*
| An ENVIRONMENT's own app — registered as "CLI or device" — can run the whole TV pattern on
| the environment's host: ask for a code, draw its QR code, and be pointed at the hosted
| page that approves it. The root's CLI client is covered elsewhere; this is the customer's.
*/

it('lets an environment\'s own CLI-or-device app start the device grant and draw its QR code', function (): void {
    multiTenantDeployment();
    $tenant = provisionAccount();
    $environment = serveOnTestHost($tenant['environment']);
    app(EnvironmentContext::class)->set(GenericEnvironment::of($environment->id));

    $kind = AppKind::CliOrDevice;
    $client = app(ClientRegistry::class)->register(new NewClient(
        name: 'Acme TV',
        type: $kind->clientType(),
        grantTypes: $kind->grantTypes(),
        scopes: $kind->defaultScopes(),
    ))->client;

    $started = $this->post('/oauth/device_authorization', [
        'client_id' => $client->client_id,
        'scope' => 'openid profile offline_access',
    ])->assertOk()->json();

    expect($started['verification_uri_complete'])->toEndWith('/device?user_code='.$started['user_code'])
        ->and($started['interval'])->toBeInt();

    $this->get('/oauth/device/qr?user_code='.$started['user_code'])
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml');
});
