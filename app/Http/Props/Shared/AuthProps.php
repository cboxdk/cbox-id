<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Prop;
use App\Platform\CurrentUser;

/**
 * The signed-in session, shared with every page.
 *
 * `user` is null for a guest, which is the whole of what a React page needs to ask before
 * drawing account chrome — there is no second question ("is the session valid?", "did it
 * expire?") because a request that got this far has already passed the middleware that
 * answers it.
 */
final readonly class AuthProps implements Prop
{
    public function __construct(
        public ?UserProps $user,
        public ?OrganizationProps $organization,
    ) {}

    public static function from(CurrentUser $user): self
    {
        if (! $user->check()) {
            return new self(user: null, organization: null);
        }

        $organization = $user->organization();

        return new self(
            user: UserProps::from($user),
            organization: $organization !== null
                ? OrganizationProps::from($organization, $user->role())
                : null,
        );
    }

    /**
     * @return array{user: UserProps|null, organization: OrganizationProps|null}
     */
    public function toArray(): array
    {
        return [
            'user' => $this->user,
            'organization' => $this->organization,
        ];
    }
}
