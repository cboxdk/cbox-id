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
use Cbox\Id\OAuthServer\Models\ApiScope;

/**
 * Remove one scope from an API. Apps that held it keep it as a plain scope, which no longer
 * reaches this API.
 */
#[AsAction(
    name: 'apis.scopes.remove',
    summary: 'Remove a scope from an API. Apps holding it keep it as a plain scope that no longer reaches the API.',
    scope: 'apis:write',
    danger: Danger::Destructive,
    rest: ['DELETE', '/apis/{id}/scopes/{key}'],
    status: 204,
    consoleRoutes: ['environment.apis.scopes.destroy'],
)]
final readonly class RemoveApiScope implements Action
{
    public function __construct(
        private Apis $apis,
        private ApiAdministration $admin,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The API id.'),
            Field::string('key')->inPath()->max(128)->describe('The scope key.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $api = $this->apis->find($context->string('id')) ?? throw ActionRefused::notFound('API');
        $key = $context->string('key');

        // Bound to this API in the query: a key another API owns is not this one's to remove.
        if (ApiScope::query()->where('api_id', $api->id)->where('key', $key)->doesntExist()) {
            throw ActionRefused::notFound('scope');
        }

        $this->admin->removeScope($api, $key, $context->actor());

        return ActionResult::none($api);
    }
}
