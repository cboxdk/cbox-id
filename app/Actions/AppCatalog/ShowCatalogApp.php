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

#[AsAction(name: 'app_catalog.show', summary: 'Read a catalog app and its publication settings.', scope: 'app_catalog:read', danger: Danger::Read, rest: ['GET', '/app-catalog/{key}'], consoleGate: ConsoleGate::EnvironmentAdmin, tag: 'App catalog')]
final readonly class ShowCatalogApp implements Action
{
    public function __construct(private AppCatalog $catalog) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([Field::string('key')->inPath()->max(64)]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $entry = $this->catalog->find($context->string('key'));

        return ActionResult::item($entry, $this->catalog->present($entry, true));
    }
}
