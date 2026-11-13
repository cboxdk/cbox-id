<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Platform\Actions\Input\InputSchema;
use App\Platform\Scopes\ScopeRegistry;
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
     * @param  list<string>  $redact
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
        public ?string $schema = null,
        public ?string $tag = null,
        public array $redact = [],
        public bool $consoleStepUp = true,
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
            schema: $meta->schema,
            tag: $meta->tag,
            redact: $meta->redact,
            consoleStepUp: $meta->consoleStepUp,
        );
    }

    /**
     * This action as one run of it is rated: the same facts, with the danger the input
     * reaches ({@see RatedByInput}). Never below the declared floor.
     */
    public function withDanger(Danger $danger): self
    {
        if (self::rank($danger) <= self::rank($this->danger)) {
            return $this;
        }

        return new self(
            class: $this->class,
            name: $this->name,
            summary: $this->summary,
            scope: $this->scope,
            danger: $danger,
            plane: $this->plane,
            method: $this->method,
            path: $this->path,
            status: $this->status,
            consoleRoutes: $this->consoleRoutes,
            consoleGate: $this->consoleGate,
            schema: $this->schema,
            tag: $this->tag,
            redact: $this->redact,
            consoleStepUp: $this->consoleStepUp,
        );
    }

    /**
     * Whether some run of this action is Critical: declared so, or rated by its input
     * ({@see RatedByInput}) and so able to become so. What a consent screen flags and what
     * an approval page asks a fresh credential for, where the input is not in hand.
     */
    public function mayBeCritical(): bool
    {
        return $this->danger === Danger::Critical || is_subclass_of($this->class, RatedByInput::class);
    }

    private static function rank(Danger $danger): int
    {
        return match ($danger) {
            Danger::Read => 0,
            Danger::Write => 1,
            Danger::Destructive => 2,
            Danger::Critical => 3,
        };
    }

    public function input(): InputSchema
    {
        return ($this->class)::input();
    }

    /** Where it is served from the API root (`/api/v1`): its plane's mount, then its own path. */
    public function documentedPath(): string
    {
        return $this->plane->documentedPath($this->path);
    }

    /**
     * The scope as a credential of this action's plane holds it today. Organization-plane
     * actions declare the v3 names (`id:<resource>:<read|write>`); the other planes keep
     * v2's until laravel-id's key and token vocabulary learns the new ones, since a key
     * minted with a name its vocabulary does not know is refused ({@see ScopeRegistry}).
     */
    public function documentedScope(): string
    {
        return $this->scope;
    }

    /**
     * The v2 names a credential may still hold this scope by, until v3.1.
     *
     * @return list<string>
     */
    public function scopeAliases(): array
    {
        return array_values(array_filter(
            ScopeRegistry::equivalents($this->scope),
            fn (string $name): bool => $name !== $this->scope && ScopeRegistry::isLegacy($name),
        ));
    }

    /** The MCP tool name: the action name with dots as underscores. */
    public function toolName(): string
    {
        return str_replace(['.', '-'], '_', $this->name);
    }
}
