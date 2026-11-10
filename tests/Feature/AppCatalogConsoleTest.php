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
        ->and($response->json('revision'))->toBe(hash('sha256', json_encode($response->json('apps'), JSON_THROW_ON_ERROR)));
});

it('gives nobody signed in to the console an empty catalog', function (): void {
    $this->getJson(route('apps.catalog'))->assertOk()->assertJsonPath('apps', []);
});
