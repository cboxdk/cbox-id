<?php

declare(strict_types=1);

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

it('admits Stripe only in the billing document without loosening inline scripts or tenant image policies', function (): void {
    $policy = function (string $route): string {
        $request = Request::create('/orgs/acme/settings/billing');
        $request->setRouteResolver(fn () => (new Route('GET', '/orgs/acme/settings/billing', fn () => null))->name($route));

        return app(SecurityHeaders::class)->handle($request, fn () => response('billing'))->headers->get('Content-Security-Policy');
    };

    config(['billing-client.api_token' => null]);
    expect($policy('orgs.settings.billing'))->not->toContain('stripe.com')
        ->and($policy('orgs.settings.billing'))->not->toContain('link.com');
    config(['billing-client.api_token' => 'test-configured']);
    $billing = $policy('orgs.settings.billing');
    expect($billing)->toContain('https://js.stripe.com', 'https://api.stripe.com', 'https://hooks.stripe.com')
        ->and($billing)->not->toContain("script-src 'self' 'unsafe-inline'")
        ->and($billing)->not->toContain("'unsafe-eval'")
        ->and($billing)->not->toContain('img-src https:')
        ->and($policy('login'))->not->toContain('stripe.com')
        ->and($policy('login'))->not->toContain('link.com')
        ->and($policy('account'))->not->toContain('stripe.com')
        ->and($policy('account'))->not->toContain('link.com');
});
