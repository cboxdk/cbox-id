<?php

declare(strict_types=1);

namespace App\Http\Props\Shell;

use App\Http\Props\Prop;

/**
 * One project in the topbar's context switcher, with the environments under it that this
 * person may open. A project with none of those is not listed at all.
 */
final readonly class ContextProjectProps implements Prop
{
    /**
     * @param  list<ContextEnvironmentProps>  $environments
     */
    public function __construct(
        public string $id,
        public string $name,
        /** Whether the environment this console stands on belongs to this project. */
        public bool $current,
        public array $environments,
    ) {}

    /**
     * @return array{id: string, name: string, current: bool, environments: list<ContextEnvironmentProps>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'current' => $this->current,
            'environments' => $this->environments,
        ];
    }
}
