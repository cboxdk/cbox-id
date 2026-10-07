<?php

declare(strict_types=1);

namespace App\Actions\LegacyLogin;

use App\Http\Resources\Environment\Timestamp;
use Cbox\Id\Migration\Models\LegacyLoginDeclarationRecord;
use Cbox\Id\OAuthServer\Models\Client;

/**
 * The legacy login declaration as the management API returns it — `LegacyLogin` in the
 * spec. A helper, not an action. The shared secret the app declared with it is never part
 * of it.
 */
final class LegacyLoginFields
{
    /**
     * @return array<string, mixed>
     */
    public static function present(?LegacyLoginDeclarationRecord $declaration): array
    {
        return [
            'declared' => $declaration !== null,
            'url' => $declaration?->url,
            'client_id' => $declaration?->client_id,
            /*
             * NAMED rather than left as an id: "this came from Acme Web" is what somebody can
             * judge. `where('client_id', …)`, not `whereKey()` — the declaration stores the
             * OAuth identifier, not the row's primary key.
             */
            'declared_by' => $declaration === null ? null : Client::query()
                ->where('client_id', $declaration->client_id)
                ->value('name'),
            'approved' => $declaration?->isApproved() ?? false,
            'approved_at' => Timestamp::of($declaration?->approved_at),
            'approved_by' => $declaration?->approved_by,
        ];
    }
}
