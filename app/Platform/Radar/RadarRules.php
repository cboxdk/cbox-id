<?php

declare(strict_types=1);

namespace App\Platform\Radar;

use App\Models\Radar\RadarRule;
use App\Platform\Radar\Enums\RadarAction;
use App\Platform\Radar\Enums\RadarRuleScope;
use Illuminate\Database\Eloquent\Collection;

/**
 * An environment's own rules: written, rewritten, reordered and removed — always through
 * here, so a rule is checked the same way whichever door saved it, and positions stay a
 * dense 1..n with no two rules sharing one.
 */
final class RadarRules
{
    /**
     * In evaluation order.
     *
     * @return Collection<int, RadarRule>
     */
    public function all(): Collection
    {
        return RadarRule::query()->orderBy('position')->orderBy('id')->get();
    }

    public function find(string $id): ?RadarRule
    {
        return RadarRule::query()->whereKey($id)->first();
    }

    /**
     * @param  array<mixed>  $conditions
     *
     * @throws RadarRuleInvalid
     */
    public function create(
        string $name,
        ?string $description,
        RadarAction $action,
        RadarRuleScope $scope,
        array $conditions,
        bool $enabled = true,
        ?int $position = null,
    ): RadarRule {
        if (RadarRule::query()->count() >= RadarRule::MAX_RULES) {
            throw new RadarRuleInvalid('An environment may have at most '.RadarRule::MAX_RULES.' rules.');
        }

        $normalized = RadarConditions::normalize($conditions);
        $count = RadarRule::query()->count();
        $position = $position === null ? $count + 1 : max(1, min($position, $count + 1));

        // Make room: everything at or after the new position moves down one.
        RadarRule::query()->where('position', '>=', $position)->increment('position');

        $rule = new RadarRule;
        $rule->forceFill([
            'name' => self::name($name),
            'description' => self::description($description),
            'action' => $action,
            'applies_to' => $scope,
            'conditions' => $normalized,
            'enabled' => $enabled,
            'position' => $position,
        ])->save();

        $this->compact();

        return $rule->refresh();
    }

    /**
     * Change what was given; `conditions` replaces them whole. Returns the fields that changed.
     *
     * @param  array<string, mixed>  $changes
     * @return list<string>
     *
     * @throws RadarRuleInvalid
     */
    public function update(RadarRule $rule, array $changes): array
    {
        $fill = [];

        if (array_key_exists('name', $changes)) {
            $fill['name'] = self::name(is_string($changes['name']) ? $changes['name'] : '');
        }

        if (array_key_exists('description', $changes)) {
            $fill['description'] = self::description(is_string($changes['description']) ? $changes['description'] : null);
        }

        if (($changes['action'] ?? null) instanceof RadarAction) {
            $fill['action'] = $changes['action'];
        }

        if (($changes['applies_to'] ?? null) instanceof RadarRuleScope) {
            $fill['applies_to'] = $changes['applies_to'];
        }

        if (is_bool($changes['enabled'] ?? null)) {
            $fill['enabled'] = $changes['enabled'];
        }

        if (array_key_exists('conditions', $changes)) {
            $fill['conditions'] = RadarConditions::normalize(is_array($changes['conditions']) ? $changes['conditions'] : []);
        }

        $rule->forceFill($fill);
        $dirty = array_keys($rule->getDirty());
        $rule->save();

        if (is_int($changes['position'] ?? null) && $changes['position'] !== $rule->position) {
            $this->move($rule, $changes['position']);
            $dirty[] = 'position';
        }

        return array_values(array_unique($dirty));
    }

    public function delete(RadarRule $rule): void
    {
        $rule->delete();
        $this->compact();
    }

    /**
     * Put the rules in exactly this order. Every rule of the environment, each once — a
     * partial order would leave the rest wherever it happened to fall.
     *
     * @param  list<string>  $ids
     *
     * @throws RadarRuleInvalid
     */
    public function reorder(array $ids): void
    {
        $existing = $this->all()->modelKeys();

        if (count($ids) !== count(array_unique($ids)) || array_diff($existing, $ids) !== [] || array_diff($ids, $existing) !== []) {
            throw new RadarRuleInvalid('Name every rule of this environment exactly once, in the order to evaluate them.');
        }

        foreach ($ids as $index => $id) {
            RadarRule::query()->whereKey($id)->update(['position' => $index + 1]);
        }
    }

    private function move(RadarRule $rule, int $position): void
    {
        $ids = $this->all()->modelKeys();
        $ids = array_values(array_filter($ids, static fn (mixed $id): bool => $id !== $rule->id));
        $position = max(1, min($position, count($ids) + 1));
        array_splice($ids, $position - 1, 0, [$rule->id]);

        $this->reorder(array_map(static fn (mixed $id): string => (string) $id, $ids));
        $rule->refresh();
    }

    /** Positions 1..n, in the current order, with no gaps or ties. */
    private function compact(): void
    {
        foreach ($this->all()->values() as $index => $rule) {
            if ($rule->position !== $index + 1) {
                RadarRule::query()->whereKey($rule->id)->update(['position' => $index + 1]);
            }
        }
    }

    private static function name(string $name): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 120) {
            throw new RadarRuleInvalid('A rule needs a name of at most 120 characters.');
        }

        return $name;
    }

    private static function description(?string $description): ?string
    {
        $description = $description === null ? null : trim($description);

        if ($description !== null && mb_strlen($description) > 500) {
            throw new RadarRuleInvalid('A description may be at most 500 characters.');
        }

        return $description === '' ? null : $description;
    }
}
