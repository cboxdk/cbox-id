<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Actions\ActionPlane;
use App\Platform\Actions\Reference\ActionsReference;
use Illuminate\Console\Command;

/**
 * Writes `docs/reference/actions-<plane>.md` — one page per {@see ActionPlane} — from the
 * action registry ({@see ActionsReference}): every action's REST route, scope, danger,
 * MCP tool, CLI command, summary and input.
 *
 * `--check` writes nothing and fails when a committed page is not what the build would
 * produce — the same gate `openapi:build --check` is for the OpenAPI documents, so the
 * reference a developer reads cannot fall behind the actions the server runs.
 */
final class BuildActionsReferenceCommand extends Command
{
    protected $signature = 'docs:actions {--check : Fail when a committed reference page is stale, without writing}';

    protected $description = 'Build the actions reference pages in docs/reference from the action registry';

    public function handle(ActionsReference $reference): int
    {
        $stale = [];

        foreach (ActionPlane::cases() as $plane) {
            $path = base_path(ActionsReference::path($plane));
            $page = $reference->render($plane);
            $current = is_file($path) ? (string) file_get_contents($path) : '';

            if ($current === $page) {
                continue;
            }

            if ($this->option('check')) {
                $stale[] = ActionsReference::path($plane);

                continue;
            }

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }

            file_put_contents($path, $page);
            $this->components->info('Wrote '.ActionsReference::path($plane).'.');
        }

        if ($stale !== []) {
            $this->components->error('Stale: '.implode(', ', $stale).'. Run `php artisan docs:actions` and commit the result.');

            return self::FAILURE;
        }

        if ($this->option('check')) {
            $this->components->info('The actions reference is current.');
        }

        return self::SUCCESS;
    }
}
