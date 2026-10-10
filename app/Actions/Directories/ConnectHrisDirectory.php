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
use Cbox\Id\Directory\Contracts\Directories;
use Cbox\Id\Directory\Contracts\PullDirectories;
use Cbox\Id\Directory\DirectoryConnectors;
use Cbox\Id\Directory\Enums\DirectoryProvider;
use Cbox\Id\Directory\Hris\HrisCatalog;
use Cbox\Id\Directory\Jobs\SyncPullDirectory;
use Cbox\Id\Directory\Models\Directory;
use Illuminate\Support\Facades\DB;

/**
 * Connect an HR system — Workday, BambooHR, Rippling, HiBob or Personio — as a directory an
 * organization's people are synced from: joiners get an account on their start date,
 * leavers lose it at the end of their last day, departments become groups.
 *
 * VERIFIED BEFORE STORED, like the identity directories: the credentials are shaped by the
 * framework's catalogue and probed against the HR system, so a wrong key is refused here
 * rather than discovered by a nightly sync that provisions nobody. Critical, because it
 * opens a standing sync that creates and deactivates the organization's people with the
 * customer's own HR credentials — which are input only, sealed, and never returned.
 *
 * THE FIRST PULL IS QUEUED, not run in this request. An HR system can be thousands of
 * people over a rate-limited API; the answer is the directory, and its
 * `last_sync_status` reads `running` and then the outcome as a worker gets to it.
 */
#[AsAction(
    name: 'directories.hris.connect',
    summary: 'Connect an HR system (Workday, BambooHR, Rippling, HiBob or Personio) to sync an organization\'s people from, verifying the credentials first. The first sync is queued.',
    scope: 'directory_sync:write',
    danger: Danger::Critical,
    schema: 'Directory',
    tag: 'Directory Sync',
    rest: ['POST', '/directories/hris'],
    status: 201,
    consoleRoutes: ['directories.hris', 'environment.directories.hris'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class ConnectHrisDirectory implements Action
{
    public function __construct(
        private Directories $directories,
        private PullDirectories $pull,
        private DirectoryConnectors $connectors,
        private EnterpriseAudit $audit,
    ) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('organization_id')->required()->max(64)->describe('The organization whose people it provisions.'),
            Field::string('provider')->required()->oneOf(array_map(static fn (DirectoryProvider $p): string => $p->value, DirectoryProvider::hris()))
                ->describe('workday, bamboohr, rippling, hibob or personio.'),
            Field::string('name')->max(120)->describe('What administrators call it. Defaults to the HR system\'s name.'),
            HrisCredentialFields::field()->required(),
            Field::list('custom_attributes', Field::string('name')->max(190))->max(50)
                ->describe('The HR system\'s own field names to copy onto each person, verbatim.'),
            Field::integer('sync_interval_minutes')->nullable()->min(Directory::MIN_SYNC_INTERVAL_MINUTES)->max(Directory::MAX_SYNC_INTERVAL_MINUTES)
                ->describe('Minutes between scheduled pulls, 15 to 1440. Omitted or null: the platform default (hourly).'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $organizationId = EnterpriseReach::requiredOrganization($context);

        EnterpriseReach::assertEntitled($organizationId, 'scim');

        $provider = DirectoryProvider::tryFrom($context->string('provider'));

        if ($provider === null || ! $provider->isHris() || HrisCatalog::for($provider) === null) {
            throw ActionRefused::because('unknown_provider', 'Choose an HR system.', 'provider');
        }

        $credentials = HrisCredentialFields::verified($this->connectors, $provider, $context->array('credentials'));

        $name = trim((string) $context->nullableString('name'));
        $directory = $this->directories->registerPull($organizationId, $name === '' ? $provider->label() : $name, $provider, $credentials);

        $custom = array_values(array_filter($context->array('custom_attributes'), 'is_string'));

        if ($custom !== []) {
            $this->pull->setHrisOptions($directory, $custom);
        }

        $minutes = $context->nullableString('sync_interval_minutes');

        if ($minutes !== null) {
            $this->pull->setSyncInterval($directory, (int) $minutes);
        }

        $this->audit->record(EnterpriseAudit::DIRECTORY_CONNECTED, $context->actor(), $organizationId, 'directory', $directory->id, [
            'name' => $directory->name,
            'provider' => $provider->value,
        ]);

        $id = $directory->id;
        DB::afterCommit(static fn () => SyncPullDirectory::dispatch($id));

        return ActionResult::item($directory, DirectoryFields::present($directory));
    }
}
