<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use InvalidArgumentException;
use Symfony\Component\Finder\Finder;

/**
 * Every action the platform offers, found once from `app/Actions` — and from the action
 * directory of each in-tree module that names one ({@see self::discoverIn()}).
 *
 * The one list every door reads: the REST routes are registered from it, the parity test
 * checks the console against it, and MCP and the CLI will list their tools and commands
 * from it. An action nobody registered by hand cannot be forgotten by a door.
 */
final class ActionRegistry
{
    /** @var array<string, ActionDefinition>|null */
    private ?array $definitions = null;

    /** @var list<array{0: string, 1: string}> Module action directories, with their namespace. */
    private array $modules = [];

    /**
     * @param  string|null  $directory  Where actions live; `app/Actions` unless a test says otherwise.
     */
    public function __construct(
        private readonly ?string $directory = null,
        private readonly string $namespace = 'App\\Actions',
    ) {}

    /**
     * Also find actions in $directory, a module's own.
     *
     * A module registers itself the way an external package would — its own provider,
     * routes and pages, no edit to `app/` — so its actions live beside its code and the
     * module names the directory from its service provider. Explicit rather than a scan of
     * `modules/*`: a module that is not installed must not have an action routed, and
     * only a module whose provider ran can call this. Called as the registry is resolved
     * (`afterResolving` in the module's `register()`), so it holds before any door reads the
     * list, whichever order the providers boot in.
     */
    public function discoverIn(string $directory, string $namespace): void
    {
        $this->modules[] = [$directory, rtrim($namespace, '\\')];
        $this->definitions = null;
    }

    /**
     * @return array<string, ActionDefinition> Keyed by action name, in name order.
     */
    public function all(): array
    {
        return $this->definitions ??= $this->discover();
    }

    /**
     * @return list<ActionDefinition>
     */
    public function forPlane(ActionPlane $plane): array
    {
        $byPlane = [];

        foreach ($this->all() as $action) {
            $byPlane[$action->plane->value][] = $action;
        }

        return $byPlane[$plane->value] ?? [];
    }

    public function named(string $name): ActionDefinition
    {
        return $this->all()[$name] ?? throw new InvalidArgumentException("No action is named {$name}.");
    }

    /**
     * @param  class-string<Action>  $class
     */
    public function forClass(string $class): ActionDefinition
    {
        foreach ($this->all() as $definition) {
            if ($definition->class === $class) {
                return $definition;
            }
        }

        return ActionDefinition::of($class);
    }

    /**
     * @return array<string, ActionDefinition>
     */
    private function discover(): array
    {
        $found = [];

        foreach ([[$this->directory ?? app_path('Actions'), $this->namespace], ...$this->modules] as [$directory, $namespace]) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach (Finder::create()->files()->in($directory)->name('*.php')->sortByName() as $file) {
                $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
                $class = $namespace.'\\'.$relative;

                // Only actions: a helper beside them (a shared rule, a presenter) is not one.
                if (! class_exists($class) || ! is_subclass_of($class, Action::class)) {
                    continue;
                }

                $definition = ActionDefinition::of($class);

                if (isset($found[$definition->name])) {
                    throw new InvalidArgumentException("Two actions are named {$definition->name}: {$found[$definition->name]->class} and {$class}.");
                }

                $found[$definition->name] = $definition;
            }
        }

        ksort($found);

        return $found;
    }
}
