<?php

declare(strict_types=1);

use App\Platform\CspNonce;

/**
 * The policy has to describe the application's real topology. Both cases here were live
 * production failures, and neither produced a single PHP-level symptom — the server was
 * happy, the browser refused, and the only evidence was in a console nobody had open.
 */
function cspPolicy(string $uri = '/'): string
{
    return test()->get($uri)->headers->get('Content-Security-Policy') ?? '';
}

function cspDirective(string $name, string $uri = '/'): string
{
    foreach (explode(';', cspPolicy($uri)) as $part) {
        $part = trim($part);

        if (str_starts_with($part, $name.' ')) {
            return $part;
        }
    }

    return '';
}

it('permits a form submission to finish on the account host', function (): void {
    // Signing in is unified on the platform root, so a session that ENDS on an
    // environment host finishes on another one: POST /admin/logout redirects to
    // /admin/login, which redirects to the root's /workspace/login. Browsers check
    // form-action against every hop of a submission's redirect chain, so under
    // `form-action 'self'` logout was refused outright — the most basic control in the
    // console, dead on a policy written for an app that lives on one origin.
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);

    expect(cspDirective('form-action'))->toContain('cboxid.com');
})->group('security');

it('does not name tenant-controlled hosts in form-action', function (): void {
    // The fix must stay narrow. Environment and white-label hosts are chosen by
    // customers; naming them would let a form post credentials to any domain a tenant
    // can point at us, which is worse than the bug it would be fixing.
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);

    $formAction = cspDirective('form-action');

    expect($formAction)->toBe('form-action \'self\' cboxid.com');
})->group('security');

it('names no extra host when the deployment is single-origin', function (): void {
    // Self-hosted single tenant: one host serves everything, so there is no second
    // origin and the policy must not invent one.
    config()->set('cbox-id.environments.base_domains', []);

    expect(cspDirective('form-action'))->toBe("form-action 'self'");
})->group('security');

it('carries a nonce in script-src so the CDN can execute its injected script', function (): void {
    // Cloudflare's JavaScript Detections injects an INLINE script after the response
    // leaves PHP. It parses this header and copies the nonce onto that script; without
    // one it is refused on every page the console serves. The value has to be in the
    // HEADER — Cloudflare documents a nonce set via `<meta>` as unsupported.
    expect(cspDirective('script-src'))->toMatch("/'nonce-[A-Za-z0-9+\/=]{20,}'/");
})->group('security');

it('issues a different nonce to every request', function (): void {
    // A nonce that repeats is a nonce in name only: anyone who can read one page can
    // reuse the value on a page they are injecting into. This is the property that
    // makes the directive above worth more than 'unsafe-inline'.
    $first = cspDirective('script-src');

    // A fresh container, which is what a second request gets.
    app()->forgetScopedInstances();

    expect(cspDirective('script-src'))->not->toBe($first);
})->group('security');

it('generates one nonce per instance and holds it', function (): void {
    // Within a request the header and any markup carrying the value must agree — two
    // reads returning two values would mean a header permitting a script nobody sent.
    $nonce = new CspNonce;

    // strlen, not toHaveLength: the decoded value is 16 random BYTES, which are not
    // valid UTF-8, and a character-counting assertion silently reports fewer of them.
    expect($nonce->value())->toBe($nonce->value())
        ->and(strlen((string) base64_decode($nonce->value(), true)))->toBe(16);
})->group('security');
