<?php

declare(strict_types=1);

use Cbox\Id\Connectors\Analytics\NullConnectorAnalytics;
use Cbox\Id\Connectors\Connections\ConnectionsOverview;
use Cbox\Id\Connectors\Contracts\ConnectorAnalytics;
use Cbox\Id\Connectors\Enums\ConnectorCategory;
use Cbox\Id\Connectors\Testing\FakeConnectorAnalytics;
use Cbox\Id\Connectors\ValueObjects\ConnectorHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('binds the inert null analytics backend by default', function (): void {
    expect(app(ConnectorAnalytics::class))->toBeInstanceOf(NullConnectorAnalytics::class)
        ->and(app(ConnectorAnalytics::class)->health(ConnectorCategory::Webhook, 'anything'))->toBeNull();
});

it('leaves connections without a health verdict when analytics is inert', function (): void {
    $org = $this->makeOrganization('Acme');
    $this->registerWebhook($org->id, 'https://hooks.acme.test/events', ['auth.login']);

    $summary = app(ConnectionsOverview::class)->forOrganization($org->id)[0];

    expect($summary->health)->toBeNull();
});

it('decorates a connection with health once a real analytics backend is bound', function (): void {
    $org = $this->makeOrganization('Acme');
    $endpoint = $this->registerWebhook($org->id, 'https://hooks.acme.test/events', ['auth.login'])->endpoint;

    $fake = (new FakeConnectorAnalytics)->set(
        ConnectorCategory::Webhook,
        $endpoint->id,
        new ConnectorHealth(delivered: 98, failed: 2, lastActivityAt: now()),
    );
    app()->instance(ConnectorAnalytics::class, $fake);

    $summary = app(ConnectionsOverview::class)->forOrganization($org->id)[0];

    expect($summary->health)->not->toBeNull()
        ->and($summary->health->verdict())->toBe('healthy')
        ->and($summary->health->successRate())->toBe(0.98);
});

it('rates a failing connection degraded and an idle one idle', function (): void {
    $degraded = new ConnectorHealth(delivered: 5, failed: 5, lastActivityAt: now());
    $idle = new ConnectorHealth(delivered: 0, failed: 0, lastActivityAt: null);

    expect($degraded->verdict())->toBe('degraded')
        ->and($degraded->successRate())->toBe(0.5)
        ->and($idle->verdict())->toBe('idle')
        ->and($idle->successRate())->toBeNull();
});
