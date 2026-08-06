<?php

declare(strict_types=1);

use Cbox\Id\Kernel\Tenancy\Contracts\EnvironmentContext;
use Cbox\Id\Kernel\Tenancy\GenericEnvironment;
use Cbox\Id\RiskPlus\Signals\NewDeviceSignal;
use Cbox\Id\RiskPlus\Support\SubjectKey;
use Cbox\Risk\ValueObjects\RiskContext;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

function deviceSignal(): NewDeviceSignal
{
    return new NewDeviceSignal(new SubjectKey('test-secret'), new Repository(new ArrayStore));
}

function signInWith(string $userAgent): RiskContext
{
    return new RiskContext(
        action: 'login',
        userAgent: $userAgent,
        email: 'user@example.com',
        headers: ['accept-language' => 'en-US'],
    );
}

it('treats the first device as enrolment, not a new-device event', function (): void {
    expect(deviceSignal()->evaluate(signInWith('Mozilla/5.0 (Macintosh)')))->toBeNull();
});

it('fires on a device the account has not been seen on before', function (): void {
    $signal = deviceSignal();

    $signal->evaluate(signInWith('Mozilla/5.0 (Macintosh)'));       // enrolment
    $result = $signal->evaluate(signInWith('Mozilla/5.0 (Windows)')); // new device

    expect($result)->not->toBeNull()
        ->and($result->key)->toBe('device.new')
        ->and($result->points)->toBe(35.0);
});

it('stays quiet on a device it has already seen', function (): void {
    $signal = deviceSignal();

    $signal->evaluate(signInWith('Mozilla/5.0 (Macintosh)'));
    $signal->evaluate(signInWith('Mozilla/5.0 (Windows)'));

    expect($signal->evaluate(signInWith('Mozilla/5.0 (Macintosh)')))->toBeNull();
});

it('is inert without a subject to key on', function (): void {
    $anonymous = new RiskContext(action: 'login', userAgent: 'Mozilla/5.0');

    expect(deviceSignal()->evaluate($anonymous))->toBeNull();
});

/**
 * One tenant must not be able to write into another tenant's device history.
 *
 * The key was `HMAC(app.key, email)` — and `app.key` is one value for the whole
 * deployment, while the assessment runs BEFORE authentication on an email the caller
 * supplies. So an unauthenticated attacker on their own free-trial environment could
 * seed their fingerprint into any address's set: the victim's real tenant then stopped
 * scoring `device.new` and stopped demanding a step-up. Filling the capped set evicts
 * every genuine device instead, and the differing outcomes are a cross-tenant oracle for
 * whether an account exists at all.
 *
 * A shared cache store across both halves on purpose — the environment must be what
 * separates them, not the store.
 */
it('keeps device history separate between environments', function (): void {
    $store = new Repository(new ArrayStore);
    $signal = new NewDeviceSignal(new SubjectKey('test-secret'), $store);
    $context = app(EnvironmentContext::class);

    // The attacker, on their own tenant, teaches the shared history their device.
    $context->runAs(GenericEnvironment::of('env_attacker'), function () use ($signal): void {
        $signal->evaluate(signInWith('Mozilla/5.0 (AttackerBox)'));
    });

    // On the victim's tenant: their own device enrols first, and then the attacker's
    // device appears. It must score as new THERE, whatever the attacker did elsewhere.
    //
    // Asserting on the attacker's exact fingerprint is what makes this a real test. An
    // earlier version checked that some LATER device scored, which it does either way —
    // the discriminator has to be the one device the attacker was trying to pre-approve.
    $victim = $context->runAs(GenericEnvironment::of('env_victim'), function () use ($signal) {
        $signal->evaluate(signInWith('Mozilla/5.0 (VictimLaptop)'));

        return $signal->evaluate(signInWith('Mozilla/5.0 (AttackerBox)'));
    });

    expect($victim)->not->toBeNull('an attacker pre-approved their device on another tenant history')
        ->and($victim->key)->toBe('device.new');
});
