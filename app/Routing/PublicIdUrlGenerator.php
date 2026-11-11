<?php

declare(strict_types=1);

namespace App\Routing;

use App\Http\Middleware\AcceptPublicIds;
use App\Platform\PublicIds\PublicId;
use App\Platform\PublicIds\RouteIds;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteUrlGenerator;
use Illuminate\Routing\UrlGenerator;

/**
 * THE CONSOLE'S URLS CARRY PUBLIC IDS: `route('environment.users.show', $user->id)` is
 * `…/users/usr_01j…`, wherever in the application the URL is built (plan §3.2, the URL
 * standard). Which parameter is which kind of record is {@see RouteIds}; protocol URLs — an
 * SSO callback, an ACS URL, a one-time token — keep their shape.
 *
 * Reading the URL back is {@see AcceptPublicIds}, which accepts both
 * forms, so a bookmark from before this change still opens.
 */
final class PublicIdUrlGenerator extends UrlGenerator
{
    /** The same generator, with every resolver and default it already had. */
    public static function from(UrlGenerator $url): self
    {
        $generator = new self($url->routes, $url->request, $url->assetRoot);

        foreach (get_object_vars($url) as $property => $value) {
            $generator->{$property} = $value;
        }

        // The cached route generator points back at the generator it was made for: start a
        // new one, carrying the defaults the old one held.
        $generator->routeGenerator = null;
        $generator->defaults($url->getDefaultParameters());

        return $generator;
    }

    /**
     * @param  Route  $route
     * @param  mixed  $parameters
     * @param  bool  $absolute
     */
    public function toRoute($route, $parameters, $absolute)
    {
        return parent::toRoute($route, $this->publicParameters($route, $parameters), $absolute);
    }

    /**
     * The parameters with each record id in its public form, matched to the route's own
     * names the way {@see RouteUrlGenerator} will match them: named
     * ones by name, then the positional ones in order over the names still open.
     */
    private function publicParameters(Route $route, mixed $parameters): mixed
    {
        if (! is_array($parameters)) {
            $parameters = [$parameters];
        }

        $names = $route->parameterNames();

        if ($names === []) {
            return $parameters;
        }

        $defaults = $this->getDefaultParameters();
        $open = array_values(array_filter(
            $names,
            static fn (mixed $name): bool => is_string($name) && ! (isset($parameters[$name]) && $parameters[$name] !== '') && ! isset($defaults[$name]),
        ));

        $position = 0;

        foreach ($parameters as $key => $value) {
            $name = is_int($key) ? ($open[$position++] ?? null) : $key;

            if (! is_string($name) || ! in_array($name, $names, true)) {
                continue;
            }

            if ($value instanceof UrlRoutable) {
                $value = $value->getRouteKey();
            }

            if (! is_string($value)) {
                continue;
            }

            $public = PublicId::encodeString(RouteIds::typeFor($route, $name), $value);

            if ($public !== $value) {
                $parameters[$key] = $public;
            }
        }

        // A parameter the URL defaults fill (the console's `{organization}` while a page of
        // one organization renders) is a URL like any other: in its public form.
        foreach ($names as $name) {
            if (! is_string($name) || array_key_exists($name, $parameters)) {
                continue;
            }

            $default = $defaults[$name] ?? null;

            if (! is_string($default)) {
                continue;
            }

            $public = PublicId::encodeString(RouteIds::typeFor($route, $name), $default);

            if ($public !== $default) {
                $parameters[$name] = $public;
            }
        }

        return $parameters;
    }
}
