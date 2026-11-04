<?php

declare(strict_types=1);

namespace App\Actions\UserProperties;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use Cbox\Id\UserProperties\Models\PropertyNamespace;

#[AsAction(
    name: 'user_property_namespaces.show',
    summary: 'Read the app and webhook grants for a user-property namespace.',
    scope: 'user_property_namespaces:read',
    danger: Danger::Read,
    rest: ['GET', '/user-property-namespaces/{name}'],
    consoleGate: ConsoleGate::EnvironmentAdmin,
    tag: 'User properties',
)]
final readonly class ShowNamespace implements Action
{
    public static function input(): InputSchema
    {
        return InputSchema::of([Field::string('name')->inPath()->max(64)]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $namespace = PropertyNamespace::query()->where('name', $context->string('name'))->first() ?? throw ActionRefused::notFound('namespace');

        return ActionResult::item($namespace, NamespaceFields::present($namespace));
    }
}
