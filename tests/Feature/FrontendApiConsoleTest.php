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
use Livewire\Volt\Volt;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->app['config']->set('cbox-id.frontend_api.enabled', true);
    (new FrontendApiServiceProvider($this->app))->boot();
});

it('creates a key with its origins from the console', function (): void {
    actingAsRole(MembershipRole::Owner);

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
    actingAsRole(MembershipRole::Owner);

    Volt::test('console.frontend-keys')
        ->set('name', 'Site')
        ->set('origins', "https://good.test\nnot-an-origin")
        ->call('create')
        ->assertHasErrors('origins');

    expect(PublishableKey::query()->count())->toBe(0);
});

it('refuses plain http away from loopback, and says why', function (): void {
    actingAsRole(MembershipRole::Owner);

    Volt::test('console.frontend-keys')
        ->set('name', 'Site')
        ->set('origins', 'http://acme.test')
        ->call('create')
        ->assertHasErrors('origins');
});

it('revokes a key so pages holding it stop working', function (): void {
    actingAsRole(MembershipRole::Owner);

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
    actingAsRole(MembershipRole::Owner);

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
