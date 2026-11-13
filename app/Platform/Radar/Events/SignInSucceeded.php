<?php

declare(strict_types=1);

namespace App\Platform\Radar\Events;

use App\Platform\Radar\Radar;
use Illuminate\Http\Request;

/**
 * A sign-in SUCCEEDED: every factor it was asked for, including any step-up, is done and
 * a session exists ({@see Radar::succeeded()}).
 *
 * The moment for anything that LEARNS from a sign-in — a device to remember, a place it
 * was made from — to learn. Learning while the attempt is still being scored, before the
 * credential is even checked, lets whoever merely tries teach the account their device:
 * the second try from the same browser then scored as a device already seen.
 */
final readonly class SignInSucceeded
{
    public function __construct(
        public Request $request,
        public string $subjectId,
        public string $email,
    ) {}
}
