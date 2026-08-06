<?php

declare(strict_types=1);

use Cbox\Id\Api\Support\ServerMetadata;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/**
 * Every issuer surface must agree, on every host.
 *
 * The authorization response used to build its RFC 9207 `iss` from the platform APEX
 * (config('cbox-id.issuer')) while discovery and the id_token used the per-environment
 * resolver. A mix-up-hardened RP — node openid-client v5+, Spring Security 6, AppAuth,
 * any FAPI client — compares the callback's `iss` against discovery per RFC 9207 §2.4
 * and aborts. Login was impossible for every environment that was not the platform root,
 * and the platform root is exactly where a developer tests.
 */
it('serves an authorization_endpoint under the issuer, and advertises RFC 9207', function (): void {
    $document = app(ServerMetadata::class)->document();

    expect($document['authorization_endpoint'])
        ->toBe($document['issuer'].'/oauth/authorize')
        ->and($document['authorization_response_iss_parameter_supported'])->toBeTrue();
});

it('builds the authorization-response iss from the same resolver as discovery', function (): void {
    $document = app(ServerMetadata::class)->document();

    // This is the value the consent screen now appends to the redirect. Previously it
    // read config('cbox-id.issuer') — one global apex — so on any tenant host it
    // disagreed with the document above.
    expect(app(IssuerResolver::class)->issuer())->toBe($document['issuer']);
});

it('serves every field OpenID Connect Discovery marks REQUIRED', function (): void {
    $document = app(ServerMetadata::class)->document();

    $required = [
        'issuer',
        'authorization_endpoint',
        'token_endpoint',
        'jwks_uri',
        'response_types_supported',
        'subject_types_supported',
        'id_token_signing_alg_values_supported',
    ];

    $missing = array_values(array_diff($required, array_keys($document)));

    expect($missing)->toBe([], 'Discovery is missing REQUIRED field(s): '.implode(', ', $missing));
});
