<?php

declare(strict_types=1);

namespace App\Actions\Hooks;

use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Integrations\IntegrationReach;
use Cbox\Id\ExternalActions\Contracts\ExternalActions;
use Cbox\Id\ExternalActions\Models\ExternalActionEndpoint;

/**
 * The hook an action names, resolved within what its principal may see — shared
 * by every hook action, and not one itself.
 *
 * {@see ExternalActions} answers a mismatched owner with a silent no-op, which is right
 * for a contract (the caller was not entitled to learn the hook exists) and wrong for a
 * door: a removal that did nothing must not answer 204. So the refusal is explicit here,
 * and the contract's no-op stays the backstop it was written to be.
 */
final class HookEndpoints
{
    /** @throws ActionRefused */
    public static function visible(ActionContext $context): ExternalActionEndpoint
    {
        $endpoint = IntegrationReach::visible(ExternalActionEndpoint::query(), IntegrationReach::confinedTo($context->principal))
            ->whereKey($context->string('id'))
            ->first();

        return $endpoint ?? throw ActionRefused::notFound('hook');
    }

    /**
     * The hook, refused unless this principal may CHANGE it: an organization's
     * administrator sees the environment's own hooks — they fire on their sign-ins — and
     * may not touch one.
     *
     * @throws ActionRefused
     */
    public static function manageable(ActionContext $context): ExternalActionEndpoint
    {
        $endpoint = self::visible($context);

        IntegrationReach::assertManageable($endpoint->organization_id, IntegrationReach::confinedTo($context->principal), 'this hook');

        return $endpoint;
    }
}
