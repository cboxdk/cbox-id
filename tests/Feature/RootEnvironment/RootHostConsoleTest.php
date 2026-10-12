<?php

declare(strict_types=1);

use App\Platform\Actions\ActionRegistry;
use App\Platform\Console\RootHostConsole;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| NOTHING ON THE ROOT HOST CONFIGURES AN ORGANIZATION AS THOUGH IT WERE AN ENVIRONMENT
|--------------------------------------------------------------------------
|
| An operator on `cboxid.com/social-sign-in` once saw the platform's Social login and
| configured their own workspace's organization with it. Every console write that changes
| how people sign in — social providers, sign-in methods and rules, SSO, domains, branding,
| directories — is enumerated here from the action registry, and each organization-console
| route it is written through is refused on the root host to an operator: the platform's own
| environment console is where those are set.
*/

beforeEach(function (): void {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    multiTenantDeployment('cboxid.com');
    config(['cbox-id.environments.base_domains' => ['cboxid.com']]);
    provisionAccount('owner@acme.example');
    actAsOperator('ops@platform.test');
});

/** The sign-in tags of the action registry: every action that changes how people sign in. */
const SIGN_IN_TAGS = ['Sign-in', 'Enterprise SSO', 'Domains', 'Branding', 'Directory Sync'];

it('refuses every organization-plane sign-in write on the root host to an operator', function (): void {
    $writes = [];

    foreach (app(ActionRegistry::class)->all() as $action) {
        if (! in_array($action->tag, SIGN_IN_TAGS, true) || ! $action->danger->writes()) {
            continue;
        }

        foreach ($action->consoleRoutes as $name) {
            if (str_starts_with($name, 'environment.') || str_starts_with($name, 'platform.')) {
                continue;
            }

            /** @var Route|null $route */
            $route = app('router')->getRoutes()->getByName($name);

            if ($route !== null) {
                $writes[$name] = $route;
            }
        }
    }

    expect(count($writes))->toBeGreaterThan(15);

    $served = [];

    foreach ($writes as $name => $route) {
        $uri = '/'.preg_replace('/\{[^}]+\}/', '01JROOTHOSTPROBE00000000000', $route->uri());
        $method = collect($route->methods())->first(static fn (string $verb): bool => $verb !== 'HEAD');
        $status = $this->call((string) $method, 'https://cboxid.com'.$uri)->status();

        if ($status !== 404) {
            $served[] = "{$method} {$uri} ({$name}) → {$status}";
        }
    }

    expect($served)->toBe([], implode("\n", $served));
})->group('security');

it('keeps the workspace team\'s own sign-in for a workspace, and sends an operator to the platform\'s', function (): void {
    expect(RootHostConsole::keepsRoute('connections'))->toBeTrue()
        ->and(RootHostConsole::keepsRoute('auth-policy.update'))->toBeTrue()
        ->and(RootHostConsole::keepsRoute('connections', operator: true))->toBeFalse()
        ->and(RootHostConsole::keepsRoute('auth-policy.update', operator: true))->toBeFalse()
        ->and(RootHostConsole::keepsRoute('social-providers'))->toBeFalse()
        ->and(RootHostConsole::keepsRoute('sign-in-methods'))->toBeFalse()
        ->and(RootHostConsole::keepsRoute('branding'))->toBeFalse()
        ->and(RootHostConsole::keepsRoute('domains'))->toBeFalse();
});

it('sends an operator from Team sign-in to the platform environment\'s single sign-on', function (): void {
    $this->get('https://cboxid.com/single-sign-on')->assertRedirect('https://cboxid.com/admin/single-sign-on');
    $this->get('https://cboxid.com/sign-in-rules')->assertRedirect('https://cboxid.com/admin/sign-in-rules');
});

it('says above every root-host page what it acts on', function (): void {
    $workspace = (array) $this->get('https://cboxid.com/account')->assertOk()->inertiaProps('shell.actsOn');
    $environment = (array) $this->get('https://cboxid.com/admin/social-sign-in')->assertOk()->inertiaProps('shell.actsOn');
    $platform = (array) $this->get('https://cboxid.com/admin/platform/workspaces')->assertOk()->inertiaProps('shell.actsOn');

    expect($environment)->toMatchArray(['kind' => 'environment', 'critical' => true])
        ->and((string) $environment['detail'])->toContain('every workspace owner and every operator')
        // Platform admin, in the same console: the whole deployment, in the warning colours
        // the amber strip used to carry.
        ->and($platform)->toMatchArray(['kind' => 'platform', 'label' => 'The whole deployment', 'critical' => true])
        ->and($workspace['kind'])->toBeIn(['workspace', 'organization']);
});
