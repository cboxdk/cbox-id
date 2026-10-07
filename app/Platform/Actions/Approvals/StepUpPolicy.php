<?php

declare(strict_types=1);

namespace App\Platform\Actions\Approvals;

use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\Danger;

/**
 * Which of a credential's actions need a person's approval before they run.
 *
 * Stored on a management key (`step_up_policy`) as
 * `{"min_danger": "critical", "actions": ["apps.secrets.rotate"]}`: every action at or above
 * `min_danger`, plus the named ones. A key with no policy needs no approval — its scopes are
 * its limit, which is the default the owner chose. A key minted by a key inherits at least
 * its parent's policy ({@see self::strictest()}), so an agent cannot shed the supervision
 * it was given by minting itself a fresh key.
 */
final readonly class StepUpPolicy
{
    /**
     * @param  list<string>  $actions
     */
    public function __construct(
        public ?Danger $minDanger = null,
        public array $actions = [],
    ) {}

    /**
     * @param  array<mixed>|null  $stored
     */
    public static function fromArray(?array $stored): ?self
    {
        if ($stored === null) {
            return null;
        }

        $danger = is_string($stored['min_danger'] ?? null) ? Danger::tryFrom($stored['min_danger']) : null;
        $actions = is_array($stored['actions'] ?? null)
            ? array_values(array_filter($stored['actions'], 'is_string'))
            : [];

        return $danger === null && $actions === [] ? null : new self($danger, $actions);
    }

    /**
     * @return array{min_danger: string|null, actions: list<string>}
     */
    public function toArray(): array
    {
        return ['min_danger' => $this->minDanger?->value, 'actions' => $this->actions];
    }

    public function requires(ActionDefinition $action): bool
    {
        if (in_array($action->name, $this->actions, true)) {
            return true;
        }

        return $this->minDanger !== null && self::rank($action->danger) >= self::rank($this->minDanger);
    }

    /** The stricter of two policies: the lower threshold and every named action of both. */
    public static function strictest(?self $a, ?self $b): ?self
    {
        if ($a === null) {
            return $b;
        }

        if ($b === null) {
            return $a;
        }

        $danger = match (true) {
            $a->minDanger === null => $b->minDanger,
            $b->minDanger === null => $a->minDanger,
            default => self::rank($a->minDanger) <= self::rank($b->minDanger) ? $a->minDanger : $b->minDanger,
        };

        return new self($danger, array_values(array_unique([...$a->actions, ...$b->actions])));
    }

    private static function rank(Danger $danger): int
    {
        return match ($danger) {
            Danger::Read => 0,
            Danger::Write => 1,
            Danger::Destructive => 2,
            Danger::Critical => 3,
        };
    }
}
