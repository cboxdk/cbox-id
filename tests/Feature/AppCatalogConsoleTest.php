<?php

declare(strict_types=1);

use App\Platform\AppCatalog\AppCatalog;
use App\Platform\AppCatalog\CatalogEntry;
use App\Platform\AppCatalog\CatalogEntryData;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Organization\Enums\MembershipRole;

beforeEach(fn () => installedDeployment());

it('administers catalog drafts and publishing through the console', function (): void {
    crudSetup();
    $this->get(route('environment.app-catalog'))->assertOk()->assertInertia(fn ($page) => $page->component('console/app-catalog/index')->where('entries', []));
    $this->get(route('environment.app-catalog.create'))->assertOk()->assertInertia(fn ($page) => $page->component('console/app-catalog/edit')->where('entry', null));
    $input = ['key' => 'billing', 'version' => 0, 'name' => 'Billing', 'launch_url' => 'https://billing.example.com', 'enabled' => false, 'position' => 0, 'audience' => 'everyone'];
    $this->put(route('environment.app-catalog.save', 'billing'), $input)->assertRedirect()->assertSessionHasNoErrors();
    $this->get(route('environment.app-catalog.edit', 'billing'))->assertOk()->assertInertia(fn ($page) => $page->where('entry.name', 'Billing')->where('entry.enabled', false));
    $this->put(route('environment.app-catalog.save', 'billing'), [...$input, 'version' => 1, 'enabled' => true])->assertRedirect()->assertSessionHasNoErrors();
    $this->from(route('environment.app-catalog.edit', 'billing'))->put(route('environment.app-catalog.save', 'billing'), $input)->assertSessionHasErrors('form');
    $this->getJson(route('environment.app-catalog.lookup', ['kind' => 'users']))->assertOk()->assertJsonStructure(['results']);
    $this->delete(route('environment.app-catalog.destroy', 'billing'), ['version' => 2])->assertRedirect()->assertSessionHasNoErrors();
    expect(CatalogEntry::query()->count())->toBe(0);
});

it('keeps validation errors beside the field that needs correcting', function (): void {
    crudSetup();
    $this->from(route('environment.app-catalog.create'))->put(route('environment.app-catalog.save', 'billing'), [
        'version' => 0, 'name' => 'Billing', 'launch_url' => 'http://billing.example.com',
        'enabled' => false, 'position' => 0, 'audience' => 'everyone',
    ])->assertRedirect()->assertSessionHasErrors('launch_url');
    expect(CatalogEntry::query()->count())->toBe(0);
});

it('opens old picker bookmarks in the personal console', function (): void {
    $subject = app(Subjects::class)->create('apps@example.test', 'App user', 'supersecret123');
    $session = app(SessionManager::class)->start($subject->id, null, ['pwd']);
    session([PlatformAuth::SESSION_KEY => $session->id]);
    $this->get(route('apps'))->assertRedirect(route('account', ['switch-app' => '1']));
});

it('serves the console switcher the signed-in person\'s catalog as same-origin JSON', function (): void {
    [$subject, $org] = actingAsRole(MembershipRole::Owner);
    $catalog = app(AppCatalog::class);
    $input = ['version' => 0, 'name' => 'Cbox · Billing', 'launch_url' => 'https://billing.example.com/login/launch', 'enabled' => true, 'position' => 0, 'audience' => 'everyone'];
    $catalog->save('billing', CatalogEntryData::fromArray($input));
    $other = app(Subjects::class)->create('other@catalog.example', 'Other')->id;
    $catalog->save('hidden', CatalogEntryData::fromArray([...$input, 'name' => 'Hidden', 'audience' => 'users', 'user_ids' => [$other]]));

    $this->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->where('appCatalogUrl', '/switch-app/catalog'));

    $response = $this->getJson(route('apps.catalog'))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonCount(1, 'apps')
        ->assertJsonPath('apps.0.key', 'billing')
        ->assertJsonPath('apps.0.launch_url', 'https://billing.example.com/login/launch?organization='.$org->id)
        ->assertJsonPath('refresh_after', 60);
    expect($response->json('apps.0'))->toHaveKeys(['key', 'name', 'description', 'launch_url', 'icon_url', 'icon_dark_url'])
        ->not->toHaveKeys(['position', 'version', 'user_ids', 'audience'])
        ->and($response->json('revision'))->toBe(hash('sha256', json_encode($response->json('apps'), JSON_THROW_ON_ERROR)))
        ->and($response->json('unavailable'))->toBe([]);
});

it('gives the console switcher a "More from Cbox" section with where to ask for each app', function (): void {
    [, $org] = actingAsRole(MembershipRole::Owner);
    $catalog = app(AppCatalog::class);
    $input = ['version' => 0, 'launch_url' => 'https://app.example.com/login/launch', 'enabled' => true, 'position' => 0, 'audience' => 'everyone'];
    $catalog->save('work', CatalogEntryData::fromArray([...$input, 'name' => 'Cbox Work', 'purpose' => 'Time and invoices', 'hue' => 'amber']));
    $catalog->save('signals', CatalogEntryData::fromArray([...$input, 'name' => 'Cbox Signals', 'purpose' => 'Risk signals', 'hue' => 'rose',
        'audience' => 'organizations', 'organization_ids' => [], 'admission_url' => 'https://signals.example.com/signup']));

    $response = $this->getJson(route('apps.catalog'))->assertOk()
        ->assertJsonPath('apps.0.key', 'work')
        ->assertJsonPath('apps.0.purpose', 'Time and invoices')
        ->assertJsonPath('apps.0.hue', 'amber')
        ->assertJsonPath('apps.0.available', true);

    expect($response->json('unavailable'))->toEqual([[
        'key' => 'signals', 'name' => 'Cbox Signals', 'description' => null, 'purpose' => 'Risk signals', 'hue' => 'rose',
        'admission_url' => 'https://signals.example.com/signup?organization='.$org->id, 'icon_url' => null, 'icon_dark_url' => null, 'available' => false,
    ]])->and($response->json('revision'))->toBe(AppCatalog::revision(['apps' => $response->json('apps'), 'unavailable' => $response->json('unavailable')]));
});

it('edits the switcher fields in the console and offers the hues to choose from', function (): void {
    crudSetup();
    $input = ['version' => 0, 'name' => 'Signals', 'launch_url' => 'https://signals.example.com', 'enabled' => true, 'position' => 0, 'audience' => 'everyone',
        'purpose' => 'Risk signals', 'hue' => 'rose', 'admission_url' => 'https://signals.example.com/signup', 'promote_when_unavailable' => false];
    $this->put(route('environment.app-catalog.save', 'signals'), $input)->assertRedirect()->assertSessionHasNoErrors();

    $this->get(route('environment.app-catalog.edit', 'signals'))->assertOk()->assertInertia(fn ($page) => $page
        ->where('entry.purpose', 'Risk signals')->where('entry.hue', 'rose')
        ->where('entry.admission_url', 'https://signals.example.com/signup')->where('entry.promote_when_unavailable', false)
        ->where('hues', fn ($hues): bool => collect($hues)->contains('rose') && collect($hues)->contains('violet')));

    $this->from(route('environment.app-catalog.edit', 'signals'))->put(route('environment.app-catalog.save', 'signals'), [...$input, 'version' => 1, 'admission_url' => 'https://10.0.0.8/join'])
        ->assertRedirect()->assertSessionHasErrors('admission_url');
});

it('gives nobody signed in to the console an empty catalog', function (): void {
    $this->getJson(route('apps.catalog'))->assertOk()->assertJsonPath('apps', []);
});
