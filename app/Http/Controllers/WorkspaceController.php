<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\AccountAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class WorkspaceController extends Controller
{
    public function logout(Request $request, AccountAuth $auth): RedirectResponse
    {
        $auth->logout($request);

        return redirect()->route('workspace.login')->with('status', 'Signed out of your workspace.');
    }
}
