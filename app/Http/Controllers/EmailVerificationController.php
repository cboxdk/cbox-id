<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Cbox\Id\Identity\Contracts\EmailVerification;
use Cbox\Id\Identity\Exceptions\InvalidEmailVerification;
use Illuminate\Http\RedirectResponse;

final class EmailVerificationController extends Controller
{
    public function verify(string $token, EmailVerification $verification): RedirectResponse
    {
        try {
            $verification->verify($token);
        } catch (InvalidEmailVerification) {
            return redirect()->route('login')->with('error', 'That verification link is invalid or has expired.');
        }

        return redirect()->route('login')->with('status', 'Your email is verified — you can sign in.');
    }
}
