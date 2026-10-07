<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Locale\LocaleResolver;
use App\Platform\Locale\LocaleSource;
use App\Platform\Locale\MailLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * THE HOSTED SURFACES SPEAK THE VISITOR'S LANGUAGE; THE CONSOLE DOES NOT.
 *
 * On the route groups an END USER or a customer's IT admin reaches — sign-in, sign-up,
 * MFA, password reset, invitations, OAuth consent and the organization picker, the Admin
 * Portal — and deliberately nowhere else. The admin console is English: its copy is
 * dense, technical and changes weekly, and a half-translated console is worse than an
 * English one. Applied per route group rather than to `web` so that line is drawn where
 * the routes are, and a new console page cannot drift into Danish by accident.
 *
 * Runs BEFORE the controller, not as a view concern: validation messages, flash
 * statuses, page titles and the mail a request sends are all produced by PHP on the way
 * to the response, and every one of them has to be in the language the page is.
 *
 * {@see LocaleResolver} decides; this applies the answer, remembers an authorization's
 * `ui_locales` for the hops after it, and marks the request so a mail sent from it goes
 * out in the language the person was just reading ({@see MailLocale}).
 */
final readonly class ResolveLocale
{
    /** The request attribute that says a hosted request has chosen its language. */
    public const ATTRIBUTE = 'cbox.hosted_locale';

    public function __construct(private LocaleResolver $resolver) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $resolved = $this->resolver->resolve($request);

        // Remember the relying party's choice for the rest of this authorization: the
        // redirect to /login, the MFA prompt, the consent screen. Only when it named a
        // language we accepted — an unusable `ui_locales` must not wipe a good one an
        // earlier hop stored.
        if ($resolved->source === LocaleSource::UiLocales && $request->hasSession()) {
            $request->session()->put(LocaleResolver::SESSION_KEY, $resolved->locale->value);
        }

        app()->setLocale($resolved->locale->value);
        $request->attributes->set(self::ATTRIBUTE, $resolved->locale);

        $response = $next($request);

        // RFC 9110 §8.5: what language the representation is in. Assistive technology and
        // translation prompts read `<html lang>`; caches and crawlers read this.
        $response->headers->set('Content-Language', $resolved->locale->value);

        return $response;
    }
}
