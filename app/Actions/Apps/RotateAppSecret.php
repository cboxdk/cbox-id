<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Http\Resources\Environment\AppSecretResource;
use App\Http\Resources\Environment\Timestamp;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enums\SecretGrace;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Exceptions\ClientSecretRefused;

/**
 * Mint a new secret for an app and retire its current ones after `grace_seconds` — the
 * overlap in which deployments move to the new secret. 0 retires them at once, which is
 * what a leaked secret needs.
 *
 * `grace_seconds` is REQUIRED, not defaulted: the two safe answers point opposite ways (cut
 * a leak off now, or keep production signing in while it redeploys), and a caller that did
 * not choose would get whichever one somebody guessed for it.
 *
 * The plaintext is in the answer ONCE as `client_secret` — only its hash is stored, and an
 * idempotent replay returns everything but it. Refused for an app with no shared secret: a
 * public app (PKCE), or one that signs assertions with its own keys, where minting one
 * would ADD a bearer credential to an app that never had one.
 */
#[AsAction(
    name: 'apps.secrets.rotate',
    summary: 'Mint a new client secret for an app and retire the current ones after grace_seconds (0 = at once). The new secret is returned once.',
    scope: 'apps:write',
    danger: Danger::Critical,
    schema: 'AppSecret',
    tag: 'Apps',
    rest: ['POST', '/apps/{id}/secrets'],
    status: 201,
    consoleRoutes: ['clients.rotate', 'environment.clients.rotate'],
    consoleGate: ConsoleGate::Administer,
    redact: ['client_secret'],
)]
final readonly class RotateAppSecret implements Action
{
    public function __construct(private ClientRegistry $clients) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            AppFields::id(),
            Field::integer('grace_seconds')->required()->min(0)->max(SecretGrace::ceiling())->describe('How long the current secrets keep working, in seconds. 0 stops them at once.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $client = AppFields::find($context, $context->string('id'));

        try {
            $rotated = $this->clients->rotateSecret($client, (int) $context->string('grace_seconds'), $context->actor());
        } catch (ClientSecretRefused $refused) {
            throw ActionRefused::because($refused->reason->value, $refused->getMessage(), 'grace_seconds');
        }

        return ActionResult::item($rotated, [
            ...AppSecretResource::from($rotated->summary, $rotated->secret),
            'previous_expire_at' => Timestamp::of($rotated->previousExpireAt),
        ]);
    }
}
