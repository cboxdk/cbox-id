<?php

declare(strict_types=1);

namespace App\Http\Resources\Environment;

use App\Models\InvitationContext;
use Cbox\Id\Organization\Models\Invitation;

/**
 * A pending invitation — `Invitation` in the spec. `roles` are the access roles parked for
 * it, granted when it is accepted; `client_id` and `return_to` are the app it came from and
 * where the person is sent back to. The token is never here: it exists only in the mail.
 */
final class InvitationResource
{
    /**
     * @param  list<string>  $roleIds
     * @return array<string, mixed>
     */
    public static function from(Invitation $invitation, ?InvitationContext $context, array $roleIds): array
    {
        return [
            'id' => $invitation->id,
            'organization_id' => $invitation->organization_id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'roles' => $roleIds,
            'status' => $invitation->status->value,
            'client_id' => $context?->client_id,
            'return_to' => $context?->return_to,
            'invited_at' => Timestamp::of($invitation->getAttribute('created_at')),
            'expires_at' => Timestamp::of($invitation->expires_at),
        ];
    }
}
