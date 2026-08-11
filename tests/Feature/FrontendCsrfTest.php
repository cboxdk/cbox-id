<?php

declare(strict_types=1);

use App\Http\Middleware\Authenticate;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * The patterns the framework actually verifies against, read from the static it fills at
 * bootstrap rather than from a resolved instance.
 *
 * @return list<string>
 */
function csrfExemptPatterns(): array
{
    $property = new ReflectionProperty(ValidateCsrfToken::class, 'neverVerify');
    $property->setAccessible(true);

    /** @var list<string> $patterns */
    $patterns = $property->getValue();

    return $patterns;
}

/**
 * THE ENDPOINT MUST SURVIVE THE REAL MIDDLEWARE STACK.
 *
 * `/frontend/v1/sign-in` is a deliberate cross-origin POST from a page on somebody else's
 * site. It carries no Laravel CSRF token and never could — and in production it answered
 * 419 while every test in the suite was green, because Laravel's test helpers disable CSRF
 * verification. The whole feature was unusable and nothing said so.
 *
 * So this asserts on the CONFIGURATION rather than on a request: whether the route is
 * exempt is a fact about `bootstrap/app.php`, and a test that sends a request through
 * Laravel's testing stack would be lying to itself the same way the last one did.
 *
 * CSRF is the wrong control here regardless. The attack it prevents is a request made with
 * a victim's ambient session; this one authenticates by the credentials in its body, and
 * what decides whether a caller may make it at all is the publishable key plus the origin
 * allow-list — a check no forged cross-site form can pass.
 */
it('exempts the browser-facing channel from CSRF, or it cannot be called at all', function (): void {
    // Read from the STATIC the framework fills at bootstrap. A freshly resolved instance
    // has an empty `except` — the exclusions never touch it — so asserting there would
    // pass on a deployment that verifies every one of these.
    expect(csrfExemptPatterns())->toContain('frontend/v1/*');
});

/**
 * And the mirror, so the exemption stays as narrow as it is: nothing that relies on an
 * ambient session may join it. A page that is signed in HERE and posts to one of these
 * without a token is exactly what CSRF is for.
 */
it('exempts nothing that authenticates by session alone', function (): void {
    $except = csrfExemptPatterns();

    $sessionAuthenticated = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! in_array('POST', $route->methods(), true)) {
            continue;
        }

        $isExempt = collect($except)->contains(
            fn (string $pattern): bool => Str::is(trim($pattern, '/'), $route->uri()),
        );

        if (! $isExempt) {
            continue;
        }

        // The console's own `Authenticate` — matched as a CLASS, not as a substring:
        // `AuthenticateFrontendApi` contains the word and is the opposite thing, a door
        // opened by a publishable key rather than by an ambient session.
        if (collect($route->gatherMiddleware())->contains(Authenticate::class)) {
            $sessionAuthenticated[] = $route->uri();
        }
    }

    expect($sessionAuthenticated)->toBe([]);
});
