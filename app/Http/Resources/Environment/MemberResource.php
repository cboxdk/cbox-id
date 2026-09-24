<?php

declare(strict_types=1);

namespace App\Http\Resources\Environment;

use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\Organization\Models\Membership;

/**
 * A member of an organization — `Member` in the spec.
 *
 * TWO ROWS, ONE OBJECT: the membership carries the authority (`role`, `status`) and the
 * subject carries the person (`email`, `name`). A member is addressed by `user_id` in
 * every path, because that is the id the caller already holds; `id` is the membership's
 * own and changes if the person leaves and is added again.
 */
final class MemberResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Membership $membership, ?Subject $person): array
    {
        return [
            'id' => $membership->id,
            'user_id' => $membership->user_id,
            'organization_id' => $membership->organization_id,
            'role' => $membership->role->value,
            'status' => $membership->status->value,
            'email' => $person?->email,
            'name' => $person?->name,
            'joined_at' => Timestamp::of($membership->getAttribute('created_at')),
        ];
    }
}
