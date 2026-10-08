<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Platform\Console\ConsoleOrganization;
use App\Platform\Console\ConsoleScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /admin/lookup/organizations?q=` — the organizations of this environment, a page at a
 * time, for the console's "For which organization?" field and its Organization filter chips.
 *
 * A SEARCH, NOT A LIST. An environment's organizations are unbounded — the shape this
 * product is sold into is a customer with thousands of them — and a control that rendered
 * every one served a 3.5 MB document before anybody clicked anything. So this answers eight
 * at a time and lets the administrator type to narrow it.
 *
 * It only ever READS. It used to sit beside a "choose" and a "clear" that kept the answer
 * in the session as the console's acting organization; those are gone, and the answer is
 * now a form field or a URL parameter that the page it lands on checks again.
 *
 * JSON rather than a partial reload, deliberately: the alternative writes the typed term
 * into the address bar, and the term is the state of a control rather than where you are.
 */
final readonly class OrganizationLookupController
{
    public function __construct(private ConsoleScope $scope) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($this->scope->signedIn(), 403);

        $results = $this->scope->searchOrganizations($request->string('q')->toString());

        return response()->json([
            'results' => array_map(static fn (ConsoleOrganization $organization): array => [
                'id' => $organization->id,
                'name' => $organization->name,
            ], $results),
            /*
             * Only counted once the page is full: "8 of 8" is noise, and a COUNT over every
             * organization in the environment is exactly the unbounded read this lookup
             * exists to stop paying.
             */
            'total' => count($results) < ConsoleScope::LOOKUP_LIMIT
                ? count($results)
                : $this->scope->organizationCount(),
        ]);
    }
}
