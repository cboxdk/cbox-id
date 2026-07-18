<?php

declare(strict_types=1);

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;

/**
 * The web plane must fail CLOSED like the API plane: an unmapped/spoofed Host is
 * never mapped to a customer tenant. Before the fix it fell through to the OLDEST
 * environment (any customer's live IdP).
 */
it('maps an unmapped host to the platform-root env, never the oldest customer', function (): void {
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);

    // A CUSTOMER environment created FIRST (the "oldest"), then the platform root
    // flagged is_default. The old bug picked oldest → the customer.
    $customer = Environment::query()->create(['name' => 'Acme', 'slug' => 'acme', 'status' => 'active', 'is_default' => false]);
    $root = Environment::query()->create(['name' => 'Production', 'slug' => 'production', 'status' => 'active']);
    $root->makeDefault();

    // An unmapped / spoofed Host resolves to the platform root, not the customer.
    $this->get('http://evil.attacker.com/login');
    expect(app(EnvironmentContext::class)->current()?->environmentKey())->toBe($root->id);

    // The customer plane is reachable ONLY via its own subdomain.
    $this->get('http://acme.cboxid.com/login');
    expect(app(EnvironmentContext::class)->current()?->environmentKey())->toBe($customer->id);
});
