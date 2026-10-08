<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Http\Resources\Environment\AppResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Exceptions\InvalidClientMetadata;
use Cbox\Id\OAuthServer\Models\Client;
use Cbox\Id\Organization\ValueObjects\ApiKeyPrefix;

/**
 * The prefix this app's customer API keys carry — `acme_live` makes keys shaped
 * `acme_live_…`. Declaring one is what lets the people who use the app create keys for it;
 * null stops NEW keys being created, and keys already issued keep working until they are
 * revoked.
 *
 * Unique per environment, because a key is routed to its app by its prefix alone. Asked
 * here so the refusal reads as a sentence (`api_key_prefix_taken`); the registry asks
 * again under its unique index, which is the guard. The format is {@see ApiKeyPrefix}'s
 * own test, refused by the registry in its words.
 */
#[AsAction(
    name: 'apps.settings.api_key_prefix',
    summary: 'Set the prefix of an app\'s customer API keys (acme_live), which lets its users create keys for it, or null to stop new keys.',
    scope: 'apps:write',
    danger: Danger::Write,
    schema: 'App',
    tag: 'Applications',
    rest: ['PUT', '/apps/{id}/settings/api-key-prefix'],
    consoleRoutes: ['clients.settings.api-keys', 'environment.clients.settings.api-keys'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class SetAppApiKeyPrefix implements Action
{
    public const string TAKEN = 'Another app in this environment already uses this prefix. Choose another.';

    public function __construct(private ClientRegistry $clients) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            AppFields::id(),
            Field::string('prefix')->nullable()->max(32)->describe('2–16 lowercase letters or digits starting with a letter, then `_live` or `_test`. Null or left out: no new keys.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $client = AppFields::find($context, $context->string('id'));
        $prefix = $context->nullableString('prefix');
        $prefix = $prefix === null ? null : (trim($prefix) ?: null);

        if ($prefix !== null && Client::query()->where('api_key_prefix', $prefix)->whereKeyNot($client->id)->exists()) {
            throw ActionRefused::because('api_key_prefix_taken', self::TAKEN, 'prefix');
        }

        try {
            $updated = $this->clients->update($client, $this->clients->blueprint($client)->withApiKeyPrefix($prefix), $context->actor());
        } catch (InvalidClientMetadata $refused) {
            throw AppFields::invalid($refused, 'prefix');
        }

        return ActionResult::item($updated, AppResource::from($updated));
    }
}
