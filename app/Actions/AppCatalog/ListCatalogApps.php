<?php

declare(strict_types=1);

namespace App\Actions\AppCatalog;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\AppCatalog\AppCatalog;
use App\Platform\AppCatalog\CatalogEntry;

#[AsAction(name: 'app_catalog.list', summary: 'List catalog apps, including drafts and visibility settings.', scope: 'app_catalog:read', danger: Danger::Read, rest: ['GET', '/app-catalog'], consoleGate: ConsoleGate::EnvironmentAdmin, tag: 'App catalog')]
final readonly class ListCatalogApps implements Action
{
    public function __construct(private AppCatalog $catalog) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $entries = CatalogEntry::query()->orderBy('position')->orderBy('key')->get();

        return ActionResult::items($entries, array_values($entries->map(fn (CatalogEntry $entry): array => $this->catalog->present($entry, true))->all()));
    }
}
