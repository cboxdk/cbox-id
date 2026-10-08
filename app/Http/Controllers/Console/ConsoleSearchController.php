<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Platform\Console\ConsoleScope;
use App\Platform\Console\ConsoleSearch;
use App\Platform\Console\PaletteActions;
use App\Platform\Console\ShellPayload;
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

        return response()->json([
            ...$results,
            'actions' => $actions->for($shell->build()->areas ?? []),
        ], 200, [
            // Personal and per-environment: never kept by a shared cache.
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
