<?php

declare(strict_types=1);

namespace App\Actions\Apis;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Apis\ApiAdministration;
use Cbox\Id\OAuthServer\Contracts\Apis;

/**
 * Delete an API and its scopes. Tokens already minted for it keep their `aud` until they
 * expire; apps holding its scope keys keep them as plain scopes.
 */
#[AsAction(
    name: 'apis.delete',
    summary: 'Delete an API and its scopes. Existing tokens keep their audience until they expire.',
    scope: 'apis:write',
    danger: Danger::Destructive,
    rest: ['DELETE', '/apis/{id}'],
    status: 204,
    consoleRoutes: ['environment.apis.destroy'],
)]
final readonly class DeleteApi implements Action
{
    public function __construct(
        private Apis $apis,
        private ApiAdministration $admin,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The API id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $api = $this->apis->find($context->string('id')) ?? throw ActionRefused::notFound('API');

        $this->admin->delete($api, $context->actor());

        return ActionResult::none($api);
    }
}
