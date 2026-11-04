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
use App\Platform\AppCatalog\CatalogEntryData;

#[AsAction(name: 'app_catalog.save', summary: 'Create or replace a catalog app, its icon and visibility. Version zero creates; a stale version conflicts.', scope: 'app_catalog:write', danger: Danger::Write, rest: ['PUT', '/app-catalog/{key}'], consoleRoutes: ['environment.app-catalog.save'], consoleGate: ConsoleGate::EnvironmentAdmin, tag: 'App catalog')]
final readonly class SaveCatalogApp implements Action
{
    public function __construct(private AppCatalog $catalog) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('key')->inPath()->max(64),
            Field::integer('version')->required()->min(0),
            Field::string('name')->required()->max(120),
            Field::string('description')->nullable()->max(300),
            Field::string('launch_url')->required()->max(2048)->describe('Explicit HTTPS entry point. Never inferred from OAuth redirects.'),
            Field::boolean('enabled')->required(),
            Field::integer('position')->required()->min(0)->max(10000),
            Field::string('audience')->required()->oneOf(['everyone', 'organizations', 'users']),
            Field::list('organization_ids', Field::string('id')->max(64))->max(100),
            Field::list('user_ids', Field::string('id')->max(64))->max(100),
            Field::list('client_ids', Field::string('id')->max(190))->max(100)->describe('Environment-wide OAuth client ids associated with this app. Does not grant access.'),
            Field::string('required_feature')->nullable()->max(64)->describe('Optional feature-flag key. Hidden when off for this person and organization.'),
            Field::string('icon')->nullable()->max(180000)->describe('PNG, JPEG or WebP data URI, up to 128 KiB and 512 pixels. Omit to retain; null clears. SVG and remote URLs are refused.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $entry = $this->catalog->save($context->string('key'), CatalogEntryData::fromArray($context->input), $context->actor());

        return ActionResult::item($entry, $this->catalog->present($entry, true));
    }
}
