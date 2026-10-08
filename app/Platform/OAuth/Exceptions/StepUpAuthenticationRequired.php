<?php

declare(strict_types=1);

namespace App\Platform\OAuth\Exceptions;

use App\Platform\OAuth\ManagementStepUp;
use Cbox\Id\OAuthServer\ValueObjects\AuthenticationAssessment;
use RuntimeException;

/**
 * The token may do this, but not on the sign-in it was issued from: the deployment demands
 * a more recent or a stronger one before a Critical action ({@see ManagementStepUp}).
 *
 * Not an authorization refusal, and the doors must not answer it as one: a 403 tells the
 * client to give up, while RFC 9470 §3's `401 insufficient_user_authentication` tells it
 * exactly what to do — sign the person in again with the named `acr_values` / `max_age`
 * and retry the call. The REST door renders that challenge; `/mcp` answers it at the HTTP
 * layer before the call reaches a tool.
 */
final class StepUpAuthenticationRequired extends RuntimeException
{
    public function __construct(public readonly AuthenticationAssessment $assessment)
    {
        parent::__construct(($assessment->errorDescription() ?? 'A more recent or stronger sign-in is required.')
            .' Sign in again'.ManagementStepUp::describe($assessment->requirement).' and repeat the request.');
    }
}
