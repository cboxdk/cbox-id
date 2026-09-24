<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;
use Cbox\Id\Organization\Models\Environment;

/**
 * One environment an app may be copied into.
 */
final readonly class CopyTargetProps implements Prop
{
    public function __construct(
        public string $id,
        public string $name,
        public string $kind,
    ) {}

    public static function of(Environment $environment): self
    {
        return new self($environment->id, $environment->name, $environment->type->label());
    }

    /**
     * @return array{id: string, name: string, kind: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
        ];
    }
}
