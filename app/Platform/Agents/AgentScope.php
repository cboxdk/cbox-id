<?php

declare(strict_types=1);

namespace App\Platform\Agents;

use App\Http\Props\Prop;
use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\Danger;

/**
 * One scope as the agent scope picker draws it: what it is called, what it lets an agent
 * do, and how much harm that is ({@see AgentScopes}).
 */
final readonly class AgentScope implements Prop
{
    /**
     * @param  list<ActionDefinition>  $actions  the actions that require it
     */
    public function __construct(
        public string $value,
        public string $label,
        public ?string $description,
        public string $resource,
        public string $resourceLabel,
        public Danger $risk,
        /** Whether an approval policy can hold what this scope allows — false for endpoints that are not actions yet. */
        public bool $held,
        public array $actions,
        public int $order,
    ) {}

    /**
     * @return array{value: string, label: string, description: string|null, resource: string, resourceLabel: string, risk: string, held: bool, actions: list<array{name: string, summary: string, danger: string}>}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label,
            'description' => $this->description,
            'resource' => $this->resource,
            'resourceLabel' => $this->resourceLabel,
            'risk' => $this->risk->value,
            'held' => $this->held,
            'actions' => array_map(static fn (ActionDefinition $action): array => [
                'name' => $action->name,
                'summary' => $action->summary,
                'danger' => $action->danger->value,
            ], $this->actions),
        ];
    }
}
