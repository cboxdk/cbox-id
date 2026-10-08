<?php

declare(strict_types=1);

namespace App\Actions\CustomerApiKeys;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Actions\OrganizationTarget;
use App\Platform\ApiKeys\BoundApiKeys;
use Cbox\Id\Organization\Contracts\CustomerApiKeys;
use Cbox\Id\Organization\ValueObjects\ApiKeyActor;

/**
 * Revoke an API key one of an organization's people holds — through the framework's
 * {@see CustomerApiKeys}, which records `api_key.revoked` with whoever asked as the actor.
 * Whatever was using it stops working at once.
 *
 * A key from another environment does not resolve here at all. Naming `organization_id`
 * binds the lookup to that organization as well — what the console does, so a key id pasted
 * under the wrong organization's page revokes nothing. Idempotent: a key already revoked
 * answers 204 too.
 */
#[AsAction(
    name: 'api_keys.revoke',
    summary: 'Revoke an API key an organization\'s member created for your app. Whatever uses it stops working at once.',
    scope: 'api_keys:write',
    danger: Danger::Destructive,
    tag: 'API keys',
    rest: ['DELETE', '/api-keys/{id}'],
    status: 204,
    consoleRoutes: ['environment.organizations.api-keys.revoke'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class RevokeCustomerApiKey implements Action
{
    public function __construct(
        private CustomerApiKeys $keys,
        private BoundApiKeys $bound,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->max(64)->describe('The API key id.'),
            Field::string('organization_id')->nullable()->max(64)->describe('The organization it must belong to; a key elsewhere is not found.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = OrganizationTarget::check($context, $context->nullableString('organization_id'));
        $id = $context->string('id');

        $key = $organizationId === null ? $this->keys->find($id) : $this->bound->find($organizationId, $id);

        if ($key === null) {
            throw ActionRefused::notFound('API key');
        }

        $actor = $context->actor();

        $this->keys->revoke($key->id, new ApiKeyActor($actor->type, $actor->id));

        return ActionResult::none($key);
    }
}
