<?php

declare(strict_types=1);

use Cbox\Id\Connectors\Connections\ConnectionsOverview;
use Cbox\Id\Connectors\Enums\ConnectorCategory;
use Cbox\Id\Connectors\ValueObjects\ConnectionSummary;
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Federation\Testing\InteractsWithFederation;
use Cbox\Id\Organization\Testing\InteractsWithOrganizations;
use Cbox\Id\Provisioning\Testing\InteractsWithProvisioning;
use Cbox\Id\Webhooks\Testing\InteractsWithWebhooks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class, InteractsWithOrganizations::class, InteractsWithFederation::class, InteractsWithProvisioning::class, InteractsWithWebhooks::class);

// The connector modules SSRF-check every target URL on registration. These tests
// register against non-routable example hosts and never make real egress, so the
// guard is off for the happy paths — the same stance laravel-id's own provisioning
// tests take. The module under test loosens nothing; it only reads existing rows.
beforeEach(function (): void {
    config([
        'ssrf.enforce' => false,
        'cbox-id.provisioning.verify_url' => false,
        'cbox-id.webhooks.verify_url' => false,
    ]);
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

/**
 * The whole environment is EVERY connection — an organization's SSO connection included. It
 * used to drop federation entirely here, because the contract it read could only answer one
 * organization at a time, so the environment-wide Connections list never showed a single SSO
 * connection.
 */
it('lists every organization\'s SSO connection when scoping to the whole environment', function (): void {
    $org = $this->makeOrganization('Acme');
    $this->makeConnection($org->id, name: 'Okta');
    $this->registerProvisioningConnection(name: 'Env-wide');

    $categories = collect(app(ConnectionsOverview::class)->forOrganization(null))->map->category;

    expect($categories)->toContain(ConnectorCategory::Provisioning)
        ->and($categories)->toContain(ConnectorCategory::Federation);
});

/**
 * A webhook subscribed to an event type nobody listed in config used to be missing: the
 * registry contract could only find endpoints by event type.
 */
it('lists a webhook whatever it subscribes to, paused ones included', function (): void {
    $org = $this->makeOrganization('Acme');
    $endpoint = $this->registerWebhook($org->id, 'https://hooks.acme.test/billing', ['organization.updated'])->endpoint;
    $endpoint->forceFill(['status' => 'paused'])->save();

    $webhooks = collect(app(ConnectionsOverview::class)->forOrganization($org->id))
        ->filter(fn ($summary): bool => $summary->category === ConnectorCategory::Webhook);

    expect($webhooks)->toHaveCount(1)
        ->and($webhooks->first()->status)->toBe('paused')
        ->and($webhooks->first()->name)->toBe('hooks.acme.test/billing');
});

/**
 * HEALTH FROM THE ROW, so every install has it: consecutive failures make a webhook failing,
 * with the last error to say why.
 */
it('reads a failing webhook from its own failure counter', function (): void {
    $org = $this->makeOrganization('Acme');
    $endpoint = $this->registerWebhook($org->id, 'https://hooks.acme.test/events', ['auth.login'])->endpoint;
    $endpoint->forceFill(['consecutive_failures' => 4, 'last_error' => 'HTTP 503 from the endpoint'])->save();

    $summary = collect(app(ConnectionsOverview::class)->forOrganization($org->id))
        ->first(fn ($summary): bool => $summary->category === ConnectorCategory::Webhook);

    expect($summary->needsAttention())->toBeTrue()
        ->and($summary->verdict())->toBe('failing')
        ->and($summary->problem)->toBe('HTTP 503 from the endpoint');
});

it('counts only active connections', function (): void {
    $org = $this->makeOrganization('Acme');
    $this->registerProvisioningConnection(name: 'Downstream', organizationIds: [$org->id]);
    $this->registerWebhook($org->id, 'https://hooks.acme.test/events', ['auth.login']);

    expect(app(ConnectionsOverview::class)->activeCount($org->id))->toBe(2);
});

/**
 * PAGED IN THE DATABASE, failing first. The list used to load every connection in the
 * environment and sort it in PHP; one page now reads one page of rows — the rest are only
 * counted — with the order the database gives: failing first, then by type, then by name.
 */
it('reads one page of connections from the database, failing first', function (): void {
    $org = $this->makeOrganization('Acme');

    foreach (range(1, 12) as $n) {
        app(Directories::class)->register($org->id, sprintf('Directory %02d', $n));
    }

    $this->registerWebhook($org->id, 'https://hooks.acme.test/fine', ['auth.login']);
    $broken = $this->registerWebhook($org->id, 'https://hooks.acme.test/broken', ['auth.login'])->endpoint;
    $broken->forceFill(['consecutive_failures' => 2, 'last_error' => 'HTTP 500'])->save();

    $loaded = 0;
    Event::listen('eloquent.retrieved: '.Directory::class, function () use (&$loaded): void {
        $loaded++;
    });

    $overview = app(ConnectionsOverview::class);
    $every = ConnectorCategory::cases();
    $first = $overview->page($org->id, $every, '', 5, 1);

    expect($first['total'])->toBe(14)
        ->and($first['rows'])->toHaveCount(5)
        ->and($first['rows'][0]->id)->toBe($broken->id)
        ->and($first['rows'][1]->name)->toBe('Directory 01')
        ->and($loaded)->toBe(4);

    $last = $overview->page($org->id, $every, '', 5, 3);

    expect(collect($last['rows'])->map->name->all())->toBe(['Directory 10', 'Directory 11', 'Directory 12', 'hooks.acme.test/fine'])
        ->and($overview->page($org->id, $every, 'failing', 5, 1)['total'])->toBe(1)
        ->and($overview->page($org->id, [ConnectorCategory::Webhook], '', 5, 1)['total'])->toBe(2)
        ->and($overview->counts($org->id)['webhook'])->toBe(['total' => 2, 'active' => 2, 'failing' => 1])
        ->and($overview->counts($org->id)['directory']['total'])->toBe(12);
});
