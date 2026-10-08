<?php

declare(strict_types=1);

use App\Platform\Actions\AccountScopes;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRegistry;
use App\Platform\Actions\ActionRoutes;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\PlatformScopes;
use App\Platform\Actions\WorkspaceScopes;
use Cbox\Id\Platform\Contracts\ManagementScopes;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| What every action must be, so every door can rely on it — on whichever plane it lives.
|--------------------------------------------------------------------------
*/

// Discovered from the directories directly: a dataset is resolved before the application
// boots, so it cannot ask the container for the registry. A module's actions live in its
// own `src/Actions`, under the namespace composer maps that module to.
dataset('actions', function (): array {
    $root = dirname(__DIR__, 3);
    $registry = new ActionRegistry($root.'/app/Actions');
    $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    foreach ($composer['autoload']['psr-4'] ?? [] as $namespace => $path) {
        if (is_string($path) && str_starts_with($path, 'modules/') && is_dir($root.'/'.$path.'Actions')) {
            $registry->discoverIn($root.'/'.$path.'Actions', $namespace.'Actions');
        }
    }

    return array_map(static fn (ActionDefinition $action): array => [$action], $registry->all());
});

it('discovers the actions, on every plane', function (ActionPlane $plane): void {
    expect(app(ActionRegistry::class)->forPlane($plane))->not->toBeEmpty();
})->with(ActionPlane::cases());

it('is routed on REST exactly as it declares, behind its plane\'s key and its scope', function (ActionDefinition $action): void {
    $route = Route::getRoutes()->getByName('api.actions.'.$action->name);

    expect($route)->not->toBeNull()
        ->and($route->methods())->toContain($action->method)
        ->and(rtrim('/'.$route->uri(), '/'))->toBe(rtrim('/api/v1'.$action->documentedPath(), '/'))
        ->and($route->gatherMiddleware())->toContain(ActionRoutes::middleware($action));
})->with('actions');

it('requires a scope its plane\'s key can actually carry', function (ActionDefinition $action): void {
    if ($action->plane === ActionPlane::Workspace) {
        expect(WorkspaceScopes::knows($action->scope))->toBeTrue("{$action->name} requires {$action->scope}, which is no workspace key scope");

        return;
    }

    // The planes only a person reaches: a scope the person delegates, and never one a
    // management key could carry — the key form must not offer it.
    if ($action->plane->personal()) {
        $known = $action->plane === ActionPlane::Platform ? PlatformScopes::knows($action->scope) : AccountScopes::knows($action->scope);

        expect($known)->toBeTrue("{$action->name} requires {$action->scope}, which its plane does not define")
            ->and(app(ManagementScopes::class)->knows($action->scope))->toBeFalse("{$action->scope} is a management key scope: a key could reach {$action->name}")
            ->and(WorkspaceScopes::knows($action->scope))->toBeFalse("{$action->scope} is a workspace key scope: a key could reach {$action->name}");

        return;
    }

    $scopes = app(ManagementScopes::class);

    expect($scopes->knows($action->scope))->toBeTrue("{$action->name} requires {$action->scope}, which no key can carry — add it to AppManagementScopes::APP_SCOPES")
        ->and(in_array($action->scope, $scopes->offerable(), true))->toBeTrue("{$action->scope} is not offered on the key form");
})->with('actions');

it('names a console gate its plane can answer', function (ActionDefinition $action): void {
    // A workspace action is run by a workspace key too, and a key's role can only answer a
    // WORKSPACE capability — an environment-console gate would refuse every key, silently.
    expect($action->consoleGate->isWorkspace())->toBe($action->plane === ActionPlane::Workspace)
        // The operator gate is the platform plane's and only its own; likewise the person's
        // for the account plane — so no operator action is reachable as a mere member, and
        // no account action administers anybody else.
        ->and($action->consoleGate === ConsoleGate::Operator)->toBe($action->plane === ActionPlane::Platform)
        ->and($action->consoleGate === ConsoleGate::Person)->toBe($action->plane === ActionPlane::Account);
})->with('actions');

it('matches its danger to its method and scope', function (ActionDefinition $action): void {
    $readMethod = $action->method === 'GET';

    expect($action->danger->writes())->toBe(! $readMethod)
        ->and(str_ends_with($action->scope, ':read'))->toBe($readMethod);
})->with('actions');

it('declares an input schema an MCP client can read, with the path parameters it routes on', function (ActionDefinition $action): void {
    $schema = $action->input()->jsonSchema();

    preg_match_all('/\{(\w+)\}/', $action->path, $placeholders);

    expect($schema['type'])->toBe('object')
        ->and(json_encode($schema, JSON_THROW_ON_ERROR))->toBeString()
        ->and($action->input()->pathFields())->toEqualCanonicalizing($placeholders[1])
        ->and($action->toolName())->toMatch('/^[a-z][a-z0-9_]*$/');
})->with('actions');

it('claims only console routes that exist', function (ActionDefinition $action): void {
    $missing = array_values(array_filter($action->consoleRoutes, static fn (string $name): bool => ! Route::has($name)));

    expect($missing)->toBe([], "{$action->name} claims routes that do not exist");
})->with('actions');

it('marks every action that returns a credential as one, so a replay never carries it', function (ActionDefinition $action): void {
    // A Critical write is one that may mint a credential; one whose response schema has a
    // `token` must redact it, or the idempotency store becomes a table of live keys.
    $mints = in_array($action->schema, ['EnvironmentKey', 'WorkspaceKey', 'CreatedEnvironment'], true) && $action->danger->writes();

    expect($mints ? $action->redact !== [] : true)->toBeTrue("{$action->name} returns a key but redacts nothing");
})->with('actions');
