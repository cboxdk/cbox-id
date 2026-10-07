<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Http\Resources\Environment\AppSecretResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\ValueObjects\ClientSecretSummary;

/**
 * An app's live secrets, newest first: ids, the characters each ends in, and dates — never
 * a secret. The id is what `apps.secrets.revoke` names, and `expires_at` marks one a
 * rotation replaced, still working through its overlap.
 *
 * Every live secret is listed: an app holds a handful at most (the current one, and those
 * still inside a rotation's grace period), so there is no page to turn.
 */
#[AsAction(
    name: 'apps.secrets.list',
    summary: 'List an app\'s live client secrets — ids, last characters and dates, never the secrets themselves.',
    scope: 'apps:read',
    danger: Danger::Read,
    schema: 'AppSecret',
    tag: 'Apps',
    rest: ['GET', '/apps/{id}/secrets'],
)]
final readonly class ListAppSecrets implements Action
{
    public function __construct(private ClientRegistry $clients) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([AppFields::id()]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $client = AppFields::find($context, $context->string('id'));
        $secrets = $this->clients->secrets($client);

        return ActionResult::page(
            $secrets,
            array_map(static fn (ClientSecretSummary $secret): array => AppSecretResource::from($secret), $secrets),
            ['limit' => count($secrets), 'has_more' => false, 'next_cursor' => null],
        );
    }
}
