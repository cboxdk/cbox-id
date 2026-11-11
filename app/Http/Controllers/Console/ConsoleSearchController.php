<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Platform\Console\ConsoleScope;
use App\Platform\Console\ConsoleSearch;
use App\Platform\Console\PaletteActions;
use App\Platform\Console\ShellPayload;
use App\Platform\Shell\IdSearchProvider;
use Cbox\Console\Kit\Shell\SearchResult;
use Cbox\Console\Kit\Shell\ShellSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ⌘K's SEARCH — `GET /admin/search?q=` on the environment console, `GET /search?q=` on the
 * organization console. JSON, because the palette asks as the person types and a page
 * visit per keystroke would rebuild the whole console for a dropdown.
 *
 * What is searched, and within what, is {@see ConsoleSearch}'s: the current environment,
 * and on the organization console only the organization its person is confined to. The
 * palette's actions ride along ({@see PaletteActions}), offered from the same rail the
 * person sees.
 *
 * Not a {@see ConsoleController}: it renders no page, so it has no title and no plane of
 * its own to state.
 */
final readonly class ConsoleSearchController
{
    public function __invoke(Request $request, ConsoleScope $scope, ConsoleSearch $search, PaletteActions $actions, ShellPayload $shell): JsonResponse
    {
        $scope->assertMayAdminister();

        $results = $search->search($request->string('q')->toString());

        // The shared shell's palette asks per group (`?group=records|goto`) and reads
        // console-kit's answer: records found HERE, in this console's environment, and "Go
        // to" from the kit, across the person's organizations.
        if ($request->filled('group')) {
            return $this->grouped($request, $results);
        }

        return response()->json([
            ...$results,
            'actions' => $actions->for($shell->build()->areas ?? []),
        ], 200, [
            // Personal and per-environment: never kept by a shared cache.
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @param  array{groups: list<array{key: string, label: string, items: list<array{kind: string, id: string, title: string, subtitle: string|null, href: string}>}>}  $results
     */
    private function grouped(Request $request, array $results): JsonResponse
    {
        $group = $request->string('group')->toString();
        $limit = max(1, min(20, $request->integer('limit', 5)));
        $groups = [];

        if ($group === 'records') {
            $groups[] = [
                'key' => 'records',
                'label' => __('Records'),
                'items' => array_map(static fn (SearchResult $result): array => $result->toArray(), IdSearchProvider::flatten($results, $limit)),
            ];
        } elseif ($group === 'goto') {
            $context = array_filter((array) $request->input('context', []), static fn (mixed $id, mixed $key): bool => is_string($key) && is_string($id), ARRAY_FILTER_USE_BOTH);
            $from = $request->string('from')->toString();

            $groups = app(ShellSearch::class)->search($request, $request->string('q')->toString(), $context, str_starts_with($from, '/') ? $from : '/', ['goto'], $limit);
        }

        return response()->json(['groups' => $groups], 200, ['Cache-Control' => 'private, no-store']);
    }
}
