<?php

declare(strict_types=1);

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Otp\Contracts\OtpChannels;
use Cbox\Id\Otp\Testing\FakeOtpChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * THE NEW-DEVICE STEP-UP HOLDS ON EVERY TRY, NOT ONLY THE FIRST.
 *
 * risk-plus's device.new learned the browser while scoring the attempt, before the password
 * was even checked, so the second submission from the same browser scored as a device
 * already seen: an attacker holding only the password passed the emailed code by
 * submitting twice, and could pre-enrol their browser with a wrong password.
 */
const NEW_DEVICE_PASSWORD = 'a-strong-password-1234';

beforeEach(function (): void {
    config(['risk.mode' => 'enforce']);
    Cache::flush();
    $this->channel = new FakeOtpChannel;
    app(OtpChannels::class)->register('email', $this->channel);
    app(Subjects::class)->create('owner@device.test', 'Owner', NEW_DEVICE_PASSWORD);
});

function deviceAttempt(string $userAgent, string $password = NEW_DEVICE_PASSWORD): TestResponse
{
    test()->flushSession();

    return test()->withServerVariables(['HTTP_USER_AGENT' => $userAgent])
        ->from(route('login'))
        ->post(route('login.attempt'), ['email' => 'owner@device.test', 'password' => $password, 'identified' => true]);
}

it('asks a new browser for the emailed code however often it submits the right password', function (): void {
    deviceAttempt('Mozilla/5.0 (Macintosh; Owner)')->assertRedirect(route('dashboard'));

    deviceAttempt('Mozilla/5.0 (X11; Attacker)')->assertRedirect(route('login.step-up'));
    expect(session()->has('cbox.session'))->toBeFalse();

    deviceAttempt('Mozilla/5.0 (X11; Attacker)')->assertRedirect(route('login.step-up'));
    expect(session()->has('cbox.session'))->toBeFalse();
});

it('does not let a wrong password enrol a browser for the next try', function (): void {
    deviceAttempt('Mozilla/5.0 (Macintosh; Owner)')->assertRedirect(route('dashboard'));

    deviceAttempt('Mozilla/5.0 (X11; Attacker)', 'not-the-password');

    deviceAttempt('Mozilla/5.0 (X11; Attacker)')->assertRedirect(route('login.step-up'));
});

it('remembers a new browser once its sign-in passed the step-up', function (): void {
    deviceAttempt('Mozilla/5.0 (Macintosh; Owner)')->assertRedirect(route('dashboard'));

    deviceAttempt('Mozilla/5.0 (Windows; New laptop)')->assertRedirect(route('login.step-up'));
    test()->withServerVariables(['HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows; New laptop)'])
        ->from(route('login.step-up'))
        ->post(route('login.step-up.verify'), ['code' => (string) $this->channel->codeFor('owner@device.test')])
        ->assertRedirect(route('dashboard'));

    deviceAttempt('Mozilla/5.0 (Windows; New laptop)')->assertRedirect(route('dashboard'));
});
