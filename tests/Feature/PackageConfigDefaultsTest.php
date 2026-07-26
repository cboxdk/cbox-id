<?php

declare(strict_types=1);
use Cbox\Id\Identity\Models\User;

/**
 * This app publishes a PARTIAL `config/cbox-id.php`: it names `oauth` and
 * `webauthn` but restates only one or two keys under each. The framework's
 * `mergeConfigFrom()` is a shallow `array_merge`, so those partial blocks used to
 * REPLACE the package's whole block — silently deleting every default this file
 * did not repeat.
 *
 * Nothing crashed, because every consumer in the package passes an in-code
 * fallback to `config()`. What broke was the env layer: an operator could set
 * `CBOX_ID_ACCESS_TOKEN_TTL`, `CBOX_ID_DCR_MODE`, `CBOX_ID_REQUIRE_PAR`,
 * `CBOX_ID_DECISIONS_MAX_BATCH`, `CBOX_ID_CIBA_*` or
 * `CBOX_ID_WEBAUTHN_USER_VERIFICATION` and get nothing at all.
 *
 * These tests assert reachability, not values: a key the app never mentions must
 * still arrive from the package, so the env var behind it has somewhere to land.
 */
it('still resolves package oauth defaults this app does not restate', function (): void {
    expect(config('cbox-id.oauth.access_token_ttl'))->toBe(900)
        ->and(config('cbox-id.oauth.require_par'))->toBeFalse()
        ->and(config('cbox-id.oauth.embed_entitlements'))->toBeTrue()
        ->and(config('cbox-id.oauth.decisions.max_batch'))->toBe(50)
        ->and(config('cbox-id.oauth.ciba.ttl_seconds'))->toBe(300)
        ->and(config('cbox-id.oauth.ciba.poll_interval'))->toBe(5)
        ->and(config('cbox-id.oauth.dynamic_registration.mode'))->toBe('disabled')
        ->and(config('cbox-id.oauth.dynamic_registration.allowed_scopes'))->toContain('openid')
        ->and(config()->has('cbox-id.oauth.authorization_endpoint'))->toBeTrue()
        ->and(config()->has('cbox-id.oauth.dynamic_registration.initial_access_token'))->toBeTrue();
});

it('still resolves the package webauthn default this app does not restate', function (): void {
    expect(config('cbox-id.webauthn.user_verification'))->toBeTrue();
});

it('keeps this app winning on every key it does state', function (): void {
    // The host must not be overwritten by the defaults it sits on top of.
    expect(config('cbox-id.oauth.authorization_endpoint_path'))->toBe('/oauth/authorize')
        ->and(config('cbox-id.models.user'))->toBe(User::class);
});

it('replaces rather than appends to a list this app redefines', function (): void {
    // `api.middleware` is a sequential array. A merge that concatenated would make
    // the app unable to shrink or empty a package-supplied list.
    expect(config('cbox-id.api.middleware'))->toBe(['plane:subject']);
});

it('leaves no package config key at any depth unreachable', function (): void {
    // The broad net: walk the package's shipped defaults and assert every single
    // path is resolvable here. This is the check that would have caught the bug on
    // the day the partial `oauth` block was added, and that catches the next
    // partial block someone adds to config/cbox-id.php.
    $packageDefaults = require base_path('vendor/cboxdk/laravel-id/config/cbox-id.php');

    expect($packageDefaults)->toBeArray();

    /**
     * @param  array<array-key, mixed>  $defaults
     * @return list<string>
     */
    $paths = function (array $defaults, string $prefix) use (&$paths): array {
        $collected = [];

        foreach ($defaults as $key => $value) {
            $path = $prefix.'.'.$key;
            $collected[] = $path;

            // Recurse into namespaces of settings only. A LIST is a single value —
            // the host is entitled to replace it, so its entries are not paths.
            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $collected = [...$collected, ...$paths($value, $path)];
            }
        }

        return $collected;
    };

    /** @var array<array-key, mixed> $packageDefaults */
    $unreachable = array_values(array_filter(
        $paths($packageDefaults, 'cbox-id'),
        static fn (string $path): bool => ! config()->has($path),
    ));

    expect($unreachable)->toBe([]);
});
