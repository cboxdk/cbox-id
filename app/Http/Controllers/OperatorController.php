<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\OperatorEnvironment;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The operator console's two plain-HTTP actions: aim the console at a plane, and
 * follow a cross-plane search result into one.
 *
 * There is no `logout()` here any more. It used to end a session of its own and send
 * the operator to a sign-in of its own, with a message — "Signed out of the operator
 * console." — that described a thing that no longer exists. Signing out of the operator
 * console IS signing out; the one console's own logout (`workspace.logout`, or the
 * subject plane's `logout`) does it, and there is nothing left for a second door to end.
 * The route is gone rather than aliased: a POST endpoint is not something anyone has
 * bookmarked, and the deleted sign-in form was its only caller.
 */
final class OperatorController extends Controller
{
    public function __construct(private readonly OperatorEnvironment $target) {}

    /**
     * Point the operator console at another environment. Operators stand above
     * every plane, so there is no identity guard here — the route group already
     * established the authority, and any real environment is fair game. The selection
     * is the target for reads and provisioning.
     */
    public function switchEnvironment(Request $request): RedirectResponse
    {
        $id = $request->string('environment')->toString();
        $environment = $id !== '' ? Environment::query()->find($id) : null;

        if ($environment !== null) {
            $this->target->pointAt($environment->slug);
        }

        return redirect()->route('operator.environments');
    }

    /**
     * Jump from a cross-environment search result to a tenant's detail page.
     *
     * The org detail page is plane-scoped: an id outside the currently-targeted
     * environment resolves to null → 404. So we first re-point the console at the
     * org's OWN environment (exactly as {@see switchEnvironment}, under the
     * operator-only {@see OperatorEnvironment::SESSION_KEY}), then hand off to the detail page,
     * where the org is now in-scope. The target env/org is derived from a real,
     * found record — never from arbitrary input beyond the id we resolve here.
     */
    public function jumpToOrganization(string $organization, EnvironmentContext $context): RedirectResponse
    {
        // Provisioning escape: resolve the org across every plane so an operator can
        // reach a tenant that does not live in the currently-pinned environment.
        $org = $context->withoutScope(
            static fn (): ?Organization => Organization::query()->find($organization)
        );

        abort_if($org === null, 404);

        $environmentId = $org->getAttribute('environment_id');
        $environment = is_string($environmentId) ? Environment::query()->find($environmentId) : null;

        abort_if($environment === null, 404);

        $this->target->pointAt($environment->slug);

        return redirect()->route('operator.organization', $org->id);
    }
}
