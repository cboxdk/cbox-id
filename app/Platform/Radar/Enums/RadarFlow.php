<?php

declare(strict_types=1);

namespace App\Platform\Radar\Enums;

/**
 * Which door an attempt came through. A rule may apply to one or both.
 */
enum RadarFlow: string
{
    case SignIn = 'sign_in';
    case SignUp = 'sign_up';

    /** The risk scorer's action name for this flow — what `risk_decisions.action` holds. */
    public function riskAction(): string
    {
        return $this === self::SignUp ? 'register' : 'login';
    }

    public static function fromRiskAction(string $action): self
    {
        return $action === 'register' ? self::SignUp : self::SignIn;
    }
}
