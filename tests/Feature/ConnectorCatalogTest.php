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
