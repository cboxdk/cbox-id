<?php

declare(strict_types=1);

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
