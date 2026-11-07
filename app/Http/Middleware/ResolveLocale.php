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
 * Language resolution for hosted sign-in, MFA, invitations, consent and Admin Portal.
 * These routes follow the visitor and environment preferences. Authenticated console
 * routes use ResolveConsoleLocale and the subject's shared language preference instead.
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
