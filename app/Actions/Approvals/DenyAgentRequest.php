<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;

/**
 * Deny a pending agent request (OIDC CIBA) on behalf of the person it was raised for — the
 * administrator's lever against an agent asking to act as somebody it should not.
 *
 * WHY AN ADMINISTRATOR MAY DENY BUT NEVER APPROVE. Denial grants nothing: the agent's poll
 * ends in `access_denied` and no token is minted, so doing it for the person is a
 * fail-closed act, not consent given on their behalf. Approval is the opposite — the
 * redeemed token is minted FOR that person — so it stays the person's own ceremony, on
 * their own device or their own Approvals page, and has no action.
 *
 * Only a request still pending and unexpired in THIS environment can be denied; any other
 * id — decided, lapsed, or another plane's — is not found. Asking again for one already
 * denied is therefore a 404, not a second denial.
 */
#[AsAction(
    name: 'approvals.deny',
    summary: 'Deny a pending approval request (CIBA) for the person it was raised for: the agent gets access_denied and no token.',
    scope: 'approvals:write',
    danger: Danger::Destructive,
    tag: 'Approvals',
    rest: ['POST', '/agent-requests/{request_id}/deny'],
    status: 204,
    consoleRoutes: ['environment.approvals.deny'],
)]
final readonly class DenyAgentRequest implements Action
{
    public function __construct(private BackchannelAuthentication $backchannel) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('request_id')->inPath(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $request = AgentRequestFields::pending()->whereKey($context->string('request_id'))->first()
            ?? throw ActionRefused::notFound('approval request');

        // As the request's own subject: the service binds a decision to the person the
        // request names, and a denial is the one decision that is safe to take for them.
        $this->backchannel->deny($request->id, $request->user_id);

        return ActionResult::none($request);
    }
}
