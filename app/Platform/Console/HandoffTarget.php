<?php

declare(strict_types=1);

namespace App\Platform\Console;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * WHERE AN ENVIRONMENT HANDOFF LANDS — the page the context switcher asked for, or none.
 *
 * Switching environment from the topbar should keep you on the page you were reading:
 * Users in staging becomes Users in production. The switch is a cross-host handoff
 * (`/open/{environment}` mints a token, the environment's host redeems it), so the page
 * has to travel with it, and anything that travels through a redirect and comes back out
 * as a `Location` header is an open redirect waiting for one missed case.
 *
 * So the rule is an ALLOW-list, not a block-list of tricks. A target is accepted only if
 * it is:
 *
 *  - a plain absolute path made of `[A-Za-z0-9_-]` segments under `/admin`. That one
 *    pattern is what rules out `//evil.example`, `\\evil.example`, `https://…`, `/admin/..`,
 *    percent-encoded slashes, a query string, a fragment and whitespace — none of them can
 *    be spelled in it, so none of them has to be remembered;
 *  - a path this application routes, to a GET route named `environment.*` — the
 *    environment console's own pages, never the handoff door itself, the sign-out, or a
 *    page on another plane that happens to share the prefix.
 *
 * Anything else is null, and null lands on the environment's home — the behaviour before
 * the switcher carried a page at all. Refusing quietly is right here: the only way to
 * send a bad target is to hand-craft the URL, and there is nobody to show an error to.
 *
 * BOUND TO THE TOKEN, not merely validated. The framework's handoff contract mints a
 * token with fixed claims and has no room for another, so the target rides beside it with
 * an HMAC over the token and the path under the application key — the key both hosts of
 * one deployment share. A target swapped in transit fails the MAC and is dropped; the
 * validation above still runs on redemption, so a key compromise does not widen what a
 * target may be.
 */
final readonly class HandoffTarget
{
    /** Longest target accepted. Every console path is far shorter; this only bounds work. */
    private const MAX_LENGTH = 256;

    private const PATTERN = '#\A/admin(?:/[A-Za-z0-9_-]+)*\z#';

    public function __construct(private Router $router) {}

    /**
     * The target if it is one this console may land on, else null.
     */
    public function sanitize(mixed $to): ?string
    {
        if (! is_string($to) || $to === '' || strlen($to) > self::MAX_LENGTH) {
            return null;
        }

        if (preg_match(self::PATTERN, $to) !== 1) {
            return null;
        }

        $route = $this->match($to);

        if ($route === null || ! in_array('GET', $route->methods(), true)) {
            return null;
        }

        $name = $route->getName();

        return is_string($name) && str_starts_with($name, 'environment.') ? $to : null;
    }

    /** The signature that binds a target to the token it travels with. */
    public function sign(string $token, string $to): string
    {
        return hash_hmac('sha256', $token."\n".$to, $this->key());
    }

    /**
     * The target carried by a redemption, if it is valid AND was minted with this token.
     *
     * Both halves, in that order: the MAC proves the target was chosen by the minting side,
     * and the allow-list proves the minting side chose something allowed.
     */
    public function verify(string $token, mixed $to, mixed $signature): ?string
    {
        $to = $this->sanitize($to);

        if ($to === null || ! is_string($signature) || $signature === '') {
            return null;
        }

        return hash_equals($this->sign($token, $to), $signature) ? $to : null;
    }

    /**
     * Which route this path resolves to, asked of the router rather than guessed from the
     * route list: the same matcher a real request would meet, with no domain or method
     * surprises.
     */
    private function match(string $path): ?Route
    {
        try {
            return $this->router->getRoutes()->match(Request::create($path, 'GET'));
        } catch (HttpExceptionInterface) {
            return null;
        }
    }

    private function key(): string
    {
        $key = config('app.key');

        return is_string($key) ? $key : '';
    }
}
