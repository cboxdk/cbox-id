<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\ApiKeys\HolderApiKeys;

/**
 * Revoke one of your own API keys. Looked up with you AND the organization in the query, so
 * a key id from somebody else's list revokes nothing and is not found — and neither is one
 * already revoked, so asking twice is no second act.
 */
#[AsAction(
    name: 'account.api_keys.revoke',
    summary: 'Revoke one of your own API keys.',
    scope: 'account:api_keys:write',
    danger: Danger::Destructive,
    plane: ActionPlane::Account,
    rest: ['DELETE', '/organizations/{organization_id}/api-keys/{key_id}'],
    status: 204,
    consoleRoutes: ['account.api-keys.revoke'],
    consoleGate: ConsoleGate::Person,
    tag: 'API keys',
)]
final readonly class RevokeMyApiKey implements Action
{
    public function __construct(private HolderApiKeys $holder) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath(),
            Field::string('key_id')->inPath(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        if (! $this->holder->revoke($context->string('organization_id'), AsPerson::subjectId($context->principal), $context->string('key_id'))) {
            throw ActionRefused::notFound('API key');
        }

        return ActionResult::none();
    }
}
