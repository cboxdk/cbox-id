<?php

declare(strict_types=1);

namespace App\Platform\Enums;

use InvalidArgumentException;

/**
 * WHAT ONE ADMIN PORTAL LINK MAY SET UP — a set of {@see PortalIntent}s, never empty.
 *
 * It was an enum of three: `sso`, `scim`, or `both`. That shape could not grow — a fourth
 * capability meant `both` stopped meaning "everything", and every pair needed a case of its
 * own — so the scope is now the set itself, stored as a JSON list on the link and carried in
 * the portal session.
 *
 * DENY BY DEFAULT on the way in: a stored value that names no intent this deployment knows
 * is dropped rather than trusted, and a set left empty by that is no scope at all
 * ({@see fromStored()} answers null) — a link nobody can say the purpose of opens nothing.
 */
final readonly class PortalScope
{
    /**
     * @param  non-empty-list<PortalIntent>  $intents  de-duplicated, in the enum's display order
     */
    private function __construct(public array $intents) {}

    /**
     * @param  iterable<PortalIntent>  $intents
     *
     * @throws InvalidArgumentException when it is empty
     */
    public static function of(iterable $intents): self
    {
        $chosen = [];

        foreach ($intents as $intent) {
            $chosen[$intent->value] = true;
        }

        $ordered = array_values(array_filter(PortalIntent::cases(), static fn (PortalIntent $intent): bool => isset($chosen[$intent->value])));

        if ($ordered === []) {
            throw new InvalidArgumentException('A portal link must cover at least one intent.');
        }

        return new self($ordered);
    }

    /** Shorthand for a link with a single purpose. */
    public static function only(PortalIntent $intent): self
    {
        return new self([$intent]);
    }

    /**
     * The set a stored or session value names, keeping only intents this deployment knows;
     * null when none is left.
     */
    public static function fromStored(mixed $values): ?self
    {
        if (! is_array($values)) {
            return null;
        }

        $intents = [];

        foreach ($values as $value) {
            $intent = is_string($value) ? PortalIntent::tryFrom($value) : null;

            if ($intent !== null) {
                $intents[] = $intent;
            }
        }

        return $intents === [] ? null : self::of($intents);
    }

    public function has(PortalIntent $intent): bool
    {
        return in_array($intent, $this->intents, true);
    }

    /**
     * The intents that let a session run $action — empty when none does.
     *
     * @return list<PortalIntent>
     */
    public function intentsFor(string $action): array
    {
        return array_values(array_filter(
            $this->intents,
            static fn (PortalIntent $intent): bool => in_array($action, $intent->actions(), true),
        ));
    }

    /**
     * The wire form: the stored JSON list, the session's, the API's.
     *
     * @return non-empty-list<string>
     */
    public function values(): array
    {
        return array_map(static fn (PortalIntent $intent): string => $intent->value, $this->intents);
    }
}
