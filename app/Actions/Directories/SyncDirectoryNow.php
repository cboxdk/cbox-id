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
use Cbox\Id\Directory\Enums\DirectoryStatus;
use Cbox\Id\Directory\Jobs\SyncPullDirectory;
use Illuminate\Support\Facades\DB;

/**
 * Pull a directory now instead of at its next scheduled run — after fixing a permission in
 * the HR system, or to watch a new hire arrive.
 *
 * QUEUED, NOT RUN HERE: a pull of an HR system can be thousands of people over a
 * rate-limited API, and holding a request (and the action's transaction) open for it is
 * how a "sync now" button times out halfway. The job is unique per directory and the sync
 * holds a per-directory lock, so pressing it twice — or pressing it while the schedule is
 * already pulling — runs it once. The answer is the directory as it stands; its
 * `last_sync_status` reads `running` once a worker has picked it up.
 *
 * `full` asks an HR system that syncs incrementally for everybody — the only kind of run
 * that deprovisions people who disappeared from it.
 */
#[AsAction(
    name: 'directories.sync',
    summary: 'Pull a Google Workspace, Microsoft Entra or HR-system directory now, on a worker. `full` asks an HR system for everybody rather than what changed.',
    scope: 'directory_sync:write',
    danger: Danger::Write,
    schema: 'Directory',
    tag: 'Directory Sync',
    rest: ['POST', '/directories/{id}/sync'],
    status: 202,
    consoleRoutes: ['directories.sync', 'environment.directories.sync'],
    consoleGate: ConsoleGate::Administer,
)]
final readonly class SyncDirectoryNow implements Action
{
    public function __construct(private EnterpriseAudit $audit) {}

    public static function input(): InputSchema
    {
        return InputSchema::of([
            Field::string('id')->inPath()->describe('The directory\'s id.'),
            EnterpriseReach::narrowField(),
            Field::boolean('full')->describe('Ask an HR system that syncs incrementally for everybody, not only what changed. Ignored by other providers.'),
        ]);
    }

    public function handle(ActionContext $context): ActionResult
    {
        $directory = DirectoryFields::changeable($context);

        if (! $directory->provider->isPull()) {
            throw ActionRefused::because('not_pull', 'A SCIM directory is pushed to by its identity provider; there is nothing to pull.');
        }

        if ($directory->status !== DirectoryStatus::Active) {
            throw ActionRefused::because('directory_paused', 'Resume the directory before syncing it.');
        }

        $full = $context->boolean('full');

        $this->audit->record(EnterpriseAudit::DIRECTORY_SYNC_REQUESTED, $context->actor(), $directory->organization_id, 'directory', $directory->id, [
            'name' => $directory->name,
            'full' => $full,
        ]);

        // After the action's transaction commits, so a worker never reads a directory the
        // request has not finished writing.
        $id = $directory->id;
        DB::afterCommit(static fn () => SyncPullDirectory::dispatch($id, $full));

        return ActionResult::item($directory, DirectoryFields::present($directory));
    }
}
