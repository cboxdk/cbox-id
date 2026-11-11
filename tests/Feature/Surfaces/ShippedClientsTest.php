<?php

declare(strict_types=1);

use App\Platform\Api\CredentialEnvironment;
use Cbox\Id\Devices\Support\EnrolmentHost;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\OAuthServer\DeviceAuthorizationService;
use Illuminate\Http\Request;

/**
 * THE SHIPPED CLIENTS (the Authenticator app, the CLIs) IN LOGIN-HOST MODE: what the server
 * hands them names the issuer's host, and nothing under an old issuer name is accepted.
 */
function standingOn(string $host, string $path = '/'): void
{
    app()->instance('request', Request::create('https://'.$host.$path));
}

it('names the login host in an enrolment code shown on the app host', function (): void {
    loginHostMode();
    standingOn(APP_HOST, '/settings/devices');

    // The app asks this host for discovery and requires `issuer` to be exactly it.
    expect(EnrolmentHost::name())->toBe(LOGIN_HOST)
        ->and('https://'.EnrolmentHost::name())->toBe(app(IssuerResolver::class)->issuer());
});

it('names the host the page is on everywhere else', function (): void {
    loginHostMode();
    standingOn(TENANT_HOST, '/settings/devices');

    expect(EnrolmentHost::name())->toBe(TENANT_HOST);

    // Single-host mode: unchanged.
    config(['cbox-id.hosts.login' => null]);
    standingOn('id.example.test', '/settings/devices');

    expect(EnrolmentHost::name())->toBe('id.example.test');
});

it('sends the device grant to the login host to be approved', function (): void {
    loginHostMode();

    $uri = (new ReflectionMethod(DeviceAuthorizationService::class, 'verificationUri'))
        ->invoke(app(DeviceAuthorizationService::class));

    expect($uri)->toBe('https://'.LOGIN_HOST.'/device');
});

it('pins no environment for a token whose issuer is a legacy name', function (): void {
    loginHostMode();
    config(['cbox-id.hosts.legacy_login' => ['cbox.cboxid.test', APP_HOST]]);

    $credentials = app(CredentialEnvironment::class);

    expect($credentials->ofIssuer('https://'.LOGIN_HOST))->not->toBeNull()
        ->and($credentials->ofIssuer('https://cbox.cboxid.test'))->toBeNull()
        // The issuer before the split: the app host.
        ->and($credentials->ofIssuer('https://'.APP_HOST))->toBeNull();
})->group('security');
