<?php

declare(strict_types=1);

namespace App\Actions\Roles;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\FrameworkMessage;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\AccessControl\Contracts\RoleDefinitions;
use Cbox\Id\AccessControl\Exceptions\RoleDefinitionChanged;
use Illuminate\Auth\Access\AuthorizationException;

#[AsAction(
    name: 'roles.definition.activate',
    summary: 'Activate a reviewed application role definition for its existing holders.',
    scope: 'role_definitions:write',
    danger: Danger::Destructive,
    schema: 'Role',
    tag: 'Roles',
    rest: ['POST', '/roles/{id}/definition/activate'],
    consoleRoutes: ['roles.definition.activate', 'environment.roles.definition.activate'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
)]
final readonly class ActivateRoleDefinition implements Action
{
    public function __construct(private RoleDefinitions $definitions) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->required()->max(64),
            Field::string('version')->required()->min(64)->max(64),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        if ($context->principal->confinedToOrganization() !== null) {
            throw new AuthorizationException('Only an environment administrator may activate an application role definition.');
        }

        RoleFields::find($context->string('id'));

        try {
            $role = $this->definitions->activate($context->string('id'), $context->string('version'), $context->actor());
        } catch (RoleDefinitionChanged $error) {
            throw new ActionRefused('role_definition_changed', FrameworkMessage::from($error), 409, 'version');
        }

        return ActionResult::item($role, RoleFields::present($role));
    }
}
