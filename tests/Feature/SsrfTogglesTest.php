<?php

declare(strict_types=1);

use App\Platform\Integrations\SsrfToggles;

/*
 * `CBOX_ID_ACTIONS_VERIFY_URL=1` (or `on`, `yes`) reaches the config as a string. The
 * registration guards read it as on, and inline-hook delivery, comparing `=== true`, read
 * it as off: no SSRF pin at send time, so a hook host rebound to an internal address after
 * registration got the signed hook context (security finding #56). Stored once as the
 * boolean the guards agree on, every reader sees the same answer.
 */
it('stores every SSRF toggle as the boolean the guards read it as', function (mixed $raw, bool $expected): void {
    foreach (SsrfToggles::KEYS as $key) {
        config([$key => $raw]);
    }

    SsrfToggles::apply(config());

    foreach (SsrfToggles::KEYS as $key) {
        expect(config($key))->toBe($expected, $key);
    }
})->with([
    'true' => [true, true],
    '"1"' => ['1', true],
    '"on"' => ['on', true],
    '"yes"' => ['yes', true],
    '"true"' => ['true', true],
    'false' => [false, false],
    '"0"' => ['0', false],
    '"off"' => ['off', false],
    'unrecognised stays on' => ['maybe', true],
])->group('security');

it('applies at boot, and every key it names exists', function (): void {
    foreach (SsrfToggles::KEYS as $key) {
        expect(config()->has($key))->toBeTrue($key)
            ->and(config($key))->toBeBool($key);
    }
});
