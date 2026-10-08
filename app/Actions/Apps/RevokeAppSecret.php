<?php

declare(strict_types=1);

namespace App\Actions\Apps;

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
use Cbox\Id\OAuthServer\Enums\ClientSecretRefusal;
use Cbox\Id\OAuthServer\Exceptions\ClientSecretRefused;
use Cbox\Id\OAuthServer\ValueObjects\ClientSecretSummary;

/**
 * Stop one of an app's secrets working now — a leaked one, or an old one somebody forgot
 * to let run out. Anything still using it fails to sign in, so it is critical.
 *
 * NEVER THE APP'S LAST LIVE SECRET (`last_live_secret`): that is rotated, or the app
 * deleted — revoking it would leave a confidential app with no way to authenticate, which
 * is a deletion that keeps the row. A secret that has already run out or been revoked is
 * `secret_not_live` rather than a 404: it existed, and "it already stopped working" is the
 * answer the caller needs. Asked here so the refusal reads as a sentence; the registry asks
 * again under its own lock, which is the guard, and a concurrent rotation can still change
 * the answer between the two.
 */
#[AsAction(
    name: 'apps.secrets.revoke',
    summary: 'Revoke one of an app\'s client secrets immediately. Never its last live secret — rotate that instead.',
    scope: 'apps:write',
    danger: Danger::Critical,
    tag: 'Applications',
    rest: ['DELETE', '/apps/{id}/secrets/{secret_id}'],
    status: 204,
    consoleRoutes: ['clients.secrets.revoke', 'environment.clients.secrets.revoke'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class RevokeAppSecret implements Action
{
    public const string NOT_LIVE = 'That secret is no longer live — it has already run out or been revoked.';

    public const string LAST_LIVE = 'This is the app\'s only live secret. Rotate it to replace it, or delete the app to switch it off.';

    public function __construct(private ClientRegistry $clients) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            AppFields::id(),
            Field::string('secret_id')->inPath()->max(64)->describe('The secret\'s id, from `GET /apps/{id}/secrets`.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $client = AppFields::find($context, $context->string('id'));
        $secretId = $context->string('secret_id');

        $live = $this->clients->secrets($client);
        $target = array_values(array_filter($live, static fn (ClientSecretSummary $secret): bool => $secret->id === $secretId));

        if ($target === []) {
            throw ActionRefused::because('secret_not_live', self::NOT_LIVE, 'secret_id');
        }

        if (count($live) <= 1) {
            throw ActionRefused::because('last_live_secret', self::LAST_LIVE, 'secret_id');
        }

        try {
            $this->clients->revokeSecret($client, $secretId, $context->actor());
        } catch (ClientSecretRefused $refused) {
            throw match ($refused->reason) {
                ClientSecretRefusal::LastLiveSecret => ActionRefused::because('last_live_secret', self::LAST_LIVE, 'secret_id'),
                ClientSecretRefusal::UnknownSecret => ActionRefused::because('secret_not_live', self::NOT_LIVE, 'secret_id'),
                default => ActionRefused::because($refused->reason->value, $refused->getMessage(), 'secret_id'),
            };
        }

        return ActionResult::none($target[0]);
    }
}
