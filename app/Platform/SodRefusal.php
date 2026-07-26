<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * A refused role grant, with everything needed to tell the admin WHY in their own
 * words: the rule that tripped, the role they tried to grant, and the roles it
 * collides with. "This grant is not allowed" is unactionable — the admin cannot see
 * which of the subject's existing roles the new one conflicts with, nor under which
 * policy, so they retry, get the same refusal, and file a bug.
 */
final readonly class SodRefusal
{
    /**
     * @param  list<string>  $conflictingRoleNames
     */
    public function __construct(
        public string $policyName,
        public string $proposedRoleName,
        public array $conflictingRoleNames,
    ) {}

    public function message(): string
    {
        $quoted = array_map(static fn (string $name): string => '"'.$name.'"', $this->conflictingRoleNames);

        return 'Blocked by "'.$this->policyName.'": "'.$this->proposedRoleName
            .'" cannot be held together with '.implode(', ', $quoted).'.';
    }
}
