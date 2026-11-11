<?php

declare(strict_types=1);

use App\Platform\Console\EnvironmentConsolePath;
use App\Platform\Console\OperatorPaths;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Platform\TenantProvisioner;
use Cbox\Id\Platform\ValueObjects\TenantBlueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(fn () => Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]));

/*
|--------------------------------------------------------------------------
| Platform admin lives at `/admin/…` — for operators, on the platform root's host only.
|--------------------------------------------------------------------------
|
| It was `/admin/platform/…` while `/admin` on the root host was the root environment's
| console; that console is at `/admin/environment/…` now. Every operator page answers under
| `/admin` for an operator, is 404 for anybody else, and is not served on a tenant's host.
| The old `/admin/platform/…` addresses redirect, counted, until they go quiet.
*/

/**
 * Every operator page (a GET route named `platform.*` under `/admin`), with its
 * parameters filled in.
 *
 * @return array<string, string> route name => path
 */
function operatorPages(string $organization): array
{
    $pages = [];

    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        /** @var Route $route */
        $name = (string) $route->getName();

        if (! str_starts_with($name, 'platform.') || ! in_array('GET', $route->methods(), true)
            || ! str_starts_with('/'.$route->uri(), '/admin') || str_starts_with($route->uri(), 'admin/platform')) {
            continue;
        }

        $pages[$name] = (string) preg_replace(
            ['/\{targetEnvironment\}/', '/\{organization\}/', '/\{[^}]+\}/'],
            ['root', $organization, 'probe'],
            '/'.$route->uri(),
        );
    }

    return $pages;
}

function operatorPathsWorkspace(): string
{
    return app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ))->organization->id;
}

it('serves every operator page under /admin, and none under /admin/platform', function (): void {
    multiTenantDeployment();
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);
    actAsOperator();
    $pages = operatorPages(operatorPathsWorkspace());

    expect(array_keys($pages))->toContain('platform.home', 'platform.orgs', 'platform.orgs.show', 'platform.environments',
        'platform.organizations', 'platform.organization', 'platform.operators', 'platform.usage', 'platform.queues', 'platform.search');

    foreach ($pages as $name => $path) {
        expect(OperatorPaths::owns(explode('?', $path)[0]))->toBeTrue("{$name} ({$path}) is outside the operator paths");

        // `search.jump` and an organization page of the root tree for an id that is not
        // in it answer 404 for a probe id; everything else answers.
        if (in_array($name, ['platform.search.jump', 'platform.organization'], true)) {
            continue;
        }

        $status = $this->get('https://cboxid.com'.$path)->status();
        expect(in_array($status, [200, 302], true))->toBeTrue("{$name} at {$path} answered {$status} for an operator");
    }
})->group('security');

it('is 404 on every operator page for a signed-in person who is not an operator', function (): void {
    multiTenantDeployment();
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);
    platformRootDeployment();
    $workspace = operatorPathsWorkspace();
    actAsOperator('someone-else@platform.test'); // the deployment is installed

    $subject = app(Subjects::class)->create('ordinary@acme.test', 'Ordinary', 'supersecret123');
    signInAsSubject($subject->id);

    foreach (operatorPages($workspace) as $name => $path) {
        $response = $this->get('https://cboxid.com'.$path);
        expect($response->status())->toBe(404, "{$name} at {$path} → ".$response->headers->get('Location'));
    }

    // The writes too: nothing acts for somebody who does not run the deployment.
    $this->post('https://cboxid.com/admin/organizations', ['name' => 'Nope'])->assertNotFound();
    $this->post('https://cboxid.com/admin/environments/switch', ['environment' => 'x'])->assertNotFound();
})->group('security');

it('serves no operator page on a tenant\'s host, to an operator or anybody', function (): void {
    multiTenantDeployment();
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);
    platformRootEnvironment();
    $provisioned = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));
    $host = 'https://'.$provisioned->environment->slug.'.cboxid.com';
    actAsOperator();

    foreach (operatorPages($provisioned->organization->id) as $name => $path) {
        $response = $this->get($host.$path);

        // 404, or — where the path was an environment-console page on that host before the
        // console moved (`/admin/organizations`, `/admin/usage`…) — the counted redirect to
        // that console's new address on the app host. Never the operator page.
        if ($response->status() === 302) {
            expect((string) $response->headers->get('Location'))
                ->toStartWith('https://cboxid.com/'.app(EnvironmentConsolePath::class)->for($provisioned->environment->id), "{$name} at {$path}");

            continue;
        }

        expect($response->status())->toBe(404, "{$name} at {$path} answered on a tenant's host");
    }

    $this->get($host.'/admin/queues/monitor')->assertNotFound();
    $this->post($host.'/admin/organizations', ['name' => 'Nope'])->assertNotFound();
    $this->get($host.'/admin/platform/organizations')->assertNotFound();
})->group('security');

it('keeps a tenant host\'s old /admin/organizations address landing on its console', function (): void {
    multiTenantDeployment();
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);
    platformRootEnvironment();
    $provisioned = app(TenantProvisioner::class)->provision(new TenantBlueprint(
        organizationName: 'Acme',
        ownerEmail: 'owner@acme.example',
        ownerName: 'Owner',
        ownerPassword: 'a-strong-unbreached-passphrase',
    ));
    $console = app(EnvironmentConsolePath::class)->for($provisioned->environment->id);

    $this->get('https://'.$provisioned->environment->slug.'.cboxid.com/admin/organizations?q=acme')
        ->assertStatus(302)
        ->assertHeader('Deprecation', 'true')
        ->assertRedirect('https://cboxid.com/'.$console.'/organizations?q=acme');
});

it('redirects every old /admin/platform address to its page under /admin, counted', function (): void {
    multiTenantDeployment();
    config()->set('cbox-id.environments.base_domains', ['cboxid.com']);
    actAsOperator();
    $workspace = operatorPathsWorkspace();

    $moves = [
        '/admin/platform' => '/admin',
        '/admin/platform/workspaces?q=acme' => '/admin/organizations?q=acme',
        '/admin/platform/workspaces/'.$workspace => '/admin/organizations/'.$workspace,
        '/admin/platform/environments' => '/admin/environments',
        '/admin/platform/environments/root/organizations' => '/admin/environments/root/organizations',
        '/admin/platform/organizations' => '/admin/environments/root/organizations',
        '/admin/platform/operators' => '/admin/operators',
        '/admin/platform/usage' => '/admin/usage',
        '/admin/platform/queues' => '/admin/queues',
        '/admin/platform/queues/monitor' => '/admin/queues/monitor',
        '/admin/platform/search?q=acme' => '/admin/search?q=acme',
    ];

    foreach ($moves as $from => $to) {
        $this->get('https://cboxid.com'.$from)
            ->assertStatus(302)
            ->assertHeader('Deprecation', 'true')
            ->assertRedirect('https://cboxid.com'.$to);
    }
})->group('security');
