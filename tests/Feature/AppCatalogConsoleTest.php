<?php

declare(strict_types=1);

use App\Platform\AppCatalog\CatalogEntry;
use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\SessionManager;
use Cbox\Id\Identity\Contracts\Subjects;

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
