<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\Rules\PasswordMeetsPolicy;
use Cbox\Id\Organization\Contracts\Invitations;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The password an invitee chooses on the way in.
 *
 * ASKED OF THE SUBJECT the password will belong to, so its reuse history applies
 * alongside the tenant's length and breach rules. A first-time invitee has no subject
 * yet, and the rule handles that — but somebody already known here, invited into a second
 * organization, is held to their own history.
 */
final class AcceptInvitationRequest extends FormRequest
{
    /**
     * The signature on this URL is the authorization, and the route's `signed` middleware
     * has already checked it.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:200', PasswordMeetsPolicy::for($this->subjectId())],
        ];
    }

    /**
     * The subject this password will belong to, if they already exist here.
     *
     * Resolved from the INVITATION rather than from anything submitted: the address is
     * the invitation's, not the form's, and a submitted one would let somebody aim the
     * reuse check at another account.
     */
    private function subjectId(): ?string
    {
        $token = (string) $this->route('token');

        return app(PlatformRoot::class)->run(function () use ($token): ?string {
            $invitation = app(Invitations::class)->byToken($token);

            if ($invitation === null) {
                return null;
            }

            return app(Subjects::class)->findByEmail((string) $invitation->email)?->id;
        });
    }

    public function password(): string
    {
        return (string) $this->string('password');
    }
}
