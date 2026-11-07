<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Locale\ConsolePreferences;
use App\Platform\Locale\HostedLocale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ResolveConsoleLocale
{
    public const ATTRIBUTE = 'cbox.console_preferences';

    public function __construct(private ConsolePreferences $preferences) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $snapshot = $this->preferences->read();
        $language = $snapshot->data->language ?? null;
        $locale = HostedLocale::fromTag(is_string($language) ? $language : null);
        if ($locale === null) {
            foreach ($request->getLanguages() as $tag) {
                $locale = HostedLocale::fromTag($tag);
                if ($locale !== null) {
                    break;
                }
            }
        }
        $default = config('cbox-id.locales.default');
        $locale ??= HostedLocale::fromTag(is_string($default) ? $default : null) ?? HostedLocale::English;
        app()->setLocale($locale->value);
        $request->attributes->set(ResolveLocale::ATTRIBUTE, $locale);
        $request->attributes->set(self::ATTRIBUTE, $snapshot);
        $response = $next($request);
        $response->headers->set('Content-Language', $locale->value);

        return $response;
    }
}
