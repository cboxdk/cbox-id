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
use Cbox\Id\OAuthServer\Contracts\Apis;
use Cbox\Id\OAuthServer\Enums\ProtocolScope;
use Cbox\Id\OAuthServer\Exceptions\InvalidApiDefinition;
use Cbox\Id\OAuthServer\Models\ApiScope;
use Cbox\Id\OAuthServer\ValueObjects\ApiScopeDefinition;

/**
 * Add one scope to an API, or change one it already owns.
 *
 * A key belongs to one API in the environment — a token request names a scope by key
 * alone — and the sign-in scopes (`openid`, `email`…) belong to Cbox ID itself. "Organizations'
 * apps may request this" means something only on an API the environment owns; on an
 * organization's own API only that organization's apps may hold its scopes, so the flag is
 * stored as true there whatever was sent.
 */
#[AsAction(
    name: 'apis.scopes.define',
    summary: 'Add a scope to an API, or change the description or tenant access of one it owns.',
    scope: 'apis:write',
    danger: Danger::Write,
    rest: ['PUT', '/apis/{id}/scopes/{key}'],
    consoleRoutes: ['environment.apis.scopes.store', 'environment.apis.scopes.update'],
)]
final readonly class DefineApiScope implements Action
{
    public function __construct(
        private Apis $apis,
        private ApiAdministration $admin,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The API id.'),
            Field::string('key')->inPath()->max(128)->describe('The scope key: `tax:assess`.'),
            Field::string('description')->nullable()->max(255),
            Field::boolean('tenant_requestable')->describe('Whether organizations\' own apps may request it. Default true.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $api = $this->apis->find($context->string('id')) ?? throw ActionRefused::notFound('API');
        $key = trim($context->string('key'));

        if (ProtocolScope::isProtocol($key)) {
            throw ActionRefused::because('invalid_api', "\"{$key}\" is a sign-in scope Cbox ID defines itself, so no API can own it.", 'key');
        }

        $owner = ApiScope::query()->where('key', $key)->first();

        if ($owner !== null && $owner->api_id !== $api->id) {
            throw ActionRefused::because('invalid_api', "\"{$key}\" already belongs to another API in this environment. A token request names a scope by its key alone, so each key can belong to one API.", 'key');
        }

        try {
            $this->admin->defineScope($api, new ApiScopeDefinition(
                $key,
                $context->nullableString('description'),
                $api->organization_id !== null || $context->boolean('tenant_requestable', true),
            ), $context->actor());
        } catch (InvalidApiDefinition $refused) {
            throw ActionRefused::because('invalid_api', $refused->getMessage(), 'key');
        }

        $fresh = $this->apis->find($api->id) ?? $api;

        return ActionResult::item($fresh, ApiResource::from($fresh));
    }
}
