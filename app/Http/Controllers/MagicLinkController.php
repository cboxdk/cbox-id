<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\AccountAuth;
use App\Platform\Enums\AttemptOutcome;
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

    /**
     * Redeem a magic link on the ACCOUNT plane — the workspace door.
     *
     * Account members are ordinary subjects in the platform-root environment, which is
     * the only reason this works at all: the same single-use token machinery serves both
     * doors. What the account plane adds is the membership check — a redeemed link for a
     * subject that carries no account membership signs nobody in here, so a link issued
     * on the tenant plane can never be walked into the account console. A member holding
     * a second factor is still held at the challenge.
     */
    public function redeemForWorkspace(Request $request, string $token, MagicLink $magicLink, AccountAuth $auth, RiskGuard $risk): RedirectResponse
    {
        if ($risk->shouldBlock($risk->assess($request, 'login'))) {
            return redirect()->route('workspace.login')->with('error', 'We could not process this request. Please try again later.');
        }

        try {
            $session = $magicLink->redeem($token);
        } catch (InvalidMagicLink) {
            return redirect()->route('workspace.login')->with('error', 'That sign-in link is invalid or has expired.');
        }

        return match ($auth->adoptSubject($session)) {
            AttemptOutcome::Ok => redirect()->intended(route('workspace.home')),
            AttemptOutcome::Mfa => redirect()->route('workspace.login.mfa'),
            default => redirect()->route('workspace.login')->with('error', 'That sign-in link is invalid or has expired.'),
        };
    }
}
