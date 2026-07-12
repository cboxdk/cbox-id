<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\CurrentUser;
use App\Platform\PlatformAuth;
use App\Platform\SocialProviders;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Exceptions\AccountExistsForEmail;
use Cbox\Id\Identity\Exceptions\IdentityAlreadyLinked;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Social sign-in and account linking (Google, GitHub, Microsoft) over OAuth.
 *
 * The platform NEVER auto-merges accounts by email. A social sign-in only reaches
 * an existing account if that provider identity was explicitly linked earlier by
 * an authenticated user. Otherwise a first-seen identity gets its own account, or
 * — if the email is already taken — the sign-in is refused with guidance to link
 * from Settings. Linking proves control of both sides: the user is signed in AND
 * completes the provider's auth.
 */
final class SocialController extends Controller
{
    public function redirect(string $provider): SymfonyRedirect
    {
        abort_unless(SocialProviders::isConfigured($provider), 404);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider, Request $request, Subjects $subjects, PlatformAuth $auth): RedirectResponse
    {
        abort_unless(SocialProviders::isConfigured($provider), 404);

        $principal = $this->resolve($provider);

        if ($principal === null) {
            return redirect()->route('login')->with('error', 'Sign-in with '.SocialProviders::label($provider).' was cancelled or failed.');
        }

        try {
            $subject = $subjects->provisionFederated($principal);
        } catch (AccountExistsForEmail) {
            return redirect()->route('login')->with(
                'error',
                'An account already uses that email. Sign in with your existing method, then connect '.SocialProviders::label($provider).' from Settings.',
            );
        }

        $auth->establish($request, $subject->id, ['social', $provider]);

        return redirect()->route('dashboard');
    }

    /**
     * Begin linking a provider to the SIGNED-IN account (authenticated route).
     */
    public function connect(string $provider): SymfonyRedirect
    {
        abort_unless(SocialProviders::isConfigured($provider), 404);

        return Socialite::driver($provider)->redirectUrl(route('social.connect.callback', $provider))->redirect();
    }

    public function connectCallback(string $provider, Subjects $subjects, CurrentUser $me): RedirectResponse
    {
        abort_unless(SocialProviders::isConfigured($provider), 404);

        $principal = $this->resolve($provider, route('social.connect.callback', $provider));

        if ($principal === null) {
            return redirect()->route('settings')->with('error', 'Connecting '.SocialProviders::label($provider).' was cancelled or failed.');
        }

        try {
            $subjects->link($me->id(), $principal);
        } catch (IdentityAlreadyLinked) {
            return redirect()->route('settings')->with('error', 'That '.SocialProviders::label($provider).' account is already linked to another user.');
        }

        return redirect()->route('settings')->with('status', SocialProviders::label($provider).' connected.');
    }

    private function resolve(string $provider, ?string $redirectUrl = null): ?FederatedPrincipal
    {
        try {
            $driver = Socialite::driver($provider);
            $social = ($redirectUrl !== null ? $driver->redirectUrl($redirectUrl) : $driver)->user();
        } catch (Throwable) {
            return null;
        }

        $email = $social->getEmail();

        return new FederatedPrincipal(
            provider: 'social:'.$provider,
            subject: (string) $social->getId(),
            email: is_string($email) && $email !== '' ? $email : null,
            name: $social->getName() ?? $social->getNickname(),
            raw: ['provider' => $provider],
        );
    }
}
