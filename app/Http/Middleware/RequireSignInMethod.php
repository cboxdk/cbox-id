<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Cbox\Id\Identity\Contracts\SignInMethods;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A door that exists only while its sign-in method is on HERE — passkeys or magic links —
 * as the environment's authentication policy and the deployment's ceiling decide together
 * ({@see SignInMethods}).
 *
 * The credential primitives refuse on their own as well; this answers first, in the shape
 * each kind of door needs. The JSON doors (the WebAuthn ceremonies, the embedded sign-in)
 * get a 403 with the sentence the page shows under its button. A browser arriving at a
 * page — an emailed link clicked after links were switched off, a form posted from a page
 * rendered before — is sent to the sign-in page with that sentence, rather than to an
 * error page about a method nobody told them was gone.
 *
 * Usage: `RequireSignInMethod::class.':passkeys'` or `…':magic_link'`.
 */
final class RequireSignInMethod
{
    public function __construct(private readonly SignInMethods $methods) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $method): Response
    {
        $enabled = match ($method) {
            'passkeys' => $this->methods->passkeysEnabled(),
            'magic_link' => $this->methods->magicLinkEnabled(),
            default => throw new \InvalidArgumentException("Unknown sign-in method [{$method}]."),
        };

        if ($enabled) {
            return $next($request);
        }

        $message = __('auth.login.method_off.'.$method);

        if ($request->expectsJson() || $request->isJson() || $request->is('passkeys/*', 'frontend/*')) {
            return new JsonResponse(['error' => $message], 403);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
