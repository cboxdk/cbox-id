<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\OperatorAuth;
use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Organization\Models\Environment;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OperatorController extends Controller
{
    public function logout(Request $request, OperatorAuth $auth): RedirectResponse
    {
        $auth->logout($request);

        return redirect()->route('operator.login')->with('status', 'Signed out of the operator console.');
    }

    /**
     * Point the operator console at another environment. Operators stand above
     * every plane, so there is no identity guard — any real environment is fair
     * game. The selection is the target for reads and provisioning.
     */
    public function switchEnvironment(Request $request): RedirectResponse
    {
        $id = $request->string('environment')->toString();
        $environment = $id !== '' ? Environment::query()->find($id) : null;

        if ($environment !== null) {
            $request->session()->put(OperatorAuth::ENV_KEY, $environment->slug);
        }

        return redirect()->route('operator.environments');
    }

    /**
     * Jump from a cross-environment search result to a tenant's detail page.
     *
     * The org detail page is plane-scoped: an id outside the currently-targeted
     * environment resolves to null → 404. So we first re-point the console at the
     * org's OWN environment (exactly as {@see switchEnvironment}, under the
     * operator-only {@see OperatorAuth::ENV_KEY}), then hand off to the detail page,
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

        session()->put(OperatorAuth::ENV_KEY, $environment->slug);

        return redirect()->route('operator.organization', $org->id);
    }
}
