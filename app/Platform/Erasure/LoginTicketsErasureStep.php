<?php

declare(strict_types=1);

namespace App\Platform\Erasure;

use App\Platform\FrontendApi\LoginTicket;
use Cbox\Id\Identity\Contracts\ErasureStep;
use Cbox\Id\Identity\ValueObjects\ErasureRequest;
use Cbox\Id\Identity\ValueObjects\ErasureStepResult;

/**
 * The Frontend API's sign-in tickets that name the person — redeemed or not.
 *
 * An unredeemed one is a credential check waiting to become a session, so leaving it would
 * let a page that was mid-sign-in finish signing in an erased account; a redeemed one is a
 * record of when and through which publishable key they signed in. Neither survives.
 */
final class LoginTicketsErasureStep implements ErasureStep
{
    public function name(): string
    {
        return 'app.frontend_login_tickets';
    }

    public function erase(ErasureRequest $request): ErasureStepResult
    {
        $deleted = LoginTicket::query()->where('subject_id', $request->subjectId)->toBase()->delete();

        return ErasureStepResult::of($this->name(), ['login_tickets' => $deleted]);
    }
}
