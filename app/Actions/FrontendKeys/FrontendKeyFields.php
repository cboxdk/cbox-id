<?php

declare(strict_types=1);

namespace App\Actions\FrontendKeys;

use App\Http\Resources\Environment\Timestamp;
use App\Platform\Actions\Input\Field;
use Cbox\Id\FrontendApi\Models\AllowedOrigin;
use Cbox\Id\FrontendApi\Models\PublishableKey;

/**
 * Publishable frontend keys as the management API takes and returns them. A helper, not an
 * action.
 *
 * THE KEY IS RETURNED IN FULL, ALWAYS — unlike every other credential here — because it is
 * not a secret: it ships in a JavaScript bundle. The allow-list of origins is the control.
 * Treating the value as sensitive teaches somebody to proxy it through their own server and
 * lose the point of having it.
 */
final class FrontendKeyFields
{
    public static function origins(): Field
    {
        return Field::list('origins', Field::string('origin')->max(255))
            ->min(1)
            ->max(50)
            ->describe('The exact origins (scheme, host, port) allowed to present the key, for example https://app.example.com. At least one.');
    }

    /**
     * One key — `FrontendKey` in the spec.
     *
     * @return array<string, mixed>
     */
    public static function present(PublishableKey $key): array
    {
        return [
            'id' => $key->id,
            'name' => $key->name,
            'key' => $key->key,
            'mode' => $key->mode->value,
            'origins' => $key->origins->map(static fn (AllowedOrigin $origin): string => $origin->origin)->values()->all(),
            'active' => $key->isActive(),
            'created_at' => Timestamp::of($key->getAttribute('created_at')),
            'last_used_at' => Timestamp::of($key->last_used_at),
            'revoked_at' => Timestamp::of($key->revoked_at),
        ];
    }
}
