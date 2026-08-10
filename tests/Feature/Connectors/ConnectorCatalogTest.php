<?php

declare(strict_types=1);

use Cbox\Id\Connectors\Catalog\ConnectorCatalog;
use Cbox\Id\Connectors\Enums\ConnectorCategory;
use Cbox\Id\Connectors\ValueObjects\ConnectorDescriptor;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Federation\Contracts\Connections as FederationConnections;
use Cbox\Id\Provisioning\Contracts\ProvisioningConnections;
use Cbox\Id\Webhooks\Contracts\WebhookRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('catalogs one descriptor per connector category, in display order', function (): void {
    $categories = collect(app(ConnectorCatalog::class)->all())->map->category;

    expect($categories->all())->toBe([
        ConnectorCategory::Provisioning,
        ConnectorCategory::Webhook,
        ConnectorCategory::Directory,
        ConnectorCategory::Federation,
    ]);
});

it('names the public module contract behind each descriptor', function (): void {
    $byCategory = collect(app(ConnectorCatalog::class)->all())
        ->keyBy(fn (ConnectorDescriptor $d): string => $d->category->value);

    expect($byCategory[ConnectorCategory::Provisioning->value]->contract)->toBe(ProvisioningConnections::class)
        ->and($byCategory[ConnectorCategory::Webhook->value]->contract)->toBe(WebhookRegistry::class)
        ->and($byCategory[ConnectorCategory::Directory->value]->contract)->toBe(Directories::class)
        ->and($byCategory[ConnectorCategory::Federation->value]->contract)->toBe(FederationConnections::class);
});

/**
 * EVERY ADVERTISED CONTRACT MUST RESOLVE — the catalog is a promise about runtime.
 *
 * The test above compares each descriptor's `contract` against a class constant: two
 * strings, both written by hand, and equal whether or not anything is bound to that
 * interface. A descriptor naming a contract the container cannot build would pass it and
 * advertise a connector that cannot work — which is precisely the shape the catalog exists
 * to prevent, since its whole job is to tell an operator what this deployment can connect.
 *
 * Resolved, not merely `bound()`: an interface can be bound to a concrete that itself
 * cannot be constructed, and the operator finds that out on the page rather than here.
 */
it('resolves every contract the catalog advertises', function (): void {
    $catalog = app(ConnectorCatalog::class);

    expect($catalog->all())->not->toBeEmpty();

    foreach ($catalog->all() as $descriptor) {
        expect(interface_exists($descriptor->contract) || class_exists($descriptor->contract))
            ->toBeTrue("connector [{$descriptor->key}] names a contract that does not exist: {$descriptor->contract}");

        expect(app($descriptor->contract))
            ->toBeObject("connector [{$descriptor->key}] advertises {$descriptor->contract}, which the container cannot resolve");
    }
});

/**
 * …and an enumerable one must actually enumerate, rather than merely claim to.
 */
it('lets every enumerable connector be enumerated for an organization', function (): void {
    foreach (app(ConnectorCatalog::class)->enumerable() as $descriptor) {
        expect($descriptor->enumerable)->toBeTrue()
            ->and(app($descriptor->contract))->toBeObject(
                "connector [{$descriptor->key}] is advertised as enumerable but its contract does not resolve",
            );
    }
});

it('marks directory sync as not enumerable via its public contract, the others as enumerable', function (): void {
    $catalog = app(ConnectorCatalog::class);

    $enumerable = collect($catalog->enumerable())->map->category;

    expect($enumerable->all())->toBe([
        ConnectorCategory::Provisioning,
        ConnectorCategory::Webhook,
        ConnectorCategory::Federation,
    ])->and($catalog->find('directory-sync')?->enumerable)->toBeFalse();
});

it('finds a descriptor by key and returns null for an unknown key', function (): void {
    $catalog = app(ConnectorCatalog::class);

    expect($catalog->find('webhook'))->toBeInstanceOf(ConnectorDescriptor::class)
        ->and($catalog->find('nope'))->toBeNull();
});
