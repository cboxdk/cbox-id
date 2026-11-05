<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Locale\HostedLocale;
use App\Platform\Locale\LocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Console preference is independent of a customer's hosted-language policy. */
final class ResolveConsoleLocale
{
    public const COOKIE = 'cbox_console_locale';

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $candidates = [$request->cookie(self::COOKIE), $request->cookie(LocaleResolver::COOKIE), ...$request->getLanguages()];
        $locale = HostedLocale::English;

        foreach ($candidates as $candidate) {
            $accepted = HostedLocale::fromTag(is_string($candidate) ? $candidate : null);

            if ($accepted !== null) {
                $locale = $accepted;
                break;
            }
        }

        app()->setLocale($locale->value);
        $response = $next($request);

        // Hosted routes resolve their own language later in the middleware stack.
        if (! $response->headers->has('Content-Language')) {
            $response->headers->set('Content-Language', $locale->value);
        }

        return $response;
    }
}
