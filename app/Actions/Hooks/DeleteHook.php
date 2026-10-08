<?php

declare(strict_types=1);

namespace App\Actions\Hooks;

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

/**
 * Remove a hook: it is no longer called at its hook point. Undoing it means
 * registering a new one, with a new secret.
 */
#[AsAction(
    name: 'hooks.delete',
    summary: 'Remove a hook. It is no longer called at its hook point.',
    scope: 'hooks:write',
    danger: Danger::Destructive,
    tag: 'Hooks',
    rest: ['DELETE', '/hooks/{id}'],
    status: 204,
    consoleRoutes: ['hooks.destroy', 'environment.hooks.destroy'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class DeleteHook implements Action
{
    public function __construct(
        private ExternalActions $hooks,
        private IntegrationAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The hook id.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $endpoint = HookEndpoints::manageable($context);

        $this->hooks->remove($endpoint->id, $endpoint->organization_id);

        $this->audit->record(IntegrationAudit::HOOK_DELETED, 'inline_hook', $endpoint->id, $endpoint->organization_id, $context->actor(), [
            'hook_point' => $endpoint->hook_point->value,
            'url' => $endpoint->url,
        ]);

        return ActionResult::none($endpoint);
    }
}
