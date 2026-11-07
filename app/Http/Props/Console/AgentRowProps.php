<?php

declare(strict_types=1);

namespace App\Http\Props\Console;

use App\Http\Props\Prop;
use App\Platform\Actions\Approvals\StepUpPolicy;
use App\Platform\Actions\Danger;
use Cbox\Id\Platform\Models\EnvironmentApiKey;

/**
 * A management key, drawn as the agent that holds it.
 *
 * Everything the list needs to answer "what can this thing do, and who answers for it" at
 * a glance: the scopes summarised rather than enumerated, the highest risk they unlock, the
 * approval its owner asked for, who minted it and through which key, and its lifecycle.
 * The key's value is never here — it exists once, on the flash channel, when it is minted.
 */
final readonly class AgentRowProps implements Prop
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public EnvironmentApiKey $key,
        public array $scopes,
        public string $scopeSummary,
        public Danger $risk,
        public ?StepUpPolicy $policy,
        public string $createdBy,
        public int $depth,
        public int $descendants,
        public KeyLifecycleProps $lifecycle,
        public ?string $rotateHref,
        public ?string $revokeHref,
    ) {}

    /** "Approval for critical actions", in the words the console uses everywhere. */
    public static function approvalLabel(?StepUpPolicy $policy): string
    {
        if ($policy === null) {
            return __('console.no_approval_needed_f4d6c290');
        }

        $level = match ($policy->minDanger) {
            Danger::Critical => __('console.approval_for_critical_actions_b750f416'),
            Danger::Destructive => __('console.approval_for_destructive_and_critical_action_a076714f'),
            Danger::Write, Danger::Read => __('console.approval_for_every_change_617da932'),
            null => null,
        };

        $named = count($policy->actions);

        if ($level === null) {
            return __('console.agent_named_approval_count', ['count' => $named]);
        }

        return $named === 0 ? $level : __('console.agent_level_and_named_approval_count', ['level' => $level, 'count' => $named]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->key->id,
            'name' => $this->key->name,
            'description' => $this->key->description,
            'prefix' => $this->key->prefix,
            'scopes' => $this->scopes,
            'scopeSummary' => $this->scopeSummary,
            'risk' => $this->risk->value,
            'approval' => [
                'label' => self::approvalLabel($this->policy),
                'minDanger' => $this->policy?->minDanger?->value,
                'actions' => $this->policy->actions ?? [],
            ],
            'createdBy' => $this->createdBy,
            'parentId' => $this->key->parent_key_id,
            'rotatedFromId' => $this->key->rotated_from_id,
            'depth' => $this->depth,
            'descendants' => $this->descendants,
            'lifecycle' => $this->lifecycle->toArray(),
            'rotateHref' => $this->rotateHref,
            'revokeHref' => $this->revokeHref,
        ];
    }
}
