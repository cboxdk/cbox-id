<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Platform\Actions\ActionRefused;
use Cbox\Id\Identity\Models\User;

/**
 * Shared lookups and the wire shape for the user actions. A helper, not an action.
 *
 * `User` in `resources/openapi/environment.yaml`: the same five fields the management API
 * has always answered with, written once so a list, a read and every write that returns the
 * person cannot drift apart.
 */
final class UserFields
{
    /**
     * A user of THIS environment. The built-in user store is hard environment-scoped, so an
     * id minted in another environment resolves to nothing — never to a row that is compared
     * afterwards — and the answer is the same 404 as for an id that never existed.
     *
     * @throws ActionRefused
     */
    public static function find(string $id): User
    {
        return User::query()->whereKey($id)->first() ?? throw ActionRefused::notFound('user');
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'status' => $user->status->value,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
        ];
    }
}
