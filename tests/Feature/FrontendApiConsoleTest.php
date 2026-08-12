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
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->app['config']->set('cbox-id.frontend_api.enabled', true);
    (new FrontendApiServiceProvider($this->app))->boot();
});

it('creates a key with its origins from the console', function (): void {
    actAsEnvironmentAdminOfATenant();

    Volt::test('console.frontend-keys')
        ->set('name', 'Marketing site')
        ->set('mode', 'live')
        ->set('origins', "https://acme.test\nhttps://www.acme.test")
        ->call('create')
        ->assertHasNoErrors();

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

    Volt::test('console.frontend-keys')
        ->set('name', 'Site')
        ->set('origins', "https://good.test\nnot-an-origin")
        ->call('create')
        ->assertHasErrors('origins');

    expect(PublishableKey::query()->count())->toBe(0);
});

it('refuses plain http away from loopback, and says why', function (): void {
    actAsEnvironmentAdminOfATenant();

    Volt::test('console.frontend-keys')
        ->set('name', 'Site')
        ->set('origins', 'http://acme.test')
        ->call('create')
        ->assertHasErrors('origins');
});

it('revokes a key so pages holding it stop working', function (): void {
    actAsEnvironmentAdminOfATenant();

    $key = app(PublishableKeys::class)->issue('Site', KeyMode::Test, ['https://acme.test']);

    Volt::test('console.frontend-keys')->call('revoke', $key->id);

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

    Volt::test('console.frontend-keys')->assertSee($key->key);
});

/**
 * The gate is in boot(), so it fires while the component is being MOUNTED — before any
 * action. Livewire reports that as a broken snapshot rather than as the authorization
 * error underneath, so the assertion is on the outcome that matters: a member who may not
 * administer never gets a usable component, and no key is created.
 */
it('refuses a member who may not administer', function (): void {
    actingAsRole(MembershipRole::Member);

    try {
        Volt::test('console.frontend-keys')
            ->set('name', 'Sneaky')
            ->set('origins', 'https://acme.test')
            ->call('create');
    } catch (Throwable) {
        // Either the authorization exception or Livewire's report of it — both mean
        // refused, and asserting on which one would be asserting on Livewire's internals.
    }

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

    Volt::test('console.frontend-keys')
        ->call('edit', $key->id)
        ->assertSet('editOrigins', 'https://acme.test')
        ->set('editOrigins', "https://acme.test\nhttps://staging.acme.test")
        ->call('saveOrigins')
        ->assertHasNoErrors();

    expect($key->fresh()?->origins()->pluck('origin')->all())
        ->toBe(['https://acme.test', 'https://staging.acme.test']);
});

it('refuses the whole edited list when one origin is unusable, and says which', function (): void {
    actAsEnvironmentAdminOfATenant();

    $key = app(PublishableKeys::class)->issue('Site', KeyMode::Test, ['https://acme.test']);

    Volt::test('console.frontend-keys')
        ->call('edit', $key->id)
        ->set('editOrigins', "https://acme.test\nhttps://acme.test/app")
        ->call('saveOrigins')
        ->assertHasErrors('editOrigins');

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

it('refuses an organization administrator who reaches the component directly', function (): void {
    actingAsRole(MembershipRole::Owner);

    try {
        Volt::test('console.frontend-keys')
            ->set('name', 'Theirs')
            ->set('origins', 'https://theirs.test')
            ->call('create');
    } catch (Throwable) {
        // The scope refuses on this plane before the action runs.
    }

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
it('keeps the key table outside the create form', function (): void {
    actAsEnvironmentAdminOfATenant();

    app(PublishableKeys::class)->issue('Site', KeyMode::Test, ['https://acme.test']);

    $html = Volt::test('console.frontend-keys')->set('creating', true)->html();

    $document = new DOMDocument;
    $document->loadHTML('<!doctype html><html lang="en"><body>'.$html.'</body></html>', LIBXML_NOERROR);

    $xpath = new DOMXPath($document);

    expect($xpath->query('//form//table')?->length)->toBe(0, 'the key table parsed as part of the create form')
        ->and($xpath->query('//table')?->length)->toBe(1, 'the key table did not render at all');
});
