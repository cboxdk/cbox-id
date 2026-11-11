<?php

declare(strict_types=1);

use App\Platform\Console\EnvironmentConsolePath;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\Organization\Enums\EnvironmentType;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Platform\Models\Project;
use Cbox\Id\Platform\PlatformRoot;
use Cbox\Id\Platform\TenantProvisioner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

/**
 * THE SHARED SHELL (laravel-console-kit → cbox-ui `CboxShell`), per surface.
 *
 * An environment console: Organization › Project › Environment, with the environment's tone
 * and the sandbox strip on a sandbox. Platform admin: no levels and the "whole deployment"
 * notice. A customer environment's own console keeps its white-labelled chrome. Nothing the
 * shell reads calls another service.
 */
function addSandbox(Environment $production, string $name): Environment
{
    $project = Project::query()->findOrFail($production->project_id);

    return app(EnvironmentContext::class)->runAs(
        GenericEnvironment::of((string) app(PlatformRoot::class)->environment()?->environmentKey()),
        static fn (): Environment => app(TenantProvisioner::class)->addEnvironment($project, $name, null, EnvironmentType::Sandbox),
    );
}

/** A path on the app host, where the console and the shell's endpoints are served. */
function onAppHost(string $path): string
{
    $host = config('cbox-id.tenancy.account_host');

    return 'http://'.(is_string($host) && $host !== '' ? $host : 'localhost').$path;
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('puts an environment console in its organization, project and environment', function (): void {
    actAsEnvironmentAdminOfATenant();

    $shell = (array) $this->get(envConsole('/users'))->assertOk()->inertiaProps('shell');

    expect(array_column($shell['context']['levels'], 'key'))->toBe(['organization', 'project', 'environment'])
        ->and($shell['context']['levels'][2]['current']['tone'])->toBe('production')
        ->and($shell['context']['levels'][2]['current']['testData'])->toBeFalse()
        ->and($shell['context']['switchUrl'])->toBe('/shell/switch')
        ->and($shell['app']['key'])->toBe('id')
        ->and($shell['nav']['activePage'])->toBe('environment.users')
        ->and($shell)->not->toHaveKey('banner');
})->group('shell');

it('shows the sandbox strip in a sandbox, and its environments production first', function (): void {
    $environmentId = actAsEnvironmentAdminOfATenant();
    $production = Environment::query()->findOrFail($environmentId);
    $sandbox = addSandbox($production, 'Aardvark sandbox');
    useEnvironmentConsole((string) $sandbox->getKey());

    $shell = (array) $this->get(envConsole('/users'))->assertOk()->inertiaProps('shell');
    $environment = $shell['context']['levels'][2];

    expect($environment['current'])->toMatchArray(['name' => 'Aardvark sandbox', 'tone' => 'sandbox', 'testData' => true])
        ->and($shell['banner']['kind'])->toBe('sandbox');

    $list = $this->getJson(onAppHost($environment['listUrl']))->assertOk()->json('data');

    expect(array_column($list, 'tone'))->toBe(['production', 'sandbox']);
})->group('shell');

it('keeps the page when switching environment, and falls back to the list from a record', function (): void {
    $environmentId = actAsEnvironmentAdminOfATenant();
    $production = Environment::query()->findOrFail($environmentId);
    $sandbox = addSandbox($production, 'Sandbox');
    $from = envConsolePath('/users');
    $sandboxPath = preg_replace('#/environments/[^/]+$#', '/environments/'.$sandbox->slug, (string) URL::getDefaultParameters()[EnvironmentConsolePath::PARAMETER]);

    $this->postJson(onAppHost('/shell/switch'), ['select' => ['environment' => (string) $sandbox->getKey()], 'from' => $from])
        ->assertOk()
        ->assertJson(['url' => '/'.$sandboxPath.'/users', 'resolution' => 'same_page']);

    // From the header's cascade, which posts the project with the environment.
    $project = (string) $sandbox->project_id;

    $this->postJson(onAppHost('/shell/switch'), ['select' => ['project' => $project, 'environment' => (string) $sandbox->getKey()], 'from' => envConsolePath('/users/01JUSER')])
        ->assertOk()
        ->assertJson(['url' => '/'.$sandboxPath.'/users', 'resolution' => 'list_fallback']);
})->group('shell');

it('shows platform admin with no context levels and the whole-deployment notice', function (): void {
    multiTenantDeployment();
    actAsOperator();

    $shell = (array) $this->get('https://cboxid.com/admin/organizations')->assertOk()->inertiaProps('shell');

    expect($shell['context']['levels'])->toBe([])
        ->and($shell['banner']['kind'])->toBe('notice')
        ->and($shell['banner']['text'])->toContain('The whole deployment');
})->group('shell');

it('rings the bell only while an approval waits', function (): void {
    actAsEnvironmentAdminOfATenant();

    $shell = (array) $this->get(envConsole('/users'))->assertOk()->inertiaProps('shell');

    expect($shell['notifications'])->toBe(['enabled' => false]);
})->group('shell');
