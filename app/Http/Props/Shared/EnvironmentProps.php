<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Prop;
use App\Platform\CurrentEnvironment;

/**
 * WHICH REALM THIS REQUEST IS ACTING IN.
 *
 * Shared with every page because the answer belongs to the chrome, and because the
 * failure it prevents is a two-tab failure: the console renders identically for staging
 * and production apart from a name, and the breadcrumb carrying that name was
 * `hidden lg:flex` — so below that breakpoint there was no indication at all, and two
 * tabs were indistinguishable at the moment of hitting Delete.
 *
 * `type` is the environment type's backing value (`production`, `sandbox`, …), and
 * `sandbox` is derived rather than inferred by the client, because "is this a real
 * environment?" is not a question a browser should be answering from a string.
 */
final readonly class EnvironmentProps implements Prop
{
    public function __construct(
        public ?string $name,
        public ?string $type,
        public bool $sandbox,
    ) {}

    public static function from(CurrentEnvironment $environment): self
    {
        return new self(
            name: $environment->name(),
            type: $environment->type(),
            sandbox: $environment->isSandbox(),
        );
    }

    /**
     * @return array{name: string|null, type: string|null, sandbox: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'sandbox' => $this->sandbox,
        ];
    }
}
