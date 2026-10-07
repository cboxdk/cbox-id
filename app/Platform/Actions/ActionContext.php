<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Platform\Actions\Principal\Principal;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditActor;

/**
 * What an action is given to work with: who is acting, and its input — already validated
 * against the action's own schema, so a field that is present has the declared type.
 */
final readonly class ActionContext
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public Principal $principal,
        public array $input,
    ) {}

    /** Who the trail names for what this action does. */
    public function actor(): AuditActor
    {
        return $this->principal->auditActor();
    }

    /** Whether the caller said anything about $key — an explicit null included. */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->input);
    }

    public function string(string $key): string
    {
        $value = $this->input[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /** The value, or null when absent, null or empty. */
    public function nullableString(string $key): ?string
    {
        $value = $this->input[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        if (! $this->has($key)) {
            return $default;
        }

        return filter_var($this->input[$key], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<mixed>
     */
    public function array(string $key): array
    {
        $value = $this->input[$key] ?? null;

        return is_array($value) ? $value : [];
    }
}
