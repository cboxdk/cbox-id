<?php

declare(strict_types=1);

namespace App\Actions\Directories;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionContext;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\AsAction;
use App\Platform\Actions\ConsoleGate;
use App\Platform\Actions\Danger;
use App\Platform\Actions\Input\Field;
use App\Platform\Actions\Input\InputSchema;
use App\Platform\Enterprise\EnterpriseAudit;
use App\Platform\Enterprise\EnterpriseReach;
use Cbox\Id\Directory\Contracts\PullDirectories;
use Cbox\Id\Directory\Hris\HrisCatalog;
use Cbox\Id\Directory\Models\Directory;

/**
 * Pace a pull directory and, for an HR system, choose which of its fields pass through.
 *
 * `sync_interval_minutes` is how often THIS directory is pulled (15 minutes to a day; null
 * is the platform default, hourly). `custom_attributes` are the HR system's own field names
 * copied onto each person as they are — a cost centre, a location, a contract type — so a
 * downstream app can read them off the directory; `field_map` names the columns of a
 * Workday report. Changing either set of fields makes the next run a full pull.
 *
 * Every field is optional and only what is sent changes.
 */
#[AsAction(
    name: 'directories.sync_settings.update',
    summary: 'Set how often a pull directory syncs, and for an HR system which of its fields pass through onto people.',
    scope: 'directory_sync:write',
    danger: Danger::Write,
    schema: 'Directory',
    tag: 'Directory Sync',
    rest: ['PATCH', '/directories/{id}/sync-settings'],
    consoleRoutes: ['directories.sync-settings', 'environment.directories.sync-settings'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class ConfigureDirectorySync implements Action
{
    public function __construct(
        private PullDirectories $directories,
        private EnterpriseAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The directory\'s id.'),
            EnterpriseReach::narrowField(),
            Field::integer('sync_interval_minutes')->nullable()->min(Directory::MIN_SYNC_INTERVAL_MINUTES)->max(Directory::MAX_SYNC_INTERVAL_MINUTES)
                ->describe('Minutes between scheduled pulls, 15 to 1440. null returns to the platform default (hourly).'),
            Field::list('custom_attributes', Field::string('name')->max(190))->max(50)
                ->describe('HR systems only: the HR system\'s own field names to copy onto each person, verbatim.'),
            Field::object('field_map', array_map(
                static fn (string $field, string $default): Field => Field::string($field)->max(190)->describe("The report column holding {$field}. Default: {$default}."),
                array_keys(HrisCatalog::fieldMapDefaults()),
                array_values(HrisCatalog::fieldMapDefaults()),
            ))
                ->describe('Workday only: the report column holding each field, where it is not the default name.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $directory = DirectoryFields::changeable($context);

        if (! $directory->provider->isPull()) {
            throw ActionRefused::because('not_pull', 'A SCIM directory is pushed to by its identity provider; it has no pull schedule.');
        }

        $changed = [];

        if ($context->has('sync_interval_minutes')) {
            $minutes = $context->nullableString('sync_interval_minutes');
            $this->directories->setSyncInterval($directory, $minutes === null ? null : (int) $minutes);
            $changed['sync_interval_minutes'] = $directory->sync_interval_minutes;
        }

        if ($context->has('custom_attributes') || $context->has('field_map')) {
            if (! $directory->provider->isHris()) {
                throw ActionRefused::because('not_hris', 'Only an HR-system directory passes fields through.', 'custom_attributes');
            }

            $current = DirectoryFields::hrisOptions($directory);

            $custom = $context->has('custom_attributes')
                ? array_values(array_filter($context->array('custom_attributes'), 'is_string'))
                : $current['custom_attributes'];

            $map = $current['field_map'];

            if ($context->has('field_map')) {
                $map = [];

                foreach ($context->array('field_map') as $field => $column) {
                    if (is_string($field) && is_string($column)) {
                        $map[$field] = $column;
                    }
                }
            }

            $this->directories->setHrisOptions($directory, $custom, $map);
            $changed['custom_attributes'] = $custom;
            $changed['field_map'] = array_keys($map);
        }

        $this->audit->record(EnterpriseAudit::DIRECTORY_SYNC_CONFIGURED, $context->actor(), $directory->organization_id, 'directory', $directory->id, [
            'name' => $directory->name,
            ...$changed,
        ]);

        return ActionResult::item($directory, DirectoryFields::present($directory));
    }
}
