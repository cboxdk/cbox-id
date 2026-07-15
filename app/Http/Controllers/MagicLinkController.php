<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\PlatformAuth;
use App\Platform\RiskGuard;
use Cbox\Id\Identity\Contracts\MagicLink;
use Cbox\Id\Identity\Exceptions\InvalidMagicLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class MagicLinkController extends Controller
{
    public function redeem(Request $request, string $token, MagicLink $magicLink, PlatformAuth $auth, RiskGuard $risk): RedirectResponse
    {
        // Hard-block a Reject before consuming the single-use token, so a risky
        // context can be retried from a safer one. (Magic-link is already an
        // email-possession factor, so a step-up on top would be redundant.)
        if ($risk->shouldBlock($risk->assess($request, 'login'))) {
            return redirect()->route('login')->with('error', 'We could not process this request. Please try again later.');
        }

        try {
            $session = $magicLink->redeem($token);
        } catch (InvalidMagicLink) {
            return redirect()->route('login')->with('error', 'That sign-in link is invalid or has expired.');
        }

        $auth->adopt($request, $session);

        return redirect()->route('dashboard');
    }
}
