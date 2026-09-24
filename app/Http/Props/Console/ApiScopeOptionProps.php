<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;

/**
 * One scope a registered API owns, as the app page's picker offers it.
 */
final readonly class ApiScopeOptionProps implements Prop
{
    public function __construct(
        public string $key,
        public ?string $description,
    ) {}

    /**
     * @return array{key: string, description: string|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'description' => $this->description,
        ];
    }
}
