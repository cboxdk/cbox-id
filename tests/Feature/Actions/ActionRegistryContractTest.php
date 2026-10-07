<?php

declare(strict_types=1);

use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionRegistry;
use Cbox\Id\Platform\Contracts\ManagementScopes;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| What every action must be, so every door can rely on it.
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

it('discovers the actions', function (): void {
    expect(app(ActionRegistry::class)->all())->not->toBeEmpty();
});

it('is routed on REST exactly as it declares, behind its scope', function (ActionDefinition $action): void {
    $route = Route::getRoutes()->getByName('api.actions.'.$action->name);

    expect($route)->not->toBeNull()
        ->and($route->methods())->toContain($action->method)
        ->and('/'.$route->uri())->toBe('/api/v1'.$action->path)
        ->and($route->gatherMiddleware())->toContain('env.api:'.$action->scope);
})->with('actions');

it('requires a scope a key can actually carry', function (ActionDefinition $action): void {
    $scopes = app(ManagementScopes::class);

    expect($scopes->knows($action->scope))->toBeTrue("{$action->name} requires {$action->scope}, which no key can carry — add it to AppManagementScopes::APP_SCOPES")
        ->and(in_array($action->scope, $scopes->offerable(), true))->toBeTrue("{$action->scope} is not offered on the key form");
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
