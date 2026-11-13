<?php

declare(strict_types=1);
use Cbox\Id\Devices\Support\AuthenticatorLinks;
use Illuminate\Http\Request;

/*
 * The association documents Apple's CDN and Android's verifier fetch, and the page the
 * Universal Link shows when the app did not open. Both verifiers follow no redirect and
 * want a 200 with `application/json`.
 */

const AUTHENTICATOR_ULID = '01JD2Z7Q8M3K4N5P6R7S8T9V0W';

it('serves the apple app site association for the approve path', function (string $host): void {
    config(['id-devices.universal_links.ios_app_ids' => ['7CX7963T36.com.cboxid.authenticator']]);

    $response = $this->get('http://'.$host.'/.well-known/apple-app-site-association');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['applinks' => ['details' => [[
            'appIDs' => ['7CX7963T36.com.cboxid.authenticator'],
            'components' => [['/' => '/authenticator/approve/*', 'comment' => 'Opens one approval request in the Cbox ID app.']],
        ]]]]);
    expect($response->isRedirection())->toBeFalse()
        ->and($response->headers->get('Set-Cookie'))->toBeNull();
})->with(['localhost', 'acme.localhost']);

it('serves the android asset links for a configured package and certificate', function (): void {
    $print = implode(':', array_fill(0, 32, 'AB'));
    config(['id-devices.universal_links.android_package' => 'com.cboxid.authenticator',
        'id-devices.universal_links.android_sha256' => [strtolower($print), 'not-a-fingerprint']]);

    $this->get('/.well-known/assetlinks.json')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => ['namespace' => 'android_app', 'package_name' => 'com.cboxid.authenticator', 'sha256_cert_fingerprints' => [$print]],
        ]]);
});

it('answers 404 for documents nobody configured', function (string $path): void {
    config(['id-devices.universal_links.ios_app_ids' => ['not a team id'], 'id-devices.universal_links.android_package' => 'com.cboxid.authenticator',
        'id-devices.universal_links.android_sha256' => []]);

    $this->get($path)->assertNotFound();
})->with(['/.well-known/apple-app-site-association', '/.well-known/assetlinks.json']);

it('shows a phone how to continue when the app did not open', function (): void {
    config(['id-devices.app_store_url' => 'https://apps.apple.com/app/id6740000000']);

    $this->get('/authenticator/approve/'.strtolower(AUTHENTICATOR_ULID), ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_2 like Mac OS X)'])
        ->assertOk()
        ->assertSee('Open the Cbox ID app')
        ->assertSee('com.cboxid.authenticator://authenticator/approve/'.AUTHENTICATOR_ULID, false)
        ->assertSee('https://apps.apple.com/app/id6740000000', false)
        ->assertSee('Approve the request there');
});

it('keeps the fallback page in the visitor language and offers no app button on a desktop', function (): void {
    $this->get('/authenticator/approve/'.AUTHENTICATOR_ULID, ['Accept-Language' => 'da', 'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)'])
        ->assertOk()
        ->assertSee('Åbn Cbox ID-appen')
        ->assertDontSee('com.cboxid.authenticator://', false);
});

it('refuses an approve link that is not a request id', function (): void {
    $this->get('/authenticator/approve/not-an-id')->assertNotFound();
});

it('recognises a phone by its user agent or by Chromium\'s mobile client hint', function (array $headers, bool $phone): void {
    $request = Request::create('/mfa', 'GET', server: collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all());

    expect(AuthenticatorLinks::isPhone($request))->toBe($phone);
})->with([
    'iPhone Safari' => [['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_2 like Mac OS X)'], true],
    'Android Chrome' => [['User-Agent' => 'Mozilla/5.0 (Linux; Android 15; Pixel 9)'], true],
    'client hint' => [['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64)', 'Sec-CH-UA-Mobile' => '?1'], true],
    'desktop' => [['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', 'Sec-CH-UA-Mobile' => '?0'], false],
]);
