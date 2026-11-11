<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Organizations\OrganizationLinks;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Pair a platform-root organization with its organization in the `cbox` environment, or
 * list the ones that are not paired yet. The migration pairs equal slugs; this is for the
 * rest, by hand, one at a time.
 */
final class LinkOrganizationCommand extends Command
{
    protected $signature = 'orgs:link
        {platform? : The platform-root organization, by slug or id}
        {cbox? : The organization in the cbox environment, by slug or id}
        {--unpaired : List the platform-root organizations with no link}
        {--by-slug : Pair every unpaired organization whose slug matches and whose owner is a member there, as the migration does}
        {--dry-run : With --by-slug, list the pairs without making them}';

    protected $description = 'Link a platform-root organization to the same organization in the cbox environment';

    public function handle(OrganizationLinks $links): int
    {
        if ($this->option('by-slug') === true && $this->option('dry-run') === true) {
            $pairs = $links->autoPairs();
            $this->table(['platform', 'cbox', 'slug'], array_map(static fn (array $pair): array => [$pair['organization_id'], $pair['cbox_organization_id'], $pair['slug']], $pairs));
            $this->line(count($pairs).' pair(s) would be made. Nothing was written.');

            return self::SUCCESS;
        }

        if ($this->option('by-slug') === true) {
            $this->info('Paired '.$links->pairBySlug().' organization(s) by slug.');

            return self::SUCCESS;
        }

        if ($this->option('unpaired') === true) {
            $rows = $links->unpaired();
            $this->table(['id', 'slug', 'name'], $rows);
            $this->line(count($rows).' unpaired.');

            return self::SUCCESS;
        }

        $platform = $this->argument('platform');
        $cbox = $this->argument('cbox');

        if (! is_string($platform) || ! is_string($cbox)) {
            $this->error('Name both organizations, or pass --unpaired or --by-slug.');

            return self::INVALID;
        }

        $platformId = $this->idOf($platform, $links->rootEnvironmentId());
        $cboxId = $this->idOf($cbox, $links->cboxEnvironmentId());

        try {
            $links->link($platformId ?? $platform, $cboxId ?? $cbox);
        } catch (DomainException $refused) {
            $this->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->info("Linked {$platform} to {$cbox}.");

        return self::SUCCESS;
    }

    private function idOf(string $slugOrId, ?string $environmentId): ?string
    {
        if ($environmentId === null) {
            return null;
        }

        $id = DB::table('organizations')
            ->where('environment_id', $environmentId)
            ->where(static fn (Builder $query): Builder => $query->where('slug', $slugOrId)->orWhere('id', $slugOrId))
            ->value('id');

        return is_string($id) ? $id : null;
    }
}
