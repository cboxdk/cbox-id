<?php

declare(strict_types=1);

use App\Platform\Appearance\ThemeRadius;
use Cbox\Id\FrontendApi\Contracts\PublishableKeys;
use Cbox\Id\FrontendApi\Enums\KeyMode;
use Cbox\Id\FrontendApi\FrontendApiServiceProvider;
use Cbox\Id\FrontendApi\Models\PublishableKey;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->app['config']->set('cbox-id.frontend_api.enabled', true);
    (new FrontendApiServiceProvider($this->app))->boot();
});

it('creates a key with its origins from the console', function (): void {
    actAsEnvironmentAdminOfATenant();

    issueFrontendKey([
        'mode' => 'live',
        'origins' => "https://acme.test\nhttps://www.acme.test",
    ])->assertSessionHasNoErrors();

    $key = PublishableKey::query()->firstOrFail();

    expect($key->key)->toStartWith('pk_live_')
        ->and($key->origins)->toHaveCount(2);
});

/**
 * A dropped entry leaves somebody holding a key that does not work, so the refusal has to
 * reach the FIELD — a toast disappears while they are still reading which line is wrong.
 */
it('refuses the whole list on the field when one origin is unusable', function (): void {
    actAsEnvironmentAdminOfATenant();

    issueFrontendKey(['name' => 'Site', 'origins' => "https://good.test\nnot-an-origin"])
        ->assertSessionHasErrors('origins');

    expect(PublishableKey::query()->count())->toBe(0);
});

it('refuses plain http away from loopback, and says why', function (): void {
    actAsEnvironmentAdminOfATenant();

    issueFrontendKey(['name' => 'Site', 'origins' => 'http://acme.test'])
        ->assertSessionHasErrors('origins');

    expect(PublishableKey::query()->count())->toBe(0);
});

it('revokes a key so pages holding it stop working', function (): void {
    actAsEnvironmentAdminOfATenant();

    $key = app(PublishableKeys::class)->issue('Site', KeyMode::Test, ['https://acme.test']);

    test()->from(route('environment.frontend-keys'))
        ->delete(route('environment.frontend-keys.destroy', $key->id))
        ->assertSessionHasNoErrors();

    expect(PublishableKey::query()->find($key->id)?->isActive())->toBeFalse();
});

/**
 * Every other credential screen reveals a secret once and shows a prefix afterwards. Doing
 * that here teaches the opposite of the truth: a person who cannot re-read their
 * publishable key concludes it is sensitive and proxies it through their own server,
 * which loses the entire point of the channel.
 */
it('keeps showing the key in full, because it is not a secret', function (): void {
    actAsEnvironmentAdminOfATenant();

    $key = app(PublishableKeys::class)->issue('Site', KeyMode::Test, ['https://acme.test']);

    test()->get(route('environment.frontend-keys'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'keys',
            fn (Collection $keys): bool => $keys->pluck('key')->contains($key->key),
        ));
});

/**
 * The refusal is now an ordinary one, and can be asserted as one.
 *
 * Under Volt the gate fired while the component was being MOUNTED, and Livewire reported
 * that as a broken snapshot rather than as the authorization error underneath — so the
 * test had to swallow whichever exception arrived and assert only that no key existed.
 * A request either carries the capability or it does not.
 */
it('refuses a member who may not administer', function (): void {
    /*
     * A DEPLOYMENT THAT SERVES THE PAGE, then somebody acting as a tenant on it. The route
     * has to exist for the refusal to be about authorization: `/admin` 404s outright on a
     * single-tenant install, and on a multi-tenant one it 404s unless an environment claims
     * the host — both are different answers to different questions.
     */
    actAsEnvironmentAdminOfATenant();
    actingAsRole(MembershipRole::Member);

    /*
     * BOUNCED, not shown. The plane middleware answers first: somebody holding an
     * organization session at an environment-plane URL is sent through the open-environment
     * handoff, which is where a person in that state belongs — and which refuses in turn if
     * the environment is not theirs. The scope's own `assertMayAdministerEnvironment()` is
     * the second layer behind it, and is what the write below meets.
     */
    test()->get(route('environment.frontend-keys'))
        ->assertRedirectContains('/open/');

    issueFrontendKey(['name' => 'Sneaky'])->assertRedirectContains('/open/');

    expect(PublishableKey::query()->count())->toBe(0);
});

/**
 * WITHOUT THIS THE CHANNEL IS HALF USEFUL: a page learns where to POST and then renders
 * the form in OUR colours, which is the tell that gives away an embedded widget as
 * somebody else's.
 */
