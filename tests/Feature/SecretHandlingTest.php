<?php

declare(strict_types=1);

use Cbox\Id\Connectors\Connections\ConnectionsOverview;
use Cbox\Id\Provisioning\Models\ProvisioningConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('never stores a raw connector secret — registration seals it via the module contract', function (): void {
    $org = $this->makeOrganization('Acme');
    $raw = 'super-secret-bearer-token-value';

    $registered = $this->registerProvisioningConnection(
        organizationId: $org->id,
        name: 'Downstream',
        secret: $raw,
        organizationIds: [$org->id],
    );

    $stored = ProvisioningConnection::query()->findOrFail($registered->connection->id);

    // The sealed column holds ciphertext, not the plaintext we handed in.
    expect($stored->auth_secret_encrypted)->not->toBe($raw)
        ->and($stored->auth_secret_encrypted)->not->toContain($raw);

    // And no attribute of the persisted row leaks the raw secret.
    expect(json_encode($stored->getAttributes()))->not->toContain($raw);
});

it('never surfaces a secret in the unified connections view — only the base URL', function (): void {
    $org = $this->makeOrganization('Acme');
    $raw = 'another-downstream-token';

    $this->registerProvisioningConnection(
        organizationId: $org->id,
        name: 'Downstream',
        baseUrl: 'https://scim.acme.test/scim/v2',
        secret: $raw,
        organizationIds: [$org->id],
    );

    $summary = app(ConnectionsOverview::class)->forOrganization($org->id)[0];

    expect($summary->target)->toBe('https://scim.acme.test/scim/v2')
        ->and(json_encode($summary))->not->toContain($raw);
});
