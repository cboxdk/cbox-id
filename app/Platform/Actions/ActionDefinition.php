<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Platform\Actions\Input\InputSchema;
use InvalidArgumentException;
use ReflectionClass;

/**
 * An action as the doors see it: its class, its {@see AsAction} facts and its input.
 */
final readonly class ActionDefinition
{
    /**
     * @param  class-string<Action>  $class
     * @param  list<string>  $consoleRoutes
     */
    private function __construct(
        public string $class,
        public string $name,
        public string $summary,
        public string $scope,
        public Danger $danger,
        public ActionPlane $plane,
        public string $method,
        public string $path,
        public int $status,
        public array $consoleRoutes,
        public ConsoleGate $consoleGate,
    ) {}

    /**
     * @param  class-string  $class
     */
    public static function of(string $class): self
    {
        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getAttributes(AsAction::class);

        if ($attributes === [] || ! $reflection->implementsInterface(Action::class)) {
            throw new InvalidArgumentException("{$class} is not an action: it needs #[AsAction] and to implement ".Action::class.'.');
        }

        $meta = $attributes[0]->newInstance();

        /** @var class-string<Action> $class */
        return new self(
            class: $class,
            name: $meta->name,
            summary: $meta->summary,
            scope: $meta->scope,
            danger: $meta->danger,
            plane: $meta->plane,
            method: strtoupper($meta->rest[0]),
            path: '/'.ltrim($meta->rest[1], '/'),
            status: $meta->status,
            consoleRoutes: $meta->consoleRoutes,
            consoleGate: $meta->consoleGate,
        );
    }

    public function input(): InputSchema
    {
        return ($this->class)::input();
    }

    /** The MCP tool name: the action name with dots as underscores. */
    public function toolName(): string
    {
        return str_replace(['.', '-'], '_', $this->name);
    }
}
