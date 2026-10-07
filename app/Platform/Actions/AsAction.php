<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use Attribute;

/**
 * Declares a class an action: one change (or one read) the platform offers, described
 * once, so every door derives its surface from the same facts.
 *
 * The console, the REST API and (next) MCP and the CLI all run an action through
 * {@see ActionRunner}; this attribute is what they read to know how. Nothing about an
 * action's route, scope or danger is written anywhere else.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsAction
{
    /**
     * @param  string  $name  Dotted, stable, public: `apis.create`. MCP tool names derive from it.
     * @param  string  $summary  One sentence an agent reads to decide whether to call it.
     * @param  string  $scope  The environment-key scope it requires (`apis:write`).
     * @param  array{0: string, 1: string}  $rest  Method and path below the plane's base: `['POST', '/apis']`.
     * @param  list<string>  $consoleRoutes  The console route names this action is the API twin of.
     * @param  ConsoleGate  $consoleGate  The console gate a person passes to run it there.
     * @param  string|null  $schema  The OpenAPI component (`#/components/schemas/…`) its `data` is.
     * @param  string|null  $tag  The OpenAPI tag it is listed under; derived from its name when null.
     */
    public function __construct(
        public string $name,
        public string $summary,
        public string $scope,
        public Danger $danger,
        public array $rest,
        public ActionPlane $plane = ActionPlane::Environment,
        public int $status = 200,
        public array $consoleRoutes = [],
        public ConsoleGate $consoleGate = ConsoleGate::EnvironmentAdmin,
        public ?string $schema = null,
        public ?string $tag = null,
    ) {}
}
