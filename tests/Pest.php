<?php

declare(strict_types=1);

use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Models\AccountMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use PHPUnit\Framework\Assert as PHPUnit;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

// Pest 4 browser tests (real Chromium via Playwright) — boot the full app the same
// way, so `visit()` drives the running application with its middleware and DB.
uses(TestCase::class, RefreshDatabase::class)->in('Browser');

/**
 * Assert the component actually RENDERED — it did not redirect, at mount OR afterwards.
 *
 * USE THIS INSTEAD OF `assertNoRedirect()`. Livewire's own `assertNoRedirect()` is
 * VACUOUS for a redirect issued in `mount()`, and that asymmetry is a trap:
 *
 *   - `assertNoRedirect()` inspects ONLY the Livewire EFFECT payload
 *     (`$this->effects['redirect']`).
 *   - A redirect issued during `mount()` of a `Volt::test(...)` / `Livewire::test(...)`
 *     is an INITIAL render, not a Livewire message — it surfaces as an HTTP 302 on the
 *     underlying response and never reaches the effects array.
 *   - So `assertNoRedirect()` passes, silently, on a component that redirected at mount.
 *   - `assertRedirect()` does NOT have this hole: it falls back to the response when the
 *     request is not a Livewire request. Only the negative form is blind.
 *
 * A `max_age` P0 in the OAuth consent screen survived a test written to catch it for
 * exactly this reason. This macro closes both halves: HTTP 200 on the response (mount
 * rendered a page rather than a 302) AND no redirect effect (no action redirected).
 *
 * It still only says "nothing bad happened" — always pair it with a positive assertion
 * about what SHOULD have rendered or been set.
 */
Testable::macro('assertRenderedNotRedirected', function (): Testable {
    /** @var Testable $this */
    $this->assertStatus(200);

    PHPUnit::assertArrayNotHasKey(
        'redirect',
        $this->effects,
        'Component performed a redirect, but the test expected it to render.'
    );

    return $this;
});

/**
 * Stand up the PLATFORM-ROOT environment ("tenant 1"), the environment account members
 * live in as ordinary subjects. Idempotent — a deployment has exactly one, and so does a
 * test. Provision accounts AFTER calling this: an account provisioned with no root is in
 * the first-install bootstrap window, where its members have no subject yet.
 *
 * See docs/core-concepts/unified-account-identity.md.
 */
function platformRootEnvironment(): Environment
{
    $existing = Environment::query()->where('is_default', true)->first();

    if ($existing !== null) {
        return $existing;
    }

    return Environment::query()->create([
        'name' => 'Platform',
        'slug' => 'platform-root-'.Str::lower((string) Str::ulid()),
        'type' => EnvironmentType::Production,
        'status' => EnvironmentStatus::Active,
        'is_default' => true,
        'settings' => [],
    ]);
}

/**
 * Make an environment the one this test's HTTP host resolves to, by stamping the app
 * URL's host on it as a verified custom domain.
 *
 * Tests used to steer requests by pointing `environments.default` at an environment,
 * which worked only because no `is_default` row existed — SetEnvironment falls back to
 * the configured key only when there is no platform root at all. With a platform root in
 * place (as every real deployment has), an unmapped host resolves to the ROOT, so a test
 * that wants to be ON a tenant must reach it the way a tenant admin actually does: by its
 * own host.
 *
 * Idempotent and first-come: an environment can only own a host if no other one already
 * does, so a test that provisions several gets host resolution for the first.
 */
function serveOnTestHost(Environment $environment): Environment
{
    $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

    if ($host === '' || Environment::query()->where('domain', $host)->whereKeyNot($environment->id)->exists()) {
        return $environment;
    }

    $environment->forceFill(['domain' => $host, 'domain_verified_at' => now()])->save();

    return $environment;
}

/**
 * Seed an environment-admin session for an account member on an environment.
 *
 * The session is keyed on the member's PLATFORM-ROOT SUBJECT — the credential of record
 * — not on the membership row, so tests go through this rather than writing the raw key
 * and encoding the wrong shape in a dozen places.
 */
function actAsEnvironmentAdmin(AccountMember $member, string $environmentId): void
{
    session()->put(EnvironmentAdminAuth::SESSION_KEY, $member->refresh()->subject_id);
    session()->put(EnvironmentAdminAuth::ENV_KEY, $environmentId);
}
