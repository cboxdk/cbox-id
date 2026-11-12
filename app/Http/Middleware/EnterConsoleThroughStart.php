<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\Surfaces\ConsoleEntry;
use App\Platform\Surfaces\Surface;
use App\Platform\Surfaces\Surfaces;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * IN LOGIN-HOST MODE, THE LOGIN HOST SENDS NOBODY STRAIGHT TO A CONSOLE PAGE.
 *
 * A sign-in, a sign-up, an accepted invitation, an added or switched account, a personal
 * organization: each ends on the login host with "and now the console" (`route('dashboard')`
 * and its kin, which name the app host there). The console holds a session of its own for
 * one account, so a redirect straight to its page shows whoever it held before, or nobody.
 *
 * So a redirect from the login host to a page on the app host is sent through the
 * console's start instead ({@see ConsoleEntry}), carrying the page as `return_to`: the
 * console asks the login host who is active now, and then lands there. One place, so no
 * controller that ends a flow has to know the console is somewhere else.
 *
 * The JSON answers that carry a `redirect` (the passkey sign-in, which the page follows
 * itself) are treated the same. Everything else passes untouched: every request off the
 * login host, and the protocol endpoints, whose redirects go to a client's registered URI.
 */
final class EnterConsoleThroughStart
{
    /**
     * The protocol endpoints answer a CLIENT, at the redirect URI it registered: the
     * console's own callback among them, and any first-party client that lives on the app
     * host. Those redirects are the protocol's and are never rewritten.
     *
     * @var list<string>
     */
    private const array PROTOCOL = ['oauth/*', 'sso/*', 'device', 'device/*', 'frontend/v1/*', '.well-known/*'];

    public function __construct(
        private readonly Surfaces $surfaces,
        private readonly ConsoleEntry $entry,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->surfaces->current($request) !== Surface::Login || $request->is(...self::PROTOCOL)) {
            return $response;
        }

        if ($response instanceof RedirectResponse) {
            $through = $this->entry->through($response->getTargetUrl());

            if ($through !== null) {
                $response->setTargetUrl($through);
            }

            return $response;
        }

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);

            if (is_array($data) && is_string($data['redirect'] ?? null) && ($through = $this->entry->through($data['redirect'])) !== null) {
                $response->setData([...$data, 'redirect' => $through]);
            }
        }

        return $response;
    }
}
