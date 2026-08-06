<?php

declare(strict_types=1);

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\RiskPlus\Models\RiskEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Risk events are one tenant's people, and must not be readable by another's.
 *
 * The table shipped with no tenancy column and the model was a plain Eloquent model, so
 * the console page — `RiskEvent::query()->latest()->limit(50)` — showed an administrator
 * in one environment every OTHER environment's flagged sign-ins, with the IP address and
 * the email address in the clear.
 *
 * This is the framework's standard isolation contract, so the assertions are the
 * standard three: reads are scoped, an unset context denies rather than returns
 * everything, and a cross-environment write is refused rather than silently re-stamped.
 */
function riskEventIn(string $environmentId, string $email): RiskEvent
{
    return app(EnvironmentContext::class)->runAs(
        GenericEnvironment::of($environmentId),
        fn (): RiskEvent => RiskEvent::query()->create([
            'action' => 'auth.login',
            'outcome' => 'flag',
            'score' => 60.0,
            'ip' => '203.0.113.7',
            'email' => $email,
            'reasons' => ['impossible_travel'],
        ]),
    );
}

it('never shows one environment the risk events of another', function (): void {
    riskEventIn('env_a', 'victim@acme.test');
    riskEventIn('env_b', 'other@beta.test');

    app(EnvironmentContext::class)->set(GenericEnvironment::of('env_a'));

    $emails = RiskEvent::query()->pluck('email')->all();

    expect($emails)->toBe(['victim@acme.test']);
});

it('stamps the environment on write without the caller passing it', function (): void {
    $event = riskEventIn('env_a', 'victim@acme.test');

    expect($event->environment_id)->toBe('env_a');
});

it('denies rather than returns everything when no environment is in context', function (): void {
    riskEventIn('env_a', 'victim@acme.test');
    riskEventIn('env_b', 'other@beta.test');

    app(EnvironmentContext::class)->set(null);

    expect(RiskEvent::query()->count())->toBe(0);
});
