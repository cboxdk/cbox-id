<?php

declare(strict_types=1);

namespace App\Platform;

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;

/**
 * The environment this request is running in, read once.
 *
 * Three view components need it to label themselves — the delete dialog, its environment
 * badge, and the sandbox banner — and each queried for it independently. The badge is
 * nested INSIDE the dialog, and the dialog renders once per deletable row, so the cost
 * was two queries per row: measured at 37 queries on a members page with two members and
 * 83 with twenty-five, growing exactly linearly. Forty-five call sites across the
 * console.
 *
 * Scoped to the request (a singleton in Laravel's per-request container), so the whole
 * page pays for one lookup. Nothing here is cached across requests: the environment a
 * host resolves to is already cached upstream, and a stale answer HERE would put the
 * wrong environment's name on a confirmation dialog — the exact failure the dialog's
 * label exists to prevent.
 */
final class CurrentEnvironment
{
    private bool $resolved = false;

    private ?Environment $environment = null;

    public function __construct(private readonly EnvironmentContext $context) {}

    public function get(): ?Environment
    {
        if ($this->resolved) {
            return $this->environment;
        }

        $this->resolved = true;
        $key = $this->context->current()?->environmentKey();

        $this->environment = $key === null
            ? null
            : Environment::query()->whereKey($key)->first(['id', 'name', 'type']);

        return $this->environment;
    }

    public function name(): ?string
    {
        return $this->get()?->name;
    }

    /** The environment type as its backing string — 'production', 'sandbox', … */
    public function type(): ?string
    {
        $type = $this->get()?->type;

        return $type instanceof \BackedEnum ? (string) $type->value : null;
    }

    public function isSandbox(): bool
    {
        return $this->type() === 'sandbox';
    }
}
