<?php

declare(strict_types=1);

namespace App\Actions\Apis;

use App\Http\Resources\Environment\ApiResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Apis\ApiAdministration;
use App\Platform\Apis\ApiLinks;
use Cbox\Id\OAuthServer\Contracts\Apis;
use Cbox\Id\OAuthServer\Exceptions\InvalidApiDefinition;
use Cbox\Id\OAuthServer\ValueObjects\NewApi;
use Cbox\Id\Organization\Models\Organization;

/**
 * Register an API. Registering one is the ENVIRONMENT's act: its identifier becomes the
 * token's `aud` and its scope keys are unique across the environment, so no tenant surface
 * may — the console offers this on the environment console only, the API to a management
 * key. `organization_id` makes it one organization's.
 */
#[AsAction(
    name: 'apis.create',
    summary: 'Register an API (resource server): its identifier becomes the access token audience, and it owns its scopes.',
    scope: 'apis:write',
    danger: Danger::Write,
    rest: ['POST', '/apis'],
    status: 201,
    consoleRoutes: ['environment.apis.store'],
)]
final readonly class CreateApi implements Action
{
    public function __construct(
        private Apis $apis,
        private ApiAdministration $admin,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('identifier')->required()->max(255)->format('uri')->describe('An absolute URI (RFC 8707), for example https://api.example.com. Becomes the token `aud`.'),
            Field::string('name')->required()->max(120),
            Field::string('organization_id')->nullable()->max(64)->describe('Make it one organization\'s API. Left out, the environment owns it.'),
            Field::string('client_id')->nullable()->max(255)->describe('The app whose roles and permissions the API enforces. Must have the API\'s owner.'),
            ApiScopeFields::field(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = $context->nullableString('organization_id');
        $clientId = $context->nullableString('client_id');
        $identifier = $context->string('identifier');

        if ($organizationId !== null && Organization::query()->whereKey($organizationId)->doesntExist()) {
            throw ActionRefused::because('organization_not_found', 'No organization with that organization_id exists in this environment.', 'organization_id');
        }

        if ($this->apis->identifiedBy($identifier) !== null) {
            throw ActionRefused::because('invalid_api', 'An API with this identifier is already registered in this environment.', 'identifier');
        }

        $refusal = ApiLinks::refusal($clientId, $organizationId);

        if ($refusal !== null) {
            throw ActionRefused::because('invalid_api', $refusal, 'client_id');
        }

        try {
            $api = $this->admin->register(new NewApi(
                identifier: $identifier,
                name: trim($context->string('name')),
                organizationId: $organizationId,
                clientId: $clientId,
                scopes: ApiScopeFields::definitions($context->array('scopes')),
            ), $context->actor());
        } catch (InvalidApiDefinition $refused) {
            throw ActionRefused::because('invalid_api', $refused->getMessage(), 'identifier');
        }

        return ActionResult::item($api, ApiResource::from($api));
    }
}
