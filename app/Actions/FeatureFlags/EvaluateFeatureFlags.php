<?php

declare(strict_types=1);

namespace App\Actions\FeatureFlags;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\FeatureFlags\Contracts\FeatureFlags;
use Cbox\Id\FeatureFlags\ValueObjects\FlagEvaluation;

/**
 * EVERY FLAG'S ANSWER FOR ONE USER IN ONE ORGANIZATION — what an app's backend asks when it
 * holds no token for the person (a job, a webhook handler) or must see a flip at once
 * rather than at the next token.
 *
 * Read from the same cached, compiled set the token's `feature_flags` claim is, so it is as
 * cheap as the claim and never disagrees with it: `feature_flags` in the answer is the list
 * the claim would carry, `evaluations` every flag with its answer. Each answer carries the rule that decided
 * — `user_target`, `organization_target`, `rollout`, `default` or `disabled` — so "why does
 * Acme see this?" has an answer.
 *
 * Ids that name nobody in this environment are not refused: they match no rule, which is
 * the truthful answer for them, and refusing would make this an existence oracle.
 */
#[AsAction(
    name: 'feature_flags.evaluate',
    summary: 'Evaluate every feature flag for a user in an organization: whether each is on, and the rule that decided. For app backends without a token in hand.',
    scope: 'feature_flags:read',
    danger: Danger::Read,
    schema: 'FeatureFlagEvaluation',
    tag: 'Feature flags',
    rest: ['GET', '/feature-flags/evaluate'],
)]
final readonly class EvaluateFeatureFlags implements Action
{
    public function __construct(private FeatureFlags $flags) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('user_id')->max(128)->describe('The user to evaluate for. Left out, only organization rules, an organization-bucketed rollout and defaults apply.'),
            Field::string('organization_id')->max(64)->describe('The organization the question is asked in.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $userId = $context->nullableString('user_id');
        $organizationId = $context->nullableString('organization_id');

        $answers = $this->flags->evaluateAll($userId, $organizationId);

        $on = array_keys(array_filter($answers, static fn (FlagEvaluation $answer): bool => $answer->enabled));

        return ActionResult::item($answers, [
            'user_id' => $userId,
            'organization_id' => $organizationId,
            // Exactly what the token's `feature_flags` claim would carry for them.
            'feature_flags' => array_map('strval', $on),
            'evaluations' => array_values(array_map(static fn (FlagEvaluation $answer): array => $answer->toArray(), $answers)),
        ]);
    }
}
