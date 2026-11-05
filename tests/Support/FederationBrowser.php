<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use RuntimeException;

/** Carry the real host-only flow cookie, as a browser does between redirect and callback. */
final class FederationBrowser
{
    public static function carryFlowCookie(TestResponse $response): void
    {
        $cookies = array_values(array_filter($response->headers->getCookies(), static fn ($cookie): bool => str_starts_with($cookie->getName(), '__Host-cbox_fed_')));
        expect($cookies)->toHaveCount(1);
        $cookie = $cookies[0];
        expect($cookie->isSecure())->toBeTrue()
            ->and($cookie->isHttpOnly())->toBeTrue()
            ->and($cookie->getDomain())->toBeNull()
            ->and($cookie->getPath())->toBe('/')
            ->and($cookie->getSameSite())->toBe('none');
        $decrypted = $response->getCookie($cookie->getName());
        if ($decrypted === null || $decrypted->getValue() === null) {
            throw new RuntimeException('The redirect did not issue a usable flow cookie.');
        }
        test()->withCookie($cookie->getName(), $decrypted->getValue());
    }
}
