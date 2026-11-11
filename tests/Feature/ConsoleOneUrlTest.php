<?php

declare(strict_types=1);

use App\Platform\Console\EnvironmentConsolePath;
use Cbox\Id\Organization\Enums\EnvironmentStatus;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

// An unclaimed deployment points every page at its first-run screen, redirects included.
beforeEach(fn () => installedDeployment());

/**
 * ONE URL PER PAGE, and every old spelling still arrives.
 *
 * The two consoles served the same component at different paths — `/sod-policies` and
 * `/admin/conflict-rules`, `/hooks` and `/admin/event-hooks` — so a link copied from one
 * was nonsense in the other. The pages have one slug each now, and the old GETs answer
 * 301 because they live in bookmarks, runbooks and support replies.
 */
dataset('moved pages', [
    // The organization and workspace console.
    ['/clients', '/apps'],
    ['/clients/new', '/apps/new'],
    ['/clients/01ABC', '/apps/01ABC'],
    ['/connections', '/single-sign-on'],
    ['/connections/01ABC', '/single-sign-on/01ABC'],
    ['/social-providers', '/social-sign-in'],
    ['/directories', '/sync-in'],
    ['/directories/01ABC', '/sync-in/01ABC'],
    ['/provisioning', '/sync-out'],
    ['/governance', '/access-reviews'],
    ['/governance/01ABC', '/access-reviews/01ABC'],
    ['/sod-policies', '/role-conflicts'],
    ['/sod-policies/new', '/role-conflicts/new'],
    ['/hooks', '/inline-hooks'],
    ['/vault', '/token-vault'],
    ['/members', '/team'],
    ['/api-keys', '/keys/workspace'],
    ['/environment-keys', '/keys'],
    ['/organization-settings', '/workspace-settings'],
    // Module pages, whose old spelling lives beside the module's own routes.
    ['/analytics', '/sign-in-activity'],
    ['/sign-in/devices', '/trusted-devices'],
    ['/settings/branding', '/branding'],

    // The environment console.
    ['/admin/applications', '/admin/apps'],
    ['/admin/applications/01ABC', '/admin/apps/01ABC'],
    ['/admin/login-methods', '/admin/saml-apps'],
    ['/admin/directories/new', '/admin/sync-in/new'],
    ['/admin/outbound-sync', '/admin/sync-out'],
    ['/admin/conflict-rules', '/admin/role-conflicts'],
    ['/admin/event-hooks/01ABC', '/admin/inline-hooks/01ABC'],
    ['/admin/stored-tokens', '/admin/token-vault'],
    ['/admin/frontend-keys', '/admin/keys/frontend'],
    ['/admin/analytics', '/admin/usage'],
]);

it('answers every old console URL with a permanent redirect to its one page', function (string $from, string $to): void {
    // The environment console's old addresses answer only where the environment console
    // does — an environment's own host on a multi-tenant deployment — and 404 everywhere
    // else (MovedPageRedirectsTest), so that is where they are asked.
    if (str_starts_with($from, '/admin/')) {
        multiTenantDeployment();
        config()->set('cbox-id.environments.base_domains', ['cboxid.com']);
        platformRootEnvironment();
        Environment::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'type' => EnvironmentType::Production,
            'status' => EnvironmentStatus::Active,
            'is_default' => false,
            'settings' => [],
        ]);

        $from = 'https://acme.cboxid.com'.$from;
    }

    $response = $this->get($from);

    $response->assertStatus(301);

    expect(parse_url((string) $response->headers->get('Location'), PHP_URL_PATH))->toBe($to);
})->with('moved pages');

it('keeps the query string a moved page was asked with', function (): void {
    // The chosen environment and the page number are state somebody picked. Laravel's own
    // redirect route drops them, which is why these are not `Route::permanentRedirect()`.
    $response = $this->get('/environment-keys?environment=01ENV&page=2');

    $response->assertStatus(301);

    expect((string) $response->headers->get('Location'))->toEndWith('/keys?environment=01ENV&page=2');
});

it('redirects a moved page to a path on this host and never elsewhere', function (): void {
    // The destination is a route default, never the request: a crafted Host or path
    // segment cannot aim the Location somewhere else.
    // A parameter is encoded into the path, so one shaped like authority (`@host`,
    // `host:port`) stays a path segment rather than becoming the URL's host.
    $response = $this->get('/clients/@evil.example:443');

    $response->assertStatus(301);

    $location = (string) $response->headers->get('Location');

    expect(parse_url($location, PHP_URL_HOST))->toBe(parse_url((string) config('app.url'), PHP_URL_HOST))
        ->and(parse_url($location, PHP_URL_PATH))->toBe('/apps/%40evil.example%3A443');
});

it('serves each shared page at the same slug on both consoles', function (string $organization, string $environment): void {
    // The environment console's path is the organization console's under the environment's
    // own path — here the platform root's, `/admin/environment`.
    expect(route($organization, [], false))->toBe(substr(route($environment, ['environmentPath' => 'admin/environment'], false), strlen('/admin/environment')));
})->with([
    ['clients', 'environment.clients'],
    ['connections', 'environment.connections'],
    ['social-providers', 'environment.social-providers'],
    ['directories', 'environment.directories'],
    ['provisioning', 'environment.provisioning'],
    ['governance', 'environment.governance'],
    ['sod-policies', 'environment.sod-policies'],
    ['hooks', 'environment.hooks'],
    ['vault', 'environment.vault'],
    ['webhooks', 'environment.webhooks'],
    ['audit', 'environment.audit'],
    ['audit-streams', 'environment.audit-streams'],
    ['roles', 'environment.roles'],
    ['permissions', 'environment.permissions'],
    ['auth-policy', 'environment.auth-policy'],
    ['branding', 'environment.branding'],
    ['usage', 'environment.usage'],
]);

it('gives the pages that moved into /orgs/{org} or made way for /settings their own address', function (): void {
    // `/settings` is the signed-in person's own settings on every Cbox app, so the console
    // page that sat there is `/organization`, and an environment keeps `settings` under its
    // own console path.
    $console = [EnvironmentConsolePath::PARAMETER => 'orgs/acme/projects/web/environments/production'];

    expect(route('settings', [], false))->toBe('/organization')
        ->and(route('environment.settings', $console, false))->toBe('/orgs/acme/projects/web/environments/production/settings')
        ->and(route('environment.settings', [EnvironmentConsolePath::PARAMETER => EnvironmentConsolePath::ROOT], false))->toBe('/admin/environment/settings')
        ->and(route('account', [], false))->toBe('/settings')
        // The organization's keys live in its settings; an environment's under its console.
        ->and(Route::getRoutes()->getByName('orgs.settings.api-keys')?->uri())->toBe('orgs/{org}/settings/api-keys')
        ->and(route('environment.keys', $console, false))->toBe('/orgs/acme/projects/web/environments/production/keys');
});

it('no longer routes any old spelling as a page of its own', function (): void {
    // A redirect row and a live page on the same path would be two statements of where
    // the page is; only the redirect may remain.
    foreach (['/clients', '/sod-policies', '/admin/conflict-rules', '/admin/event-hooks', '/admin/analytics'] as $path) {
        $route = Route::getRoutes()->match(request()->create($path));

        expect($route->getName())->toBeNull()
            ->and($route->defaults['to'] ?? null)->toBeString();
    }
});
