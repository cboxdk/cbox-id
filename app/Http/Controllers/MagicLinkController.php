<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\PlatformAuth;
use Cbox\Id\Identity\Contracts\MagicLink;
use Cbox\Id\Identity\Exceptions\InvalidMagicLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class MagicLinkController extends Controller
{
    public function redeem(Request $request, string $token, MagicLink $magicLink, PlatformAuth $auth): RedirectResponse
    {
        try {
            $session = $magicLink->redeem($token);
        } catch (InvalidMagicLink) {
            return redirect()->route('login')->with('error', 'That sign-in link is invalid or has expired.');
        }

        $auth->adopt($request, $session);

        return redirect()->route('dashboard');
    }
}
