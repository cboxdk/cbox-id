<?php

declare(strict_types=1);

namespace App\Actions\LogStreams;

use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Integrations\IntegrationReach;
use Cbox\Id\AuditStreaming\Models\AuditStream;
use Illuminate\Database\Eloquent\Builder;

/**
 * The log streams a principal may see — OWNED, never deliverable — shared by every log
 * stream action, and not one itself.
 *
 * Unlike a webhook, an organization's administrator does not even SEE the environment's
 * own streams: they are the operator's compliance shipping, and listing them would show a
 * tenant the operator's SIEM endpoint with a pause button beside it. So a confined
 * principal gets {@see AuditStream::scopeOwnedByOrganization()} and nothing else; the
 * environment's authority gets every stream in the environment, whoever owns it.
 */
final class OwnedStreams
{
    /**
     * @return Builder<AuditStream>
     */
    public static function query(ActionContext $context): Builder
    {
        $confinedTo = IntegrationReach::confinedTo($context->principal);

        return $confinedTo === null
            ? AuditStream::query()
            : AuditStream::query()->ownedByOrganization($confinedTo);
    }

    /** @throws ActionRefused */
    public static function find(ActionContext $context): AuditStream
    {
        return self::query($context)->whereKey($context->string('id'))->first()
            ?? throw ActionRefused::notFound('log stream');
    }
}
