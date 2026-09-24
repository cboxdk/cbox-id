<?php

declare(strict_types=1);

namespace App\Platform\Staff\ValueObjects;

use App\Platform\SodRefusal;

/**
 * A staff role segregation of duties would not allow, and WHERE.
 *
 * The where is the part an organization-scoped refusal never had to say: a staff role lands
 * in every organization the person belongs to at once, so "blocked by Payments" is only
 * actionable when it also names the organization in which the person already holds the
 * other half of the pair.
 */
readonly class StaffGrantRefusal
{
    public function __construct(
        public SodRefusal $conflict,
        /** Null when the conflict is between staff roles, which belong to no organization. */
        public ?string $organizationName = null,
    ) {}

    public function message(): string
    {
        return $this->organizationName === null
            ? $this->conflict->message().' Both would be staff roles.'
            : 'In '.$this->organizationName.': '.$this->conflict->message();
    }
}
