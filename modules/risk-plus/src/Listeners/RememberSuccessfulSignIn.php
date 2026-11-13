<?php

declare(strict_types=1);

namespace Cbox\Id\RiskPlus\Listeners;

use App\Platform\Radar\Events\SignInSucceeded;
use Cbox\Id\RiskPlus\Signals\ImpossibleTravelSignal;
use Cbox\Id\RiskPlus\Signals\NewDeviceSignal;
use Cbox\Risk\ValueObjects\RiskContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Teaches the signals that remember an account's devices and whereabouts — from a sign-in
 * that SUCCEEDED, and from nothing else.
 *
 * Both used to learn inside their own scoring, which runs before the credential is
 * checked: a password-only attacker's first try taught the account their device and the
 * second walked past the new-device step-up. Fails open: remembering must never be what
 * breaks a sign-in.
 */
class RememberSuccessfulSignIn
{
    public function __construct(
        private readonly NewDeviceSignal $devices,
        private readonly ImpossibleTravelSignal $travel,
    ) {}

    public function handle(SignInSucceeded $event): void
    {
        $request = $event->request;
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = (string) ($values[0] ?? '');
        }

        $context = new RiskContext(
            action: 'login',
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            email: $event->email,
            headers: $headers,
        );

        try {
            $this->devices->remember($context);
            $this->travel->remember($context);
        } catch (Throwable $e) {
            Log::warning('risk-plus could not remember a successful sign-in', ['error' => $e->getMessage()]);
        }
    }
}
