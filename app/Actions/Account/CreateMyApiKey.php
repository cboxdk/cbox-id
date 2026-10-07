<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Actions\Workspace\Keys\EnvironmentKeyIssuer;
use App\Http\Resources\Environment\Timestamp;
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
use App\Platform\ApiKeys\KeyApps;
use App\Platform\ApiKeys\ValueObjects\RefusalExplanation;
use Cbox\Id\Organization\Exceptions\CustomerApiKeyRefused;
use Cbox\Id\Organization\Models\CustomerApiKey;

/**
 * Mint an API key of your own, for one of the apps built on this environment — the key the
 * SDKs send people to the console for. It acts as YOU in that app, inside one organization
 * you belong to, and can never carry a permission you do not hold there yourself.
 *
 * CRITICAL: it hands out a credential. The value is returned once, as `token`, and never
 * kept for an idempotent replay. Every refusal the framework can give — an app that offers
 * no keys, an organization you are not an active member of, a permission you do not hold —
 * is the same sentence the console shows, on the field it is about.
 */
#[AsAction(
    name: 'account.api_keys.create',
    summary: 'Create an API key of your own for one of this environment\'s apps, in an organization you belong to. The value is returned once, as `token`.',
    scope: 'account:api_keys:write',
    danger: Danger::Critical,
    plane: ActionPlane::Account,
    rest: ['POST', '/organizations/{organization_id}/api-keys'],
    status: 201,
    consoleRoutes: ['account.api-keys.store'],
    consoleGate: ConsoleGate::Person,
    schema: 'PersonalApiKey',
    tag: 'API keys',
    redact: ['token'],
)]
final readonly class CreateMyApiKey implements Action
{
    public function __construct(
        private HolderApiKeys $holder,
        private KeyApps $apps,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->inPath(),
            Field::string('client_id')->required()->max(255)->describe('The app this key is for.'),
            Field::string('name')->required()->max(120),
            Field::list('permissions', Field::string('permission')->min(1)->max(255))->distinct()->max(200)->describe('What the key may do in the app — each one a permission you hold there. Empty: it only identifies you.'),
            Field::string('expires_at')->nullable()->format('date-time')->describe('When it stops working. Left out, it does not expire.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = $context->string('organization_id');
        $clientId = trim($context->string('client_id'));

        try {
            $issued = $this->holder->issue(
                userId: AsPerson::subjectId($context->principal),
                organizationId: $organizationId,
                clientId: $clientId,
                permissions: array_values(array_filter($context->array('permissions'), 'is_string')),
                name: trim($context->string('name')),
                expiresAt: EnvironmentKeyIssuer::expiry($context, 'expires_at'),
            );
        } catch (CustomerApiKeyRefused $refused) {
            $explanation = RefusalExplanation::of($refused, $this->apps->offered($organizationId, $clientId)?->name);

            throw ActionRefused::because($refused->reason->value, $explanation->message, $explanation->field === 'expiresOn' ? 'expires_at' : $explanation->field);
        }

        return ActionResult::item($issued, [...self::present($issued->key), 'token' => $issued->plaintext]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(CustomerApiKey $key): array
    {
        return [
            'id' => $key->id,
            'name' => $key->name,
            'organization_id' => $key->organization_id,
            'client_id' => $key->client_id,
            'prefix' => $key->prefix,
            'permissions' => $key->permissions,
            'expires_at' => Timestamp::of($key->expires_at),
            'created_at' => Timestamp::of($key->created_at),
        ];
    }
}
