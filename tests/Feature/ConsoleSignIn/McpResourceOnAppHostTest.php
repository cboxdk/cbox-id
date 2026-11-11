<?php

declare(strict_types=1);

use App\Mcp\McpProtectedResources;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\Contracts\IssuerResolver;
use Cbox\Id\Platform\PlatformRoot;

/**
 * LOGIN-HOST MODE: the platform's MCP server is on the app host, and the login host is its
 * authorization server. A client is never told to send its token to the sign-in host.
 */
it('names the app host as the MCP resource and the login host as its authorization server', function (): void {
    loginHostMode();

    $document = $this->getJson('https://'.APP_HOST.'/.well-known/oauth-protected-resource/mcp')->assertOk();

    expect($document->json('resource'))->toBe('https://'.APP_HOST.'/mcp')
        ->and($document->json('authorization_servers'))->toBe(['https://'.LOGIN_HOST]);

    // The 401 a client meets at the server points at that document, on the same host.
    $challenge = $this->postJson('https://'.APP_HOST.'/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertUnauthorized()
        ->headers->get('WWW-Authenticate');

    expect($challenge)->toContain('resource_metadata="https://'.APP_HOST.'/.well-known/oauth-protected-resource/mcp"');

    // The login host has no MCP server, and the app host no authorization server.
    $this->postJson('https://'.LOGIN_HOST.'/mcp', [])->assertNotFound();
    $this->getJson('https://'.APP_HOST.'/.well-known/oauth-authorization-server')->assertNotFound();
    $this->getJson('https://'.LOGIN_HOST.'/.well-known/oauth-authorization-server')->assertOk();
})->group('security');

it('keeps `{issuer}/mcp` as the resource in single-host mode and on a tenant host', function (): void {
    $tenant = loginHostMode();

    // A tenant's environment: its own host is both issuer and resource, as before.
    $inTenant = app(EnvironmentContext::class)->runAs($tenant, fn (): string => app(McpProtectedResources::class)->mcp()->identifier);

    expect($inTenant)->toBe(rtrim(app(IssuerResolver::class)->forEnvironment($tenant->environmentKey()), '/').'/mcp');

    // Single-host mode: the root's resource follows its issuer.
    config(['cbox-id.hosts.login' => null]);

    $root = app(PlatformRoot::class)->run(fn (): string => app(McpProtectedResources::class)->mcp()->identifier);

    expect($root)->toBe(rtrim((string) config('cbox-id.issuer'), '/').'/mcp');
});
