<?php

declare(strict_types=1);

use Cbox\Id\Connectors\Connections\ConnectionsOverview;
use Cbox\Id\Connectors\Enums\ConnectorCategory;
use Cbox\Id\Connectors\ValueObjects\ConnectionSummary;
use Cbox\Id\Federation\Testing\InteractsWithFederation;
use Cbox\Id\Organization\Testing\InteractsWithOrganizations;
use Cbox\Id\Provisioning\Testing\InteractsWithProvisioning;
use Cbox\Id\Webhooks\Testing\InteractsWithWebhooks;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class, InteractsWithOrganizations::class, InteractsWithFederation::class, InteractsWithProvisioning::class, InteractsWithWebhooks::class);

// The connector modules SSRF-check every target URL on registration. These tests
// register against non-routable example hosts and never make real egress, so the
// guard is off for the happy paths — the same stance laravel-id's own provisioning
// tests take. The module under test loosens nothing; it only reads existing rows.
beforeEach(function (): void {
    config(['ssrf.enforce' => false, 'cbox-id.provisioning.verify_url' => false]);
});

/** @return array<string, ConnectionSummary> */
function summariesByCategory(ConnectionsOverview $overview, ?string $organizationId): array
{
    $out = [];
    foreach ($overview->forOrganization($organizationId) as $summary) {
        $out[$summary->category->value] = $summary;
    }

    return $out;
}

it('aggregates provisioning, webhook and federation connections for an organization from their contracts', function (): void {
    $org = $this->makeOrganization('Acme');

    $this->registerProvisioningConnection(
        organizationId: $org->id,
        name: 'Downstream',
        organizationIds: [$org->id],
    );
    $this->registerWebhook($org->id, 'https://hooks.acme.test/events', ['auth.login']);
    $this->makeConnection($org->id, name: 'Okta');

    $byCategory = summariesByCategory(app(ConnectionsOverview::class), $org->id);

    expect(array_keys($byCategory))->toContain('provisioning', 'webhook', 'federation')
        ->and($byCategory['provisioning']->name)->toBe('Downstream')
        ->and($byCategory['provisioning']->target)->toBe('https://scim.downstream.test/scim/v2')
        ->and($byCategory['webhook']->target)->toBe('https://hooks.acme.test/events')
        ->and($byCategory['federation']->name)->toBe('Okta')
        ->and($byCategory['federation']->isActive())->toBeTrue();
});

it('includes an environment-wide provisioning connection for every organization', function (): void {
    $org = $this->makeOrganization('Acme');
    $this->registerProvisioningConnection(name: 'Env-wide'); // no org ⇒ environment-wide

    $byCategory = summariesByCategory(app(ConnectionsOverview::class), $org->id);

    expect($byCategory['provisioning']->name)->toBe('Env-wide');
});

it('excludes a provisioning connection scoped to a different organization', function (): void {
    $acme = $this->makeOrganization('Acme');
    $other = $this->makeOrganization('Other');
    $this->registerProvisioningConnection(name: 'Other-only', organizationIds: [$other->id]);

    $summaries = app(ConnectionsOverview::class)->forOrganization($acme->id);

    expect($summaries)->toBe([]);
});

it('drops the organization-only federation lookup when scoping to the whole environment', function (): void {
    $org = $this->makeOrganization('Acme');
    $this->makeConnection($org->id, name: 'Okta');
    $this->registerProvisioningConnection(name: 'Env-wide');

    $categories = collect(app(ConnectionsOverview::class)->forOrganization(null))->map->category;

    expect($categories)->toContain(ConnectorCategory::Provisioning)
        ->and($categories)->not->toContain(ConnectorCategory::Federation);
});

it('counts only active connections', function (): void {
    $org = $this->makeOrganization('Acme');
    $this->registerProvisioningConnection(name: 'Downstream', organizationIds: [$org->id]);
    $this->registerWebhook($org->id, 'https://hooks.acme.test/events', ['auth.login']);

    expect(app(ConnectionsOverview::class)->activeCount($org->id))->toBe(2);
});
