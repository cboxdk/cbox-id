<?php

declare(strict_types=1);

use App\Platform\PlaneResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/**
 * AN AGENT'S KEY AND THE OTHER KINDS OF KEY ARE ONE CLICK APART. API keys' "Secret keys" tab
 * has always led to AI agents › Agents — the environment's secret keys are listed as the
 * agents holding them — but the Agents page drew no tabs, so from there the publishable keys
 * were a rail away and the workspace's keys were on another host with no way to reach them.
 * Both pages draw the same tabs now, Workspace keys as an absolute link to the workspace's
 * host, where that page lives.
 */
it('draws the key tabs on the Agents page and on API keys, workspace keys on the workspace host', function (): void {
    actAsEnvironmentAdminOfATenant();

    $host = app(PlaneResolver::class)->consoleHost();
    expect($host)->not->toBeNull();

    foreach (['/admin/agents' => 'management', '/admin/keys/frontend' => 'frontend'] as $path => $current) {
        $this->get($path)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tabs.0.key', 'management')
            ->where('tabs.0.href', fn (string $href): bool => str_ends_with($href, '/admin/agents'))
            ->where('tabs.1.key', 'frontend')
            ->where('tabs.2.key', 'workspace')
            ->where('tabs.2.href', 'https://'.$host.'/keys/workspace')
            ->where('tabs.2.current', false)
            ->where('tabs', fn ($tabs): bool => collect($tabs)->where('current', true)->pluck('key')->all() === [$current]));
    }
});

it('links Keys › Workspace keys on Connect to the workspace host', function (): void {
    actAsEnvironmentAdminOfATenant();

    $host = app(PlaneResolver::class)->consoleHost();

    $this->get('/admin/agents/connect')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('workspace.workspaceKeysHref', 'https://'.$host.'/keys/workspace'));
});
