<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Http\Controllers\EnvironmentHandoffController;
use App\Platform\CurrentUser;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "YOU DON'T ADMINISTER THIS ENVIRONMENT" — what the root's open-environment door
 * ({@see EnvironmentHandoffController::openEnvironment()}) answers a person who is signed
 * in but may not open the environment they asked for.
 *
 * It is the end of a real journey, which is why it is a page and not a status code:
 * somebody opened `acme.cboxid.com/admin/users`, was sent to the root to sign in, signed in
 * — and the sign-in WORKED. A blank 403 at that point reads as "Google is broken". This says
 * the sign-in succeeded, whose console it is, who can let them in, and where they can go.
 *
 * 403, still: the answer to "may I" is no, and a crawler or a script should see that.
 */
final readonly class EnvironmentRefusedController extends ConsoleController
{
    public function show(Request $request, string $environment): Response
    {
        $model = app(EnvironmentContext::class)->withoutScope(
            static fn (): ?Environment => Environment::query()->find($environment),
        );

        return $this->page('console/environment-refused', 'You don’t administer this environment', [
            'refusedEnvironment' => $model === null ? null : ['name' => $model->name, 'type' => $model->type->value],
            'email' => app(CurrentUser::class)->email(),
            'projectsHref' => route('projects'),
            'accountsHref' => route('accounts'),
        ])->toResponse($request)->setStatusCode(403);
    }
}
