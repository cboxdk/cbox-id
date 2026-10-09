<?php

declare(strict_types=1);

namespace App\Platform\Radar;

use App\Models\Radar\RadarListEntry;
use App\Models\Radar\RadarRule;
use App\Models\RiskDecision;
use App\Platform\Radar\Enums\RadarBuiltin;
use App\Platform\Radar\Enums\RadarFlow;

/**
 * Radar's resources as the API, MCP and the console read them — one shape each, so the three
 * cannot drift. `RadarRule`, `RadarListEntry`, `RadarDecision` and `RadarSettings` in the spec.
 */
final class RadarPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function rule(RadarRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'description' => $rule->description,
            'position' => $rule->position,
            'enabled' => $rule->enabled,
            'applies_to' => $rule->applies_to->value,
            'action' => $rule->action->value,
            'conditions' => $rule->conditions,
            'summary' => implode(' and ', array_filter(array_map(RadarConditions::describe(...), $rule->conditions))),
            'created_at' => $rule->created_at->toIso8601String(),
            'updated_at' => $rule->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function entry(RadarListEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'list' => $entry->list->value,
            'kind' => $entry->kind->value,
            'value' => $entry->value,
            'note' => $entry->note,
            'expires_at' => $entry->expires_at?->toIso8601String(),
            'active' => $entry->active(),
            'created_at' => $entry->created_at->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, string>  $names  rule key => name ({@see RadarDecisions::ruleNames()})
     * @return array<string, mixed>
     */
    public static function decision(RiskDecision $decision, array $names = []): array
    {
        $triggered = array_values(array_filter($decision->triggered ?? [], 'is_string'));

        return [
            'id' => $decision->id,
            'assessed_at' => $decision->assessed_at->toIso8601String(),
            'flow' => RadarFlow::fromRiskAction($decision->action)->value,
            'method' => $decision->method,
            'verdict' => $decision->verdict ?? 'allow',
            'enforced' => $decision->enforced,
            'mode' => $decision->mode,
            'rule' => $decision->rule,
            'rule_name' => $decision->rule === null ? null : ($names[$decision->rule] ?? $decision->rule),
            'triggered' => array_map(static fn (string $key): array => ['rule' => $key, 'name' => $names[$key] ?? $key], $triggered),
            'reasons' => array_values(array_filter($decision->reasons, 'is_string')),
            'risk_score' => round($decision->score, 2),
            'risk_outcome' => $decision->outcome->value,
            'country' => $decision->country,
            'asn' => $decision->asn,
            'email_domain' => $decision->email_domain,
            'device' => $decision->device_hash,
            'facts' => (object) ($decision->facts ?? []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function settings(RadarPolicy $policy): array
    {
        $builtins = [];

        foreach ($policy->builtins() as $key => $setting) {
            $rule = RadarBuiltin::from($key);
            $bounds = $rule->thresholdBounds();

            $builtins[] = [
                'key' => $key,
                'name' => $rule->label(),
                'description' => $rule->description(),
                'applies_to' => $rule->scope()->value,
                'enabled' => $setting['enabled'],
                'action' => $setting['action']->value,
                'threshold' => $setting['threshold'],
                'threshold_unit' => $rule->thresholdUnit(),
                'threshold_min' => $bounds[0] ?? null,
                'threshold_max' => $bounds[1] ?? null,
            ];
        }

        return [
            'mode' => $policy->mode()->value,
            'mode_inherited' => $policy->modeInherited(),
            'deployment_mode' => RadarPolicy::deploymentMode()->value,
            'ip_intelligence' => self::intelligenceDriver(),
            'builtin_rules' => $builtins,
        ];
    }

    /** Which IP intelligence source is configured — `none`, `maxmind` or `ipinfo`. */
    public static function intelligenceDriver(): string
    {
        $driver = config('cbox-id.radar.ip_intelligence.driver', 'none');

        return is_string($driver) && in_array($driver, ['maxmind', 'ipinfo'], true) ? $driver : 'none';
    }
}
