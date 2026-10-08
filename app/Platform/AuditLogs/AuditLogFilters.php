<?php

declare(strict_types=1);

namespace App\Platform\AuditLogs;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * What a reader narrowed a list of audit events to — the same filters on the API, the
 * consoles, the Admin Portal and an export, so "export what I am looking at" exports
 * exactly that.
 *
 * `organization_id` is null only for the environment's own authority reading across every
 * organization; a confined reader's is always their own, set by whoever builds this, never
 * by the filter.
 */
final readonly class AuditLogFilters
{
    /**
     * @param  list<string>  $actions
     */
    public function __construct(
        public ?string $organizationId = null,
        public array $actions = [],
        public ?string $actorId = null,
        public ?string $targetId = null,
        public ?CarbonImmutable $rangeStart = null,
        public ?CarbonImmutable $rangeEnd = null,
    ) {}

    /**
     * From an input array — the action's, a query string's. Times that do not parse are
     * dropped, never guessed.
     *
     * @param  array<string, mixed>  $input
     */
    public static function from(array $input, ?string $organizationId): self
    {
        $actions = $input['actions'] ?? [];
        $actions = is_string($actions) ? [$actions] : (is_array($actions) ? $actions : []);

        return new self(
            organizationId: $organizationId,
            actions: array_values(array_filter($actions, static fn (mixed $action): bool => is_string($action) && $action !== '')),
            actorId: self::string($input['actor_id'] ?? null),
            targetId: self::string($input['target_id'] ?? null),
            rangeStart: self::time($input['range_start'] ?? null),
            rangeEnd: self::time($input['range_end'] ?? null),
        );
    }

    /**
     * As stored on an export, and as an export's `filters` are returned.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'actions' => $this->actions,
            'actor_id' => $this->actorId,
            'target_id' => $this->targetId,
            'range_start' => $this->rangeStart?->format('Y-m-d\TH:i:s.v\Z'),
            'range_end' => $this->rangeEnd?->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
