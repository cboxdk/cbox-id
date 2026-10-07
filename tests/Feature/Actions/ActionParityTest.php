<?php

declare(strict_types=1);

use App\Platform\Actions\ActionRegistry;
use Tests\Support\ParityAllowlist;
use Tests\Support\WriteRoutes;

/*
|--------------------------------------------------------------------------
| Every console write is an action, or is on a list that only shrinks.
|--------------------------------------------------------------------------
|
| An action is what the REST API, MCP and the CLI can call. A console write that is not one
| is something a person can do and an agent cannot — and nothing used to say how many of
| those there were. This test is that number, and it may only go down.
*/

/** @return array<string, string> console route name => action name */
function claimedConsoleRoutes(): array
{
    $claimed = [];

    foreach (app(ActionRegistry::class)->all() as $action) {
        foreach ($action->consoleRoutes as $route) {
            expect($claimed)->not->toHaveKey($route, "{$route} is claimed by two actions");
            $claimed[$route] = $action->name;
        }
    }

    return $claimed;
}

it('accounts for every console write: an action, or a listed exception', function (): void {
    $claimed = claimedConsoleRoutes();
    $listed = array_flip(ParityAllowlist::all());

    $unaccounted = array_values(array_filter(
        array_keys(WriteRoutes::console()),
        static fn (string $name): bool => ! isset($claimed[$name]) && ! isset($listed[$name]),
    ));

    expect($unaccounted)->toBe([], 'These console writes are neither an action nor on the parity allowlist. Make them an action (app/Actions), or — only for a sign-in ceremony — list them: '.implode(', ', $unaccounted));
});

it('names only console writes that exist, in actions and on the list alike', function (): void {
    $writes = WriteRoutes::console();

    $stale = array_values(array_filter(
        [...array_keys(claimedConsoleRoutes()), ...ParityAllowlist::all()],
        static fn (string $name): bool => ! isset($writes[$name]),
    ));

    expect($stale)->toBe([]);
});

it('never lists a write that is already an action', function (): void {
    expect(array_values(array_intersect(ParityAllowlist::all(), array_keys(claimedConsoleRoutes()))))->toBe([]);
});

it('only ever shrinks the pending list', function (): void {
    $pending = count(ParityAllowlist::pending());

    expect($pending)->toBeLessThanOrEqual(ParityAllowlist::BASELINE)
        ->and($pending)->toBe(ParityAllowlist::BASELINE, 'The pending list shrank — lower ParityAllowlist::BASELINE to '.$pending.' so it cannot grow back.');
});
