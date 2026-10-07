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
use Cbox\Id\OAuthServer\Models\Api;
use Cbox\Id\OAuthServer\Models\ApiScope;

/**
 * Rename an API, (re)link the app whose roles it enforces, and — when `scopes` is sent —
 * make its scope set exactly that: named scopes added or changed, the rest removed. All or
 * nothing: the runner's transaction covers the rename and every scope, so a refused scope
 * leaves neither the rename nor an audit line claiming it.
 */
#[AsAction(
    name: 'apis.update',
    summary: 'Rename an API, link or unlink its app, and optionally replace its complete scope set.',
    scope: 'apis:write',
    danger: Danger::Write,
    rest: ['PATCH', '/apis/{id}'],
    consoleRoutes: ['environment.apis.update'],
)]
final readonly class UpdateApi implements Action
{
    public function __construct(
        private Apis $apis,
        private ApiAdministration $admin,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The API id.'),
            Field::string('name')->max(120),
            Field::string('client_id')->nullable()->max(255)->describe('The app to link; null unlinks it. Left out, the link is unchanged.'),
            ApiScopeFields::field()->describe('The COMPLETE scope set afterwards. Left out, scopes are unchanged.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $api = $this->apis->find($context->string('id')) ?? throw ActionRefused::notFound('API');

        $clientId = $context->has('client_id') ? $context->nullableString('client_id') : $api->client_id;

        if ($clientId !== $api->client_id) {
            $refusal = ApiLinks::refusal($clientId, $api->organization_id);

            if ($refusal !== null) {
                throw ActionRefused::because('invalid_api', $refusal, 'client_id');
            }
        }

        try {
            $this->admin->update(
                $api,
                $context->has('name') ? trim($context->string('name')) : $api->name,
                $clientId,
                $context->actor(),
            );

            if ($context->has('scopes')) {
                $keep = [];

                foreach (ApiScopeFields::definitions($context->array('scopes')) as $scope) {
                    $this->admin->defineScope($api, $scope, $context->actor());
                    $keep[] = $scope->key;
                }

                foreach (array_diff($this->scopeKeys($api), $keep) as $gone) {
                    $this->admin->removeScope($api, $gone, $context->actor());
                }
            }
        } catch (InvalidApiDefinition $refused) {
            throw ActionRefused::because('invalid_api', $refused->getMessage(), 'name');
        }

        $fresh = $this->apis->find($api->id) ?? $api;

        return ActionResult::item($fresh, ApiResource::from($fresh));
    }

    /**
     * @return list<string>
     */
    private function scopeKeys(Api $api): array
    {
        return array_values(ApiScope::query()
            ->where('api_id', $api->id)
            ->orderBy('key')
            ->get(['key'])
            ->map(static fn (ApiScope $scope): string => $scope->key)
            ->all());
    }
}
