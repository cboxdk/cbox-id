<?php

declare(strict_types=1);

namespace App\Actions\Workspace\Keys;

use App\Actions\Workspace\InWorkspace;
use App\Http\Resources\Workspace\KeyResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;

/**
 * Mint a management key (`cbid_env_…`) for one of the workspace's environments — the
 * workspace console's Keys page, for whichever environment is chosen.
 *
 * HIGH PRIVILEGE: the key provisions organizations and people in that environment. So it
 * takes the environments capability, and an environment the caller cannot reach is not
 * found ({@see InWorkspace::environment()}): a person reaches the environments their
 * membership grants, a key every one its workspace owns. The value is in `token`, once.
 */
#[AsAction(
    name: 'keys.environment.create',
    summary: 'Mint a management key for one of the workspace\'s environments, with the scopes it needs. The value is returned once, as `token`.',
    scope: 'keys:write',
    danger: Danger::Critical,
    plane: ActionPlane::Workspace,
    rest: ['POST', '/environments/{environment_id}/keys'],
    status: 201,
    consoleRoutes: ['keys.store'],
    consoleGate: ConsoleGate::ManageEnvironments,
    schema: 'EnvironmentKey',
    tag: 'Keys',
    redact: ['token'],
)]
final readonly class CreateEnvironmentKey implements Action
{
    public function __construct(private EnvironmentKeyIssuer $keys) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('environment_id')->inPath(),
            Field::string('name')->required()->max(120),
            Field::list('scopes', Field::string('scope')->max(64))->required()->min(1)->max(64)->describe('The environment-key scopes it carries, for example `apis:write`.'),
            Field::string('expires_at')->nullable()->format('date-time')->describe('When it stops working. Left out, it does not expire.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $workspaceId = InWorkspace::id($context->principal);
        $environment = InWorkspace::environment($context->principal, $workspaceId, $context->string('environment_id'));

        $issued = $this->keys->issue(
            $context->principal,
            $workspaceId,
            $environment,
            trim($context->string('name')),
            array_values(array_filter($context->array('scopes'), 'is_string')),
            EnvironmentKeyIssuer::expiry($context, 'expires_at'),
        );

        return ActionResult::item($issued, KeyResource::environment($issued->key, $issued->plaintext));
    }
}
