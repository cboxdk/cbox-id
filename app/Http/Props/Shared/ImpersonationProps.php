<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Middleware\EnforceImpersonationWindow;
use App\Http\Props\Prop;
use App\Platform\Impersonation;
use Cbox\Id\Identity\Contracts\Subjects;

/**
 * A live support impersonation, and the countdown on it.
 *
 * Shared rather than rendered per page, and that is the whole point of it being here: the
 * banner is the only exit control there is, and it was once carried by one of the two
 * blade layouts — so an operator who started an impersonation from a page on the OTHER
 * layout got no banner and no way back out. A shared prop cannot be forgotten by a page,
 * because a page does not choose it.
 *
 * `expiresInSeconds` is sent so the React shell can count the 30-minute box down in view.
 * It is a display of the server's decision, never the enforcement of it — the window is
 * closed by {@see EnforceImpersonationWindow} on the request, and a
 * browser that ignores this number gains nothing.
 */
final readonly class ImpersonationProps implements Prop
{
    public function __construct(
        public string $subject,
        public ?string $email,
        public ?string $reason,
        public int $expiresInSeconds,
    ) {}

    public static function from(Impersonation $impersonation, Subjects $subjects): ?self
    {
        $active = $impersonation->active();

        if ($active === null) {
            return null;
        }

        return new self(
            subject: $active->subject,
            email: $subjects->find($active->subject)?->email,
            reason: $active->reason,
            expiresInSeconds: $impersonation->expiresInSeconds(),
        );
    }

    /**
     * @return array{subject: string, email: string|null, reason: string|null, expiresInSeconds: int}
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'email' => $this->email,
            'reason' => $this->reason,
            'expiresInSeconds' => $this->expiresInSeconds,
        ];
    }
}
