<?php

declare(strict_types=1);

namespace App\Platform\Portal;

use Carbon\CarbonInterface;
use Cbox\Id\Directory\Models\Directory;
use Cbox\Id\Directory\Models\DirectoryGroup;
use Cbox\Id\Directory\Models\DirectoryUser;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * WHEN EACH DIRECTORY LAST HEARD FROM ITS IDENTITY PROVIDER — "First update received" on
 * the Admin Portal's checklist and "Last update received …" on its directory list.
 *
 * The two kinds of directory leave different evidence, and reading only one of them is how
 * a SCIM directory said "No updates received yet" however many people it had pushed:
 *
 *  - A PULL directory (Google Workspace, Entra) is fetched on a schedule, and each
 *    successful run stamps `last_synced_at`.
 *  - A PUSH (SCIM) directory is written to by the customer's identity provider. Nothing
 *    stamps `last_synced_at` — that column is the pull job's — so what a push leaves behind
 *    is the people and groups it wrote, and the latest of their `updated_at` is when it
 *    last wrote. A SCIM DELETE deactivates a user row rather than removing it, so the
 *    evidence does not disappear with the people.
 *
 * Two grouped reads for any number of directories, never one per directory.
 */
final readonly class DirectoryUpdates
{
    /**
     * @param  iterable<Directory>  $directories
     * @return array<string, CarbonInterface|null> Keyed by directory id; null when nothing has arrived.
     */
    public function lastReceived(iterable $directories): array
    {
        $received = [];
        $pushed = [];

        foreach ($directories as $directory) {
            $received[$directory->id] = $directory->last_synced_at;

            if ($directory->last_synced_at === null) {
                $pushed[] = $directory->id;
            }
        }

        if ($pushed === []) {
            return $received;
        }

        foreach ([DirectoryUser::query()->toBase(), DirectoryGroup::query()->toBase()] as $rows) {
            foreach ($this->latestWrites($rows, $pushed) as $directoryId => $at) {
                $current = $received[$directoryId] ?? null;
                $received[$directoryId] = $current === null || $at->greaterThan($current) ? $at : $current;
            }
        }

        return $received;
    }

    /**
     * The latest write per directory in one table. A base query of the model's — `toBase()`
     * keeps the environment scope.
     *
     * @param  list<string>  $directoryIds
     * @return array<string, CarbonInterface>
     */
    private function latestWrites(Builder $rows, array $directoryIds): array
    {
        $latest = [];

        $found = $rows
            ->whereIn('directory_id', $directoryIds)
            ->groupBy('directory_id')
            ->selectRaw('directory_id, max(updated_at) as latest')
            ->get();

        foreach ($found as $row) {
            $id = $row->directory_id ?? null;
            $at = $row->latest ?? null;

            if (is_string($id) && is_string($at)) {
                $latest[$id] = Carbon::parse($at);
            }
        }

        return $latest;
    }
}
