<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\Paginates;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;

/**
 * Every pending agent request in this environment — agents asking to act as one of its
 * people (OIDC CIBA) — a page at a time: an agent platform raises these continuously, and
 * a runaway client must not make the list unreadable.
 *
 * What an administrator does with one is {@see DenyAgentRequest}. Approving is not on
 * offer here or anywhere else an administrator stands: CIBA approval IS the person's
 * consent, so only the person can give it.
 */
#[AsAction(
    name: 'approvals.list',
    summary: 'List the pending requests from agents to act as one of this environment\'s people (CIBA): which app, for whom, and what it asks.',
    scope: 'approvals:read',
    danger: Danger::Read,
    schema: 'AgentRequest',
    tag: 'Agent requests',
    rest: ['GET', '/agent-requests'],
)]
final readonly class ListAgentRequests implements Action
{
    use Paginates;

    public static function input(): InputSchema
    {
        return InputSchema::of(self::pageFields());
    }

    public function handle(ActionContext $context): ActionResult
    {
        return $this->page(
            AgentRequestFields::pending(),
            $context,
            static fn (BackchannelAuthRequest $request): array => AgentRequestFields::present($request),
        );
    }
}
