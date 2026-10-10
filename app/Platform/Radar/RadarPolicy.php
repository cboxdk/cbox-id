<?php

declare(strict_types=1);

namespace App\Platform\Radar;

use App\Models\Radar\RadarListEntry;
use App\Models\Radar\RadarRule;
use App\Models\Radar\RadarSettings;
use App\Platform\Radar\Enums\RadarAction;
use App\Platform\Radar\Enums\RadarBuiltin;
use App\Platform\Radar\Enums\RadarMode;
use Illuminate\Support\Carbon;

/**
 * The current environment's Radar configuration — its mode, its built-in rules, its own
 * rules and its lists — with every default filled in.
 *
 * Every read goes through the environment-owned models, so it is ONE environment's,
 * whichever door asked. Nothing is cached between requests: a mode switch or a new deny
 * entry takes effect on the next attempt.
 */
final class RadarPolicy
{
    /**
     * The deployment's default mode — `RISK_MODE` — which an environment follows until it
     * chooses its own.
     */
    public static function deploymentMode(): RadarMode
    {
        return config('risk.mode') === RadarMode::Enforce->value ? RadarMode::Enforce : RadarMode::Monitor;
    }

    public function mode(): RadarMode
    {
        $stored = $this->stored()?->mode;

        return is_string($stored) ? (RadarMode::tryFrom($stored) ?? self::deploymentMode()) : self::deploymentMode();
    }

    /** Whether the mode is the deployment's default rather than the environment's own choice. */
    public function modeInherited(): bool
    {
        $stored = $this->stored()?->mode;

        return ! is_string($stored) || RadarMode::tryFrom($stored) === null;
    }

    public function updatedAt(): ?Carbon
    {
        return $this->stored()?->updated_at;
    }

    /**
     * Set the mode. Returns the change, or [] when it was already so. Choosing the mode the
     * environment was inheriting is a change too — from then on the deployment's default no
     * longer moves it.
     *
     * @return array<string, array{from: string|bool, to: string|bool}>
     */
    public function setMode(RadarMode $mode): array
    {
        $before = $this->mode();
        $inherited = $this->modeInherited();

        if ($before === $mode && ! $inherited) {
            return [];
        }

        $settings = $this->stored() ?? new RadarSettings;
        $settings->forceFill(['mode' => $mode->value])->save();

        $changes = $before === $mode ? [] : ['mode' => ['from' => $before->value, 'to' => $mode->value]];

        return $inherited ? [...$changes, 'mode_inherited' => ['from' => true, 'to' => false]] : $changes;
    }

    /**
     * Every built-in rule with this environment's tuning applied.
     *
     * @return array<string, array{enabled: bool, action: RadarAction, threshold: int|null}>
     */
    public function builtins(): array
    {
        $stored = $this->stored()->builtin_rules ?? [];
        $builtins = self::defaultBuiltins();

        foreach ($builtins as $key => $default) {
            $own = $stored[$key] ?? null;

            if (! is_array($own)) {
                continue;
            }

            $action = is_string($own['action'] ?? null) ? RadarAction::tryFrom($own['action']) : null;
            $threshold = $own['threshold'] ?? null;

            $builtins[$key] = [
                'enabled' => is_bool($own['enabled'] ?? null) ? $own['enabled'] : $default['enabled'],
                'action' => $action ?? $default['action'],
                'threshold' => $default['threshold'] !== null && is_int($threshold) ? $threshold : $default['threshold'],
            ];
        }

        return $builtins;
    }

    /**
     * @return array<string, array{enabled: bool, action: RadarAction, threshold: int|null}>
     */
    public static function defaultBuiltins(): array
    {
        $defaults = [];

        foreach (RadarBuiltin::cases() as $rule) {
            $defaults[$rule->value] = [
                'enabled' => $rule->defaultEnabled(),
                'action' => $rule->defaultAction(),
                'threshold' => $rule->defaultThreshold(),
            ];
        }

        return $defaults;
    }

