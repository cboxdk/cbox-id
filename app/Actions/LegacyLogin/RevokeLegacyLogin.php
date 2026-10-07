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
 * Take the legacy endpoint back out of the sign-in path, keeping the declaration on file.
 *
 * Critical in the other direction: people already migrated are unaffected, and everybody
 * still on the old system can no longer sign in — an outage somebody would choose the
 * timing of. The declaration stays so whoever revoked it can still see what they revoked.
 */
#[AsAction(
    name: 'legacy_login.revoke',
    summary: 'Withdraw the legacy login approval. People not yet migrated can no longer sign in; the declaration stays on file.',
    scope: 'signin:write',
    danger: Danger::Critical,
    schema: 'LegacyLogin',
    tag: 'Legacy login',
    rest: ['POST', '/legacy-login/revoke'],
    consoleRoutes: ['environment.legacy-login.revoke'],
)]
final readonly class RevokeLegacyLogin implements Action
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
            ?? throw ActionRefused::because('no_declaration', 'No app has declared a legacy login endpoint.');

        if ($declaration->isApproved()) {
            $this->approvals->revoke();

            $this->audit->record(SignInAudit::LEGACY_LOGIN_REVOKED, $context->actor(), null, 'legacy_login', $declaration->id, [
                'url' => $declaration->url,
                'client_id' => $declaration->client_id,
            ]);
        }

        $fresh = $this->approvals->current();

        return ActionResult::item($fresh, LegacyLoginFields::present($fresh));
    }
}
