<?php

declare(strict_types=1);

namespace App\Actions\Hooks;

use App\Http\Resources\Environment\InlineHookResource;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Integrations\IntegrationAudit;
use Cbox\Id\ExternalActions\Contracts\ExternalActions;
use Cbox\Id\ExternalActions\Enums\ActionEndpointStatus;

/**
 * Pause or activate an inline hook, by the state it should END in.
 *
 * The console's button is a toggle — the record knows which of two states it is in — but
 * a toggle is the wrong verb for a machine: a retried "toggle" undoes itself. `active` says
 * the outcome, so sending it twice is the same as sending it once, and the console sends
 * the opposite of what it shows.
 *
 * CRITICAL either way: activating a hook puts an endpoint that may refuse sign-ins back in
 * the sign-in path, and pausing one can switch off a check somebody relies on. Asked for a
 * state the hook is already in, it changes nothing and records nothing.
 */
#[AsAction(
    name: 'hooks.update',
    summary: 'Pause (active: false) or activate (active: true) an inline hook.',
    scope: 'hooks:write',
    danger: Danger::Critical,
    schema: 'InlineHook',
    tag: 'Inline hooks',
    rest: ['PATCH', '/hooks/{id}'],
    consoleRoutes: ['hooks.toggle', 'environment.hooks.toggle'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class UpdateHook implements Action
{
    public function __construct(
        private ExternalActions $hooks,
        private IntegrationAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The inline hook id.'),
            Field::boolean('active')->required()->describe('True to call it at its hook point; false to pause it.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $endpoint = HookEndpoints::manageable($context);
        $active = $context->boolean('active');

        if ($active !== ($endpoint->status === ActionEndpointStatus::Active)) {
            // In the hook's OWN scope, which is what the contract matches on — the one call
            // that resolves for an environment administrator and an organization's alike.
            $active
                ? $this->hooks->activate($endpoint->id, $endpoint->organization_id)
                : $this->hooks->pause($endpoint->id, $endpoint->organization_id);

            $endpoint->refresh();

            $this->audit->record($active ? IntegrationAudit::HOOK_ACTIVATED : IntegrationAudit::HOOK_PAUSED, 'inline_hook', $endpoint->id, $endpoint->organization_id, $context->actor(), [
                'hook_point' => $endpoint->hook_point->value,
                'url' => $endpoint->url,
            ]);
        }

        return ActionResult::item($endpoint, InlineHookResource::from($endpoint));
    }
}