it('puts the environment theme on the public config document', function (): void {
    $key = app(PublishableKeys::class)->issue('Site', KeyMode::Test, ['https://acme.test']);

    // The environment ROW has to exist — the key carries an id, and the contributor reads
    // the theme off the record. Without it there is nothing to theme from and the
    // document correctly says nothing, which is what this test would otherwise be
    // asserting by accident.
    Environment::withoutGlobalScopes()->create([
        'id' => $key->environment_id,
        'name' => 'Test',
        'slug' => 'test-env',
        'settings' => ['appearance' => ['preset' => 'midnight', 'radius' => ThemeRadius::None->value]],
    ]);

    $this->withHeaders([
        'X-Cbox-Publishable-Key' => $key->key,
        'Origin' => 'https://acme.test',
    ])->getJson('/frontend/v1/config')
        ->assertOk()
        ->assertJsonPath('appearance.radius', ThemeRadius::None->value);
});

it('says nothing about appearance when the environment has set none', function (): void {
    $key = app(PublishableKeys::class)->issue('Site', KeyMode::Test, ['https://acme.test']);

    // Silence rather than an empty object: an empty one reads as "themed, with no
    // colours", which renders a blank sign-in box.
    $this->withHeaders([
        'X-Cbox-Publishable-Key' => $key->key,
        'Origin' => 'https://acme.test',
    ])->getJson('/frontend/v1/config')
        ->assertOk()
        ->assertJsonMissingPath('appearance');
});

/**
 * THE ALLOW-LIST IS THE CONTROL, so it has to be changeable.
 *
 * `setOrigins()` existed on the contract with no caller anywhere — the page offered create
 * and revoke only. Adding a staging domain therefore meant minting a second key and
 * shipping a new bundle, and changing a list meant an outage you scheduled yourself.
 */
it('edits a key allow-list without minting a new key', function (): void {
    actAsEnvironmentAdminOfATenant();

    $key = app(PublishableKeys::class)->issue('Site', KeyMode::Test, ['https://acme.test']);

    // The current list reaches the page, which is what the edit form opens with.
    test()->get(route('environment.frontend-keys'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where(
            'keys',
            fn (Collection $keys): bool => $keys->firstWhere('id', $key->id)['origins'] === ['https://acme.test'],
        ));

    test()->from(route('environment.frontend-keys'))
        ->put(route('environment.frontend-keys.origins', $key->id), [
            'origins' => "https://acme.test\nhttps://staging.acme.test",
        ])
        ->assertSessionHasNoErrors();

    expect($key->fresh()?->origins()->pluck('origin')->all())
        ->toBe(['https://acme.test', 'https://staging.acme.test']);
});

it('refuses the whole edited list when one origin is unusable, and says which', function (): void {
    actAsEnvironmentAdminOfATenant();

    $key = app(PublishableKeys::class)->issue('Site', KeyMode::Test, ['https://acme.test']);

    test()->from(route('environment.frontend-keys'))
        ->put(route('environment.frontend-keys.origins', $key->id), [
            'origins' => "https://acme.test\nhttps://acme.test/app",
        ])
        ->assertSessionHasErrors('origins');

    // Unchanged: a list that half-applied would be a key that works in half the places
    // somebody just told it to.
    expect($key->fresh()?->origins()->pluck('origin')->all())->toBe(['https://acme.test']);
});

/**
 * THE PAGE IS NOT ON THE ORGANIZATION PLANE. Publishable keys are environment-owned with no
 * organization column, so on that plane every organization's administrator would be
 * administering — and revoking — every other organization's keys.
 */
it('is absent from the organization console rather than present and refusing', function (): void {
    expect(Route::has('frontend-keys'))->toBeFalse()
        ->and(Route::has('environment.frontend-keys'))->toBeTrue();
});

it('refuses an organization administrator who types the environment URL', function (): void {
    /*
     * There is no organization-plane route, so the only way to reach this at all is the
     * environment plane's URL — and the scope refuses somebody acting as a tenant on it.
     * The deployment has to serve that URL first, or the refusal is the prefix's.
     */
    actAsEnvironmentAdminOfATenant();
    actingAsRole(MembershipRole::Owner);

    test()->get(route('environment.frontend-keys'))->assertRedirectContains('/open/');
    issueFrontendKey(['name' => 'Theirs', 'origins' => 'https://theirs.test'])
        ->assertRedirectContains('/open/');

    expect(PublishableKey::query()->count())->toBe(0);
});

/**
 * AN UNCLOSED DIV SWALLOWED THE PAGE.
 *
 * The create panel's `cbx-panel-body` was never closed, so per the HTML parsing rules the
 * `</form>` closed nothing and everything after it — the key count, the empty state, the
 * whole table — became a descendant of the still-open form whenever the panel was expanded.
 * The table rendered inside the form's chrome, and the Revoke button, which had no `type`,
 * defaulted to `submit` and posted the create form on its way past.
 *
 * Asserted through a real parse rather than by eye: this is exactly the class of defect a
 * suite cannot see, because every Livewire assertion in this file passes either way.
 */
/*
 * The create form and the key list are SIBLINGS, which used to need saying because they
 * were not: the list rendered inside the form element, so a browser treated every control
 * on every key as part of the submission — Enter anywhere reached the wrong handler, and
 * the form's own reset cleared fields that belonged to other keys.
 *
 * It is structural, so it can only be checked where structure exists. See
 * tests/Browser/FrontendKeysTest.php.
 */
