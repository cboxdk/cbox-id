<?php

declare(strict_types=1);

namespace App\Actions\Provisioning;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseAudit;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Provisioning\Contracts\ProvisioningConnections;
use Cbox\Id\Provisioning\Enums\ConnectionStatus;

/**
 * Pause pushing people to a target, or resume. Paused, changes stop going downstream;
 * resumed, they are pushed again from now on. The state is said (`active`) rather than
 * toggled, so a retry cannot undo itself.
 *
 * Pausing is the contract's own operation; there is no `resume()`, because coming back is
 * the absence of a pause — so it is the status written on the scoped model, as the console
 * always wrote it.
 */
#[AsAction(
    name: 'provisioning.targets.status.set',
    summary: 'Pause or resume pushing people to a downstream SCIM target.',
    scope: 'provisioning:write',
    danger: Danger::Write,
    schema: 'ProvisioningTarget',
    tag: 'Outbound provisioning',
    rest: ['POST', '/provisioning-targets/{id}/status'],
    consoleRoutes: ['provisioning.toggle', 'environment.provisioning.toggle'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class SetProvisioningTargetStatus implements Action
{
    public function __construct(
        private ProvisioningConnections $connections,
        private EnterpriseAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The target\'s id.'),
            Field::boolean('active')->required()->describe('true to resume pushing, false to pause.'),
            EnterpriseReach::narrowField(),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $target = ProvisioningFields::target($context);
        $active = $context->boolean('active');

        if (($target->status === ConnectionStatus::Active) !== $active) {
            if ($active) {
                $target->status = ConnectionStatus::Active;
                $target->save();
            } else {
                $this->connections->pause($target->id);
                $target->refresh();
            }

            $this->audit->record($active ? EnterpriseAudit::PROVISIONING_RESUMED : EnterpriseAudit::PROVISIONING_PAUSED, $context->actor(), $target->organization_id, 'provisioning_connection', $target->id, [
                'name' => $target->name,
            ]);
        }

        return ActionResult::item($target, ProvisioningFields::present($target));
    }
}
