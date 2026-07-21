<?php

declare(strict_types=1);

use App\Rules\SecureRedirectUri;

it('accepts https and loopback http, rejects cleartext and dangerous schemes', function (string $uri, bool $ok): void {
    expect(SecureRedirectUri::isSecure($uri))->toBe($ok);
})->with([
    ['https://app.example.com/callback', true],
    ['https://app.example.com/cb?x=1', true],
    ['http://localhost:3000/cb', true],
    ['http://127.0.0.1:8080/cb', true],
    ['com.example.app://oauth2redirect', true],       // reverse-domain native scheme
    ['http://app.example.com/cb', false],             // cleartext on a public host
    ['https://app.example.com/cb#frag', false],       // fragment
    ['javascript:alert(1)', false],                   // dangerous single-word scheme
    ['data:text/html,x', false],
    ['app:/cb', false],                               // dotless custom scheme
    ['not-a-url', false],
]);
