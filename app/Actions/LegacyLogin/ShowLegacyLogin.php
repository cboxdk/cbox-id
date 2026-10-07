<?php

declare(strict_types=1);

namespace App\Actions\LegacyLogin;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Migration\LegacyLoginApprovals;

/**
 * The legacy sign-in endpoint an app has declared, if any, and whether it is approved —
 * which app asked, what URL, and since when people's passwords have been sent there.
 */
#[AsAction(
    name: 'legacy_login.get',
    summary: 'Read the legacy login endpoint an app declared for migration, and whether it is approved to receive sign-ins.',
    scope: 'signin:read',
    danger: Danger::Read,
    schema: 'LegacyLogin',
    tag: 'Legacy login',
    rest: ['GET', '/legacy-login'],
)]
final readonly class ShowLegacyLogin implements Action
{
    public function __construct(private LegacyLoginApprovals $approvals) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $declaration = $this->approvals->current();

        return ActionResult::item($declaration, LegacyLoginFields::present($declaration));
    }
}
