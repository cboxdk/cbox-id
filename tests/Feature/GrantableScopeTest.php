<?php

declare(strict_types=1);

use App\Platform\ScopeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * EVERY SCOPE THE CODE REQUIRES MUST BE GRANTABLE BY SOMETHING.
 *
 * This defect has now happened three times, and each time it looked different:
 *
 *  - `organizations` was advertised in discovery and emitted as a claim, and appeared in
 *    neither the console picker nor the dynamic-registration allow-list.
 *  - `groups` was added afterwards and missed both lists again, with the comment
 *    recording the first fix directly above the line where it was missing.
 *  - `decisions:read` gates `/oauth/decisions` once an operator turns
 *    `oauth.decisions.require_scope` on, and was offered nowhere — so switching that flag
 *    on made the endpoint unusable by every client at once.
 *
 * The shape is always the same: something in the code names a scope, and nothing can hand
 * it out. So the assertion is not about any particular scope — it is that a scope named as
 * a requirement is reachable through one of the two doors that exist.
 */
it('offers every scope the code demands through some door a client can walk', function (): void {
    $offered = collect(app(ScopeCatalog::class)->all())->pluck('key')->all();

    /** @var list<string> $registerable */
    $registerable = config('cbox-id.oauth.dynamic_registration.allowed_scopes', []);

    $grantable = array_unique([...$offered, ...$registerable]);

    // Scopes the code REQUIRES somewhere. Listed by hand rather than scraped, because a
    // scraper over string literals would find every scope ever mentioned in a comment and
    // this list is short enough to keep honest.
    $required = [
        'decisions:read',   // POST /oauth/decisions, behind oauth.decisions.require_scope
        'apps.manifest',    // POST /api/v1/apps/manifest
        'vault.manage',
        'vault.lease',
    ];

    expect(array_values(array_diff($required, $grantable)))->toBe(
        [],
        'a scope the code requires cannot be granted by the console or by dynamic registration',
    );
});

/**
 * And the mirror, which is how `groups` was caught: nothing may be ADVERTISED that a
 * self-registering client cannot ask for. A conformant client reads the document and
 * requests what it says exists.
 */
it('advertises no sign-in scope a self-registering client cannot ask for', function (): void {
    $advertised = $this->getJson('/.well-known/openid-configuration')->assertOk()->json('scopes_supported');

    /** @var list<string> $registerable */
    $registerable = config('cbox-id.oauth.dynamic_registration.allowed_scopes', []);

    // Platform-API scopes are deliberately not self-registerable — they reach this
    // deployment's own management surface, which is an operator's decision.
    $signIn = array_values(array_filter(
        $advertised,
        static fn (string $scope): bool => ! str_contains($scope, '.') && ! str_contains($scope, ':'),
    ));

    expect(array_values(array_diff($signIn, $registerable)))->toBe([]);
});
