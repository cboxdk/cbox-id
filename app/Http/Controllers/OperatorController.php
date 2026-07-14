<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\OperatorAuth;
use Cbox\Id\Organization\Models\Environment;
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
}
