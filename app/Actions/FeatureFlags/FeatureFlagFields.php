<?php

declare(strict_types=1);

namespace App\Actions\FeatureFlags;

use App\Http\Resources\Environment\Timestamp;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Input\Field;
use Cbox\Id\FeatureFlags\Contracts\FeatureFlags;
use Cbox\Id\FeatureFlags\Exceptions\InvalidFeatureFlag;
use Cbox\Id\FeatureFlags\Models\FeatureFlag;
use Cbox\Id\FeatureFlags\ValueObjects\FlagTargeting;

/**
 * Feature flags as the management API returns them, the inputs their targeting is written
 * with, and the one way a flag is reached. A helper, not an action.
 *
 * A FLAG IS THE ENVIRONMENT'S. Every app and every organization in it reads the same flags,
 * so there is no organization-owned flag and no organization-narrowed read: a flag is found
 * by id in the principal's environment, or it is a 404.
 *
 * TARGETING IS SENT IN PARTS, REPLACED IN PARTS. `users`, `organizations` and
 * `rollout_percentage` are each the complete value of that part when sent, and left alone
 * when not — so a caller that adds an organization does not have to resend every user rule,
 * and a caller that sends `users: []` clears them.
 */
final class FeatureFlagFields
{
    /**
     * The targeting inputs, shared by create and update.
     *
     * @return list<Field>
     */
    public static function targetingFields(): array
    {
        $rule = static fn (string $name, string $what): Field => Field::list($name, Field::object('rule', [
            Field::string('id')->required()->max(128)->describe("The {$what}'s id."),
            Field::boolean('enabled')->describe('On (true, the default) or off for them, whatever the organization, rollout or default say.'),
        ]))->max(1000);

        return [
            $rule('users', 'user')->describe('Rules for named users — they outrank every other rule. The COMPLETE list when sent.'),
            $rule('organizations', 'organization')->describe('Rules for named organizations — they outrank the rollout and the default. The COMPLETE list when sent.'),
            Field::integer('rollout_percentage')->nullable()->min(0)->max(100)->describe('On for this percentage of everyone else, by a stable hash of the user id (or the organization id without one). null for no rollout.'),
        ];
    }

    /**
     * The targeting to save: what the caller sent for each part, and $current for the rest.
     */
    public static function targeting(ActionContext $context, FlagTargeting $current): FlagTargeting
    {
        return new FlagTargeting(
            users: $context->has('users') ? self::rules($context->array('users')) : $current->users,
            organizations: $context->has('organizations') ? self::rules($context->array('organizations')) : $current->organizations,
            rolloutPercentage: $context->has('rollout_percentage') ? self::percentage($context) : $current->rolloutPercentage,
        );
    }

    /** Whether the caller sent any part of the targeting. */
    public static function sendsTargeting(ActionContext $context): bool
    {
        return $context->has('users') || $context->has('organizations') || $context->has('rollout_percentage');
    }

    /** @throws ActionRefused */
    public static function find(ActionContext $context, FeatureFlags $flags): FeatureFlag
    {
        return $flags->find($context->string('id')) ?? throw ActionRefused::notFound('feature flag');
    }

    /**
     * The framework's refusal as the API's: its machine reason and the input it is about.
     */
    public static function refusal(InvalidFeatureFlag $invalid): ActionRefused
    {
        $field = match ($invalid->field) {
            'targeting' => 'users',
            default => $invalid->field,
        };

        return ActionRefused::because($invalid->reason, $invalid->getMessage(), $field);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(FeatureFlag $flag): array
    {
        $targeting = $flag->targeting();

        return [
            'id' => $flag->id,
            'key' => $flag->key,
            'description' => $flag->description,
            'enabled' => $flag->enabled,
            'default_value' => $flag->default_value,
            'rollout_percentage' => $flag->rollout_percentage,
            'users' => self::ruleList($targeting->users),
            'organizations' => self::ruleList($targeting->organizations),
            'created_at' => Timestamp::of($flag->created_at),
            'updated_at' => Timestamp::of($flag->updated_at),
        ];
    }

    /**
     * @param  array<string, bool>  $map
     * @return list<array{id: string, enabled: bool}>
     */
    private static function ruleList(array $map): array
    {
        $list = [];

        foreach ($map as $id => $enabled) {
            $list[] = ['id' => (string) $id, 'enabled' => $enabled];
        }

        return $list;
    }

    /**
     * @param  array<mixed>  $rules
     * @return array<string, bool>
     */
    private static function rules(array $rules): array
    {
        $map = [];

        foreach ($rules as $rule) {
            if (is_array($rule) && is_string($rule['id'] ?? null) && trim($rule['id']) !== '') {
                $map[trim($rule['id'])] = filter_var($rule['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $map;
    }

    private static function percentage(ActionContext $context): ?int
    {
        $value = $context->input['rollout_percentage'] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
