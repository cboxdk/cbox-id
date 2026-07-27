<?php

declare(strict_types=1);

use Cbox\Id\RiskPlus\Models\RiskEvent;
use Cbox\Risk\Enums\Outcome;
use Cbox\Risk\Events\RiskAssessed;
use Cbox\Risk\ValueObjects\RiskAssessment;
use Cbox\Risk\ValueObjects\RiskContext;
use Cbox\Risk\ValueObjects\SignalResult;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function assess(float $score, Outcome $outcome): RiskAssessment
{
    return new RiskAssessment($score, $outcome, [
        new SignalResult('device.new', $score, 'sign-in from a new device'),
    ]);
}

it('records an elevated assessment for the console to review', function (): void {
    event(new RiskAssessed(
        new RiskContext(action: 'login', ip: '1.1.1.1', email: 'user@example.com'),
        assess(60.0, Outcome::StepUp),
        'enforce',
    ));

    $event = RiskEvent::query()->sole();

    expect($event->action)->toBe('login')
        ->and($event->outcome)->toBe('step_up')
        ->and($event->score)->toBe(60.0)
        ->and($event->email)->toBe('user@example.com')
        ->and($event->reasons)->toBe(['sign-in from a new device']);
});

it('does not record a plain allow', function (): void {
    event(new RiskAssessed(
        new RiskContext(action: 'login', ip: '1.1.1.1'),
        assess(0.0, Outcome::Allow),
        'monitor',
    ));

    expect(RiskEvent::query()->count())->toBe(0);
});
