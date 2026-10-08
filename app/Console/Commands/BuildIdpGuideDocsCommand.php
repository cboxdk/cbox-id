<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Portal\IdpGuideDocs;
use Cbox\Id\Federation\IdentityProviderGuides;
use Illuminate\Console\Command;

/**
 * Writes `docs/for-it-admins/idp/<provider>.md` for every identity provider guide the
 * framework ships ({@see IdentityProviderGuides}) that has no hand-written page, and the
 * provider table on that section's `_index.md` ({@see IdpGuideDocs}).
 *
 * `--check` writes nothing and fails when a committed page is not what the build would
 * produce — the same gate `docs:actions --check` is for the actions reference, so a
 * framework upgrade that renames a field or adds a provider cannot leave the IT
 * administrator's pages behind the portal.
 */
final class BuildIdpGuideDocsCommand extends Command
{
    protected $signature = 'docs:idp-guides {--check : Fail when a committed page is stale, without writing}';

    protected $description = 'Build the identity provider pages in docs/for-it-admins/idp from the framework\'s guides';

    public function handle(IdpGuideDocs $docs): int
    {
        $stale = [];
        $pages = $docs->pages();

        $indexPath = base_path(IdpGuideDocs::INDEX);
        $index = $docs->index(is_file($indexPath) ? (string) file_get_contents($indexPath) : '');

        if ($index === null) {
            $this->components->error(IdpGuideDocs::INDEX.' has lost its generated-table markers.');

            return self::FAILURE;
        }

        $pages[IdpGuideDocs::INDEX] = $index;

        foreach ($pages as $relative => $page) {
            $path = base_path($relative);
            $current = is_file($path) ? (string) file_get_contents($path) : '';

            if ($current === $page) {
                continue;
            }

            if ($this->option('check')) {
                $stale[] = $relative;

                continue;
            }

            file_put_contents($path, $page);
            $this->components->info('Wrote '.$relative.'.');
        }

        if ($stale !== []) {
            $this->components->error('Stale: '.implode(', ', $stale).'. Run `php artisan docs:idp-guides` and commit the result.');

            return self::FAILURE;
        }

        if ($this->option('check')) {
            $this->components->info('The identity provider pages are current.');
        }

        return self::SUCCESS;
    }
}
