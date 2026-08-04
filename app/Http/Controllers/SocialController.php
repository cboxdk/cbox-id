<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\EmailVerificationMail;
use App\Platform\CurrentUser;
use App\Platform\Enums\RefusedFactor;
use App\Platform\MailLinks;
use App\Platform\PlatformAuth;
use App\Platform\Social\OperatorProvider;
use App\Platform\Social\OperatorProviders;
use App\Platform\Social\OperatorSocialFlow;
use App\Platform\SsoRefusal;
use Cbox\Id\Identity\Contracts\EmailVerification;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Exceptions\AccountExistsForEmail;
use Cbox\Id\Identity\Exceptions\IdentityAlreadyLinked;
use Cbox\Id\Identity\ValueObjects\FederatedPrincipal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Social sign-in and account linking for the OPERATOR's own providers.
 *
 * These run on the same federation clients as every tenant connection — the OIDC
 * relying party and assertion validator for Google and Entra, the OAuth 2.0 client for
 * GitHub — so the SSRF pinning, the RS256 allow-list and the refusal to treat a
 * provider's word about an address as proof are the platform's, in one place, rather
 * than a third-party driver's absence of an opinion. See {@see OperatorSocialFlow}.
 *
 * The platform NEVER auto-merges accounts by email. A social sign-in only reaches an
 * existing account if that provider identity was explicitly linked earlier by an
 * authenticated user. Otherwise a first-seen identity gets its own account, or — if the
 * email is already taken — the sign-in is refused with guidance to link from Settings.
 * Linking proves control of both sides: the user is signed in AND completes the
 * provider's auth.
 */
final class SocialController extends Controller
{
    public function __construct(
        private readonly OperatorProviders $providers,
        private readonly OperatorSocialFlow $flow,
    ) {}

    public function redirect(string $provider, Request $request): RedirectResponse
    {
        return $this->start($request, $provider, route('social.callback', $provider));
    }

    public function callback(string $provider, Request $request, Subjects $subjects, PlatformAuth $auth, MailLinks $links): RedirectResponse
    {
        $operator = $this->providers->find($provider);

        abort_if($operator === null, 404);

        $principal = $this->resolve($request, $operator, route('social.callback', $provider));

        if ($principal === null) {
            return redirect()->route('login')->with('error', 'Sign-in with '.$operator->label().' was cancelled or failed.');
        }

        try {
            $provisioning = $subjects->resolveFederated($principal);
            $subject = $provisioning->subject;

            // A first social sign-in IS a signup, so it gets the signup's obligations —
            // the same token, the same mail, the same out-of-band confirmation. What the
            // provider claims about the address is not evidence: Discord will carry any
            // address someone types, and even Google's assertion is a statement about
            // their relationship with the user, not ours.
            if ($provisioning->created && $principal->email !== null) {
                $token = app(EmailVerification::class)->issue($subject->id, $principal->email);
                Mail::to($principal->email)->send(new EmailVerificationMail(
                    // MailLinks: mailed, so the origin comes from the deployment.
                    $links->route('verification.verify', $token),
                ));
            }
        } catch (AccountExistsForEmail) {
            // Don't dead-end: hold the verified identity aside and ask the user to
            // sign in to the existing account — linking then completes on login.
            $auth->startPendingLink($principal);

            return redirect()->route('login');
        }

        // Refused under a mandate, and this is the least arguable of the doors. Every other
        // one is about how strong a local factor is; this one authenticates against a
        // DIFFERENT IDENTITY PROVIDER than the mandated one, which is the exact thing the
        // mandate is for. "Continue with Google" is a fine way in until an administrator
        // says their directory decides, and then it is somebody else's directory deciding.
        //
        // These are the OPERATOR's providers — the buttons the deployment offers — not the
        // organization's connection, so there is no reading on which the mandate points
        // here. {@see \App\Platform\SsoMandates::describe()} excludes them from the link it
        // renders for exactly the same reason.
        //
        // After the provider's callback verifies and the subject resolves, never before:
        // the refusal must depend on having completed the flow, not on the address.
        if (! $auth->localSignInAllowedFor($subject->id)) {
            SsoRefusal::hold($subject->id, RefusedFactor::Social);

            return redirect()->route('login');
        }

        $auth->establish($request, $subject->id, ['social', $provider]);

        // A brand-new social account has exactly one way in, and it belongs to somebody
        // else: if the provider is unreachable — or the person closes that account, or
        // loses it — this account is gone with it. It also has no password, so every
        // step-up gate that asks for one has nothing to ask.
        //
        // Sent to the account page rather than a bespoke wizard, because that page
        // already offers exactly these two things and will still be the right place to
        // do them tomorrow. Prompted, not forced: holding someone on a form at the very
        // first moment of a one-click sign-in is how a one-click sign-in stops being one.
        if ($provisioning->created) {
            return redirect()->route('account')->with(
                'status',
                'Welcome. Two things worth doing now: confirm your email — we have sent a link'
                .($principal->email === null ? '' : ' to '.$principal->email)
                .' — and set a password, so you can still get in if '
                .$operator->label().' is ever unavailable.',
            );
        }

        return redirect()->route('dashboard');
    }

