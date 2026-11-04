<?php

declare(strict_types=1);

namespace App\Actions\AppCatalog;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\AppCatalog\AppCatalog;

#[AsAction(name: 'app_catalog.delete', summary: 'Remove a catalog app without deleting its OAuth clients or user data.', scope: 'app_catalog:write', danger: Danger::Destructive, rest: ['DELETE', '/app-catalog/{key}'], status: 204, consoleRoutes: ['environment.app-catalog.destroy'], consoleGate: ConsoleGate::EnvironmentAdmin, tag: 'App catalog')]
final readonly class DeleteCatalogApp implements Action
{
    public function __construct(private AppCatalog $catalog) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([Field::string('key')->inPath()->max(64), Field::integer('version')->required()->min(1)]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $this->catalog->delete($context->string('key'), (int) $context->string('version'), $context->actor());

        return ActionResult::none();
    }
}
