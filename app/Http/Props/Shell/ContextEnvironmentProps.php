<?php

declare(strict_types=1);

namespace App\Http\Props\Shell;

use App\Http\Props\Prop;

/**
 * One environment in the topbar's context switcher.
 *
 * `href` is the WHOLE way there: the workspace host's `/open/{environment}`, which checks
 * access and mints the signed handoff, carrying the page to land on as `?to=`. The browser
 * follows it as a plain navigation — it leaves this host — so the switcher never has to
 * know how a handoff works.
 */
final readonly class ContextEnvironmentProps implements Prop
{
    public function __construct(
        public string $id,
        public string $name,
        /** The environment type's backing value — `production`, `sandbox`, … */
        public string $type,
        public bool $current,
        public string $href,
    ) {}

    /**
     * @return array{id: string, name: string, type: string, current: bool, href: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'current' => $this->current,
            'href' => $this->href,
        ];
    }
}
