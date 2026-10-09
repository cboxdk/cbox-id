<?php

declare(strict_types=1);

namespace App\Platform\Radar;

use App\Platform\Radar\Enums\RadarAction;
use App\Platform\Radar\Enums\RadarBuiltin;
use App\Platform\Radar\Enums\RadarList;
use App\Platform\Radar\Enums\RadarListKind;
use App\Platform\Radar\Enums\RadarMode;
use App\Platform\Radar\Enums\RadarRuleScope;

/**
 * One environment's Radar configuration, read once for one attempt and handed to the
 * {@see RadarEngine} — which is then a pure function of it and the facts.
 */
final readonly class RadarPolicySnapshot
{
    /**
     * @param  array<string, array{enabled: bool, action: RadarAction, threshold: int|null}>  $builtins  keyed by {@see RadarBuiltin} value
     * @param  list<array{id: string, name: string, scope: RadarRuleScope, action: RadarAction, conditions: list<array<string, mixed>>}>  $rules  enabled rules, in order
     * @param  list<array{list: RadarList, kind: RadarListKind, value: string}>  $entries  active list entries
     */
    public function __construct(
        public RadarMode $mode,
        public array $builtins,
        public array $rules = [],
        public array $entries = [],
    ) {}

    /** Every default, no rules and no lists: what an environment that chose nothing gets. */
    public static function defaults(RadarMode $mode): self
    {
        return new self($mode, RadarPolicy::defaultBuiltins());
    }

    /**
     * @return array{enabled: bool, action: RadarAction, threshold: int|null}
     */
    public function builtin(RadarBuiltin $rule): array
    {
        return $this->builtins[$rule->value] ?? [
            'enabled' => $rule->defaultEnabled(),
            'action' => $rule->defaultAction(),
            'threshold' => $rule->defaultThreshold(),
        ];
    }

    public function threshold(RadarBuiltin $rule): int
    {
        return $this->builtin($rule)['threshold'] ?? $rule->defaultThreshold() ?? 0;
    }
}
