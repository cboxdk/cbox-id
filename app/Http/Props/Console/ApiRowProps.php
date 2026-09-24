<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;

/**
 * One registered API in the list: what it is called, the audience its tokens carry, who
 * owns it, and the app whose roles it enforces.
 */
final readonly class ApiRowProps implements Prop
{
    public function __construct(
        public string $id,
        public string $name,
        public string $identifier,
        public string $owner,
        public ?string $linkedApp,
        public int $scopeCount,
        public string $href,
    ) {}

    /**
     * @return array{id: string, name: string, identifier: string, owner: string, linkedApp: string|null, scopeCount: int, href: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'identifier' => $this->identifier,
            'owner' => $this->owner,
            'linkedApp' => $this->linkedApp,
            'scopeCount' => $this->scopeCount,
            'href' => $this->href,
        ];
    }
}