    /**
     * Begin linking a provider to the SIGNED-IN account (authenticated route).
     */
    public function connect(string $provider, Request $request): RedirectResponse
    {
        return $this->start($request, $provider, route('social.connect.callback', $provider));
    }

    public function connectCallback(string $provider, Request $request, Subjects $subjects, CurrentUser $me): RedirectResponse
    {
        $operator = $this->providers->find($provider);

        abort_if($operator === null, 404);

        $principal = $this->resolve($request, $operator, route('social.connect.callback', $provider));

        if ($principal === null) {
            return redirect()->route('settings')->with('error', 'Connecting '.$operator->label().' was cancelled or failed.');
        }

        try {
            $subjects->link($me->id(), $principal);
        } catch (IdentityAlreadyLinked) {
            return redirect()->route('settings')->with('error', 'That '.$operator->label().' account is already linked to another user.');
        }

        return redirect()->route('settings')->with('status', $operator->label().' connected.');
    }

    /**
     * Issue the state and nonce, stash them, and send the browser on.
     *
     * Both are generated here and checked on return. The third-party driver this
     * replaced kept its own state in the session and validated it out of sight; nothing
     * does that for us now, so it is explicit — and it has to be, because without it the
     * callback below is an unauthenticated URL that signs somebody in.
     */
    private function start(Request $request, string $provider, string $redirectUri): RedirectResponse
    {
        $operator = $this->providers->find($provider);

        abort_if($operator === null, 404);

        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));

        // Keyed by redirect URI: sign-in and connect are separate flows that a person can
        // have open at once, and a single key would let the second overwrite the first.
        $request->session()->put($this->stashKey($operator, $redirectUri), [
            'state' => $state,
            'nonce' => $nonce,
        ]);

        try {
            $url = $this->flow->authorizeUrl($operator, $redirectUri, $state, $nonce);
        } catch (Throwable $e) {
            // Reached when the provider's discovery is unreachable or incomplete. Nothing
            // the person clicking can do about it, so say so plainly rather than sending
            // them to a provider that cannot complete.
            Log::warning('cbox-id: operator social redirect failed.', [
                'provider' => $operator->key(),
                'reason' => $e->getMessage(),
            ]);

            return redirect()->route('login')->with('error', 'Sign-in with '.$operator->label().' is unavailable right now.');
        }

        return redirect()->away($url);
    }

    /**
     * Complete the flow, or null when anything about it fails.
     *
     * Null rather than an exception because every caller here does the same thing with
     * it: send the person somewhere they can try again. The reason is logged rather than
     * shown — a federation failure's detail is for whoever runs the deployment.
     */
    private function resolve(Request $request, OperatorProvider $operator, string $redirectUri): ?FederatedPrincipal
    {
        // Pulled, not read: a replayed callback finds nothing stashed and fails closed.
        $stashed = $request->session()->pull($this->stashKey($operator, $redirectUri));

        $expectedState = is_array($stashed) && is_string($stashed['state'] ?? null) ? $stashed['state'] : null;
        $expectedNonce = is_array($stashed) && is_string($stashed['nonce'] ?? null) ? $stashed['nonce'] : null;

        $state = $request->string('state')->toString();
        $code = $request->string('code')->toString();

        if ($expectedState === null || $expectedNonce === null || $code === '' || ! hash_equals($expectedState, $state)) {
            return null;
        }

        try {
            return $this->flow->principal($operator, $code, $redirectUri, $expectedNonce);
        } catch (Throwable $e) {
            Log::warning('cbox-id: operator social sign-in rejected.', [
                'provider' => $operator->key(),
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Colons, not dots: a dotted session key is NESTED by Laravel, which would put every
     * provider's stash under one `social` parent that a single forget() could clear.
     */
    private function stashKey(OperatorProvider $operator, string $redirectUri): string
    {
        return 'social:'.$operator->key().':'.hash('sha256', $redirectUri);
    }
}
