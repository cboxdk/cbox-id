<?php

declare(strict_types=1);

use Cbox\Id\Connectors\Catalog\ConnectorCatalog;
use Cbox\Id\Connectors\Enums\ConnectorCategory;
use Cbox\Id\Connectors\ValueObjects\ConnectorDescriptor;
use Cbox\Id\Directory\Enums\DirectoryProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * THE CATALOG IS THE PLATFORM'S LIST OF INTEGRATIONS, not the plugin's list of what it could
 * count. It used to hold four types — the four with a public "list" contract — so social
 * login, HR systems, SAML apps, inline hooks, log streams and pipes were integrations with no
 * place in Connectors at all. Every category is catalogued, once, in display order.
 */
it('catalogs every kind of connection the platform makes, once each, in display order', function (): void {
    $categories = collect(app(ConnectorCatalog::class)->all())->map->category;

    expect($categories->all())->toBe([
        ConnectorCategory::Federation,
        ConnectorCategory::Social,
        ConnectorCategory::Directory,
        ConnectorCategory::Hris,
        ConnectorCategory::Provisioning,
        ConnectorCategory::SamlApp,
        ConnectorCategory::Webhook,
        ConnectorCategory::Hook,
        ConnectorCategory::LogStream,
        ConnectorCategory::Pipe,
    ])->and($categories->all())->toHaveCount(count(ConnectorCategory::cases()));
});

/**
 * …and every one of them LEADS somewhere. A descriptor names the console pages its type is
 * set up and managed on; a name that routes nowhere would be a card whose buttons 404, which
 * is the dead end this catalog was rebuilt to remove.
 */
it('names a real list, setup and row page for every type, on at least one plane', function (): void {
    $routed = static fn (string $name): bool => Route::has($name) || Route::has('environment.'.$name);

    foreach (app(ConnectorCatalog::class)->all() as $descriptor) {
        expect($routed($descriptor->indexRoute))->toBeTrue("[{$descriptor->key}] lists on [{$descriptor->indexRoute}], which is not a route")
            ->and($routed($descriptor->createRoute))->toBeTrue("[{$descriptor->key}] is set up on [{$descriptor->createRoute}], which is not a route")
            ->and($descriptor->showRoute === null ? $descriptor->rowParameter !== null : $routed($descriptor->showRoute))
            ->toBeTrue("[{$descriptor->key}] has no way to open one of its rows")
            ->and(app(ConnectorCatalog::class)->groups())->toContain($descriptor->group);
    }
});

it('offers every HR system under HR systems and never under Directory Sync', function (): void {
    $catalog = app(ConnectorCatalog::class);
    $hris = array_map(static fn (DirectoryProvider $provider): string => $provider->value, DirectoryProvider::hris());

    expect(array_keys($catalog->find('hr-systems')?->options ?? []))->toBe($hris)
        ->and(array_intersect(array_keys($catalog->find('directory-sync')?->options ?? []), $hris))->toBe([])
        ->and($catalog->find('directory-sync')?->options)->toHaveKey('scim');
});

it('gives every type with shortcuts the parameter its setup form reads', function (): void {
    foreach (app(ConnectorCatalog::class)->all() as $descriptor) {
        if ($descriptor->options !== []) {
            expect($descriptor->optionParameter)->not->toBeNull("[{$descriptor->key}] offers shortcuts with no parameter to carry them");
        }
    }
});

it('finds a descriptor by key and by category, and returns null for an unknown key', function (): void {
    $catalog = app(ConnectorCatalog::class);

    expect($catalog->find('webhook'))->toBeInstanceOf(ConnectorDescriptor::class)
        ->and($catalog->forCategory(ConnectorCategory::Hris)?->key)->toBe('hr-systems')
        ->and($catalog->find('nope'))->toBeNull();
});
