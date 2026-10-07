<?php

declare(strict_types=1);

namespace App\Actions\LegacyLogin;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Migration\LegacyLoginApprovals;
use App\Platform\SignInAudit;

/**
 * Put the declared legacy endpoint into the sign-in path: from now on every unknown address,
 * and the password typed with it, is offered to that URL.
 *
 * The one write here that redirects where passwords go, which is why an app declaring it is
 * never enough and somebody holding this environment's authority must approve it. On the
 * console that somebody must also have re-confirmed in the last minutes (asked by the page,
 * before this runs). From the API it is a key carrying `signin:write` — Critical, and on the
 * trail as the key that did it. Re-approving keeps the ORIGINAL moment: when a URL joined
 * the login path is a fact somebody needs during an incident.
 */
#[AsAction(
    name: 'legacy_login.approve',
    summary: 'Approve the declared legacy login endpoint: sign-ins for people not yet migrated, with their passwords, go to that URL.',
    scope: 'signin:write',
    danger: Danger::Critical,
    schema: 'LegacyLogin',
    tag: 'Legacy login',
    rest: ['POST', '/legacy-login/approve'],
    consoleRoutes: ['environment.legacy-login.approve'],
)]
final readonly class ApproveLegacyLogin implements Action
{
    public function __construct(
        private LegacyLoginApprovals $approvals,
        private SignInAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $declaration = $this->approvals->current()
            ?? throw ActionRefused::because('no_declaration', 'No app has declared a legacy login endpoint to approve.');

        if (! $declaration->isApproved()) {
            $this->approvals->approve($context->principal->id());

            $this->audit->record(SignInAudit::LEGACY_LOGIN_APPROVED, $context->actor(), null, 'legacy_login', $declaration->id, [
                'url' => $declaration->url,
                'client_id' => $declaration->client_id,
            ]);
        }

        $fresh = $this->approvals->current();

        return ActionResult::item($fresh, LegacyLoginFields::present($fresh));
    }
}
