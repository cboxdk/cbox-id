<?php

declare(strict_types=1);

namespace App\Actions\Keys;

use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\Principal\EnvironmentKeyPrincipal;
use App\Platform\Actions\Principal\Principal;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Platform\Models\EnvironmentApiKey;

/**
 * Shared lookups for the key actions. A helper, not an action.
 */
final class KeyFields
{
    /** The environment the acting principal is on. */
    public static function environmentId(Principal $principal): string
    {
        if ($principal instanceof EnvironmentKeyPrincipal) {
            return $principal->key()->environment_id;
        }

        $current = app(EnvironmentContext::class)->current()?->environmentKey();

        return is_string($current) ? $current : throw ActionRefused::notFound('environment');
    }

    /**
     * A key of THIS environment — the model is environment-scoped, so another environment's
     * id is simply not found.
     *
     * @throws ActionRefused
     */
    public static function find(string $id): EnvironmentApiKey
    {
        return EnvironmentApiKey::query()->whereKey($id)->first() ?? throw ActionRefused::notFound('key');
    }
}
