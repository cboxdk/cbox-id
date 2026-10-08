<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;

/**
 * The app's configuration without its identity or credentials: no client id, no secret, no
 * key set, no owning organization. The payload is the blueprint document itself, exactly
 * as `apps.create` takes it back — which is how an app is promoted from staging to
 * production.
 */
#[AsAction(
    name: 'apps.blueprint',
    summary: 'Export an app\'s configuration as a blueprint — no client id, secret, key set or owner — to register the same app in another environment.',
    scope: 'apps:read',
    danger: Danger::Read,
    schema: 'AppBlueprint',
    tag: 'Applications',
    rest: ['GET', '/apps/{id}/blueprint'],
)]
final readonly class ExportAppBlueprint implements Action
{
    public function __construct(private ClientRegistry $clients) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([AppFields::id()]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $client = AppFields::find($context, $context->string('id'));
        $blueprint = $this->clients->blueprint($client);

        return ActionResult::item($blueprint, $blueprint->toArray());
    }
}
