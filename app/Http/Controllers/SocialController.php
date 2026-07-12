<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\PlatformAuth;
use App\Platform\SocialProviders;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Social sign-in (Google, GitHub, Microsoft) over OAuth via Socialite. The
 * federated identity is linked to a canonical subject by the provider-VERIFIED
 * email, so signing in with Google lands the user on the same account they'd
 * reach via SAML or a password — no duplicate accounts, no takeover.
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

        try {
            $social = Socialite::driver($provider)->user();
        } catch (Throwable) {
            return redirect()->route('login')->with('error', 'Sign-in with '.SocialProviders::label($provider).' was cancelled or failed.');
        }

        $email = $social->getEmail();

        if (! is_string($email) || $email === '' || ! $this->emailIsVerified($provider, $social->user)) {
            return redirect()->route('login')
                ->with('error', 'Your '.SocialProviders::label($provider).' account has no verified email address we can trust.');
        }

        $subject = $subjects->provisionFederated(new FederatedPrincipal(
            provider: 'social:'.$provider,
            subject: (string) $social->getId(),
            email: $email,
            name: $social->getName() ?? $social->getNickname(),
            raw: ['provider' => $provider],
        ));

        $auth->establish($request, $subject->id, ['social', $provider]);

        return redirect()->route('dashboard');
    }

    /**
     * Only link an identity when the provider asserts the email is verified.
     *
     * @param  array<string, mixed>  $raw
     */
    private function emailIsVerified(string $provider, array $raw): bool
    {
        return match ($provider) {
            // Google & Microsoft (OIDC) expose an explicit verification claim.
            'google', 'microsoft' => ($raw['email_verified'] ?? $raw['verified_email'] ?? false) === true,
            // Socialite resolves GitHub's primary email, which GitHub only
            // exposes once verified.
            'github' => true,
            default => false,
        };
    }
}