    /**
     * Tune built-in rules. `$changes` is keyed by rule, each with any of `enabled`, `action`
     * and `threshold`. Returns what changed, as from/to pairs, for the trail.
     *
     * @param  array<mixed>  $changes
     * @return array<string, array<string, array{from: mixed, to: mixed}>>
     *
     * @throws RadarRuleInvalid
     */
    public function updateBuiltins(array $changes): array
    {
        $current = $this->builtins();
        $next = $current;

        foreach ($changes as $key => $change) {
            $rule = is_string($key) ? RadarBuiltin::tryFrom($key) : null;

            if ($rule === null) {
                throw new RadarRuleInvalid('`'.$key.'` is not a built-in rule. The built-in rules are '.implode(', ', RadarBuiltin::values()).'.');
            }

            if (! is_array($change)) {
                throw new RadarRuleInvalid("`{$rule->value}` takes an object of enabled, action and threshold.");
            }

            $setting = $next[$rule->value];

            if (array_key_exists('enabled', $change)) {
                if (! is_bool($change['enabled'])) {
                    throw new RadarRuleInvalid("`{$rule->value}.enabled` is true or false.");
                }

                $setting['enabled'] = $change['enabled'];
            }

            if (array_key_exists('action', $change)) {
                $action = is_string($change['action']) ? RadarAction::tryFrom($change['action']) : null;

                if ($action === null) {
                    throw new RadarRuleInvalid("`{$rule->value}.action` is one of ".implode(', ', RadarAction::values()).'.');
                }

                $setting['action'] = $action;
            }

            if (array_key_exists('threshold', $change) && $change['threshold'] !== null) {
                $bounds = $rule->thresholdBounds();

                if ($bounds === null) {
                    throw new RadarRuleInvalid("`{$rule->value}` has no threshold.");
                }

                $threshold = $change['threshold'];

                if (! is_int($threshold) || $threshold < $bounds[0] || $threshold > $bounds[1]) {
                    throw new RadarRuleInvalid("`{$rule->value}.threshold` is a whole number from {$bounds[0]} to {$bounds[1]} ({$rule->thresholdUnit()}).");
                }

                $setting['threshold'] = $threshold;
            }

            $next[$rule->value] = $setting;
        }

        $diff = [];

        foreach ($next as $key => $setting) {
            foreach ($setting as $name => $value) {
                $before = $current[$key][$name];

                if ($before !== $value) {
                    $diff[$key][$name] = [
                        'from' => $before instanceof RadarAction ? $before->value : $before,
                        'to' => $value instanceof RadarAction ? $value->value : $value,
                    ];
                }
            }
        }

        if ($diff === []) {
            return [];
        }

        $stored = [];

        foreach ($next as $key => $setting) {
            $stored[$key] = [
                'enabled' => $setting['enabled'],
                'action' => $setting['action']->value,
                'threshold' => $setting['threshold'],
            ];
        }

        $settings = $this->stored() ?? new RadarSettings;
        $settings->forceFill(['builtin_rules' => $stored])->save();

        return $diff;
    }

    /**
     * Everything one attempt is judged against.
     */
    public function snapshot(): RadarPolicySnapshot
    {
        $rules = [];

        foreach (RadarRule::query()->where('enabled', true)->orderBy('position')->orderBy('id')->limit(RadarRule::MAX_RULES)->get() as $rule) {
            $rules[] = [
                'id' => $rule->id,
                'name' => $rule->name,
                'scope' => $rule->applies_to,
                'action' => $rule->action,
                'conditions' => array_values(array_filter($rule->conditions, 'is_array')),
            ];
        }

        $entries = [];

        $active = RadarListEntry::query()
            ->where(static fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()))
            ->limit(RadarListEntry::MAX_ENTRIES)
            ->get();

        foreach ($active as $entry) {
            $entries[] = ['list' => $entry->list, 'kind' => $entry->kind, 'value' => $entry->value];
        }

        return new RadarPolicySnapshot($this->mode(), $this->builtins(), $rules, $entries);
    }

    private function stored(): ?RadarSettings
    {
        return RadarSettings::query()->first();
    }
}
