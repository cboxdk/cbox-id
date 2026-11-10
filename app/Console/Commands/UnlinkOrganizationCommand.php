<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Organizations\OrganizationLinks;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Undo a pair made by mistake: the platform-root organization goes back to its own slug
 * as its address, and can be paired again with `orgs:link`.
 */
final class UnlinkOrganizationCommand extends Command
{
    protected $signature = 'orgs:unlink
        {platform : The platform-root organization, by slug or id}';

    protected $description = 'Remove the link between a platform-root organization and its cbox organization';

    public function handle(OrganizationLinks $links): int
    {
        $platform = $this->argument('platform');
        $root = $links->rootEnvironmentId();

        if (! is_string($platform) || $platform === '') {
            $this->error('Name the platform-root organization, by slug or id.');

            return self::INVALID;
        }

        $id = $root === null ? null : DB::table('organizations')
            ->where('environment_id', $root)
            ->where(static fn (Builder $query): Builder => $query->where('slug', $platform)->orWhere('id', $platform))
            ->value('id');

        if (! is_string($id)) {
            $this->error("No platform-root organization {$platform}.");

            return self::FAILURE;
        }

        if (! $links->unlink($id)) {
            $this->error("{$platform} is not linked.");

            return self::FAILURE;
        }

        $this->info("Unlinked {$platform}.");

        return self::SUCCESS;
    }
}
