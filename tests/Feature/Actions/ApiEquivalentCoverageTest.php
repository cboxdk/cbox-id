<?php

declare(strict_types=1);

use App\Platform\Actions\ActionRegistry;
use App\Platform\Connect\ActionSnippets;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\Enums\MembershipRole;

/*
|--------------------------------------------------------------------------
| "</> API" on every console action.
|--------------------------------------------------------------------------
|
| Every action that names a console route is the API twin of a form, and the page holding
| that form is handed the action's facts in the shared `apiEquivalents` prop — which the
| page header turns into its "</> API" button, so no page has to remember to. What is held
| here is the server's half: every console route an action claims resolves to a page that
| hosts it, and a real page request carries the prop with the page's own ids filled in.
| That the header DRAWS it from the prop is the component tests'.
*/

it('hands every action that claims a console route to the page holding its form', function (): void {
    $snippets = app(ActionSnippets::class);
    $orphans = [];

    foreach (app(ActionRegistry::class)->all() as $action) {
        foreach ($action->consoleRoutes as $consoleRoute) {
            $host = $snippets->hostOf($consoleRoute);

            if ($host === null || ! array_key_exists($action->name, $snippets->forPage($host))) {
                $orphans[] = "{$consoleRoute} ({$action->name}) → ".($host ?? 'no page');
            }
        }
    }

    expect($orphans)->toBe([], 'Each of these console routes has no page that offers its API equivalent. '
        .'Nest the route under the page holding its form, or name the page in ActionSnippets::HOSTS.');
})->group('actions');

it('sends the API twin of the create form with the page, and never a secret field as a value', function (): void {
    multiTenantDeployment();
    actAsEnvironmentAdminOfATenant();
    confirmEnvironmentStepUp();

    $actions = (array) $this->get(route('environment.clients.create'))->assertOk()->inertiaProps('apiEquivalents');
    $create = $actions['apps.create'] ?? null;

    expect($create)->not->toBeNull()
        ->and($create['method'])->toBe('POST')
        ->and($create['path'])->toBe('/apps')
        ->and($create['url'])->toEndWith('/api/v1/apps')
        ->and($create['scope'])->toBe('apps:write')
        ->and($create['danger'])->toBe('critical')
        ->and($create['tool'])->toBe('apps_create')
        ->and($create['cli'])->toBe('cbox id apps:create')
        ->and($create['sdk'])->toBe('env.apps.create')
        ->and($create['redact'])->toContain('client_secret')
        ->and(collect($create['fields'])->pluck('name'))->toContain('name', 'redirect_uris')
        ->and(collect($create['fields'])->pluck('value')->filter()->all())->toBe([]);
})->group('actions');

it('fills the page\'s own id into the path of the actions it hosts', function (): void {
    multiTenantDeployment();
    actAsEnvironmentAdminOfATenant();
    confirmEnvironmentStepUp();

    registerApp(['name' => 'Filled', 'redirectUris' => 'https://filled.example/cb', 'environmentWide' => true], 'environment.clients')->assertRedirect();
    $app = Client::query()->where('name', 'Filled')->sole();

    $actions = (array) $this->get(route('environment.clients.show', $app->id))->assertOk()->inertiaProps('apiEquivalents');

    expect($actions)->toHaveKeys(['apps.update', 'apps.delete', 'apps.secrets.rotate'])
        ->and($actions['apps.update']['path'])->toBe('/apps/'.$app->id)
        ->and($actions['apps.update']['url'])->toEndWith('/api/v1/apps/{id}')
        ->and(collect($actions['apps.update']['fields'])->firstWhere('name', 'id')['value'] ?? null)->toBe($app->id);
})->group('actions');

it('marks a secret input as one, so a snippet writes it as a variable', function (): void {
    $users = app(ActionSnippets::class)->describe(app(ActionRegistry::class)->named('users.create'))->toArray();

    expect(collect($users['fields'])->firstWhere('name', 'password')['secret'] ?? null)->toBeTrue()
        ->and(collect($users['fields'])->firstWhere('name', 'email')['secret'] ?? null)->toBeFalse();
});

it('offers the organization console its own twins, and none off the console', function (): void {
    actingAsRole(MembershipRole::Owner);

    $actions = (array) $this->get(route('roles'))->assertOk()->inertiaProps('apiEquivalents');

    expect($actions)->toHaveKey('roles.create');

    auth()->logout();
    $this->flushSession();

    expect((array) $this->get(route('login'))->assertOk()->inertiaProps('apiEquivalents'))->toBe([]);
})->group('actions');
