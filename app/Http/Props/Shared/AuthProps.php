<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Prop;
use App\Platform\Console\ConsoleScope;
use App\Platform\CurrentUser;
use App\Platform\EnvironmentAdminAuth;
use Cbox\Id\Identity\Contracts\Subjects;
use Cbox\Id\Identity\ValueObjects\Subject;
use Cbox\Id\Platform\PlatformRoot;

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
            return self::environmentAdministrator();
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
     * THE PERSON ADMINISTERING AN ENVIRONMENT IS STILL A PERSON.
     *
     * {@see CurrentUser} is the SUBJECT session, and a subject belongs to one environment's
     * user pool — an account member administering a tenant's environment is not in it, so
     * `check()` is false there and this used to answer "guest". The layout reads `user` to
     * decide whether the console has a frame at all, so the whole environment plane rendered
     * with no rail, no sub-nav, no bottom bar, no account menu and no way to sign out. Every
     * request-level test passed: the pages were all 200 and their props were all correct.
     *
     * Resolved in the PLATFORM ROOT, because that is where account members live — the same
     * lookup {@see ConsoleScope::actorEmailVerified()} does, for the
     * same reason.
     */
    private static function environmentAdministrator(): self
    {
        $actorId = app(EnvironmentAdminAuth::class)->subjectId();

        if ($actorId === null) {
            return new self(user: null, organization: null);
        }

        $subject = app(PlatformRoot::class)->run(
            fn (): ?Subject => app(Subjects::class)->find($actorId),
        );

        if (! $subject instanceof Subject) {
            return new self(user: null, organization: null);
        }

        return new self(
            user: new UserProps(
                id: $subject->id,
                name: $subject->name ?? $subject->email ?? 'Administrator',
                email: $subject->email,
                emailVerified: $subject->emailVerified,
            ),
            // No organization: this person is acting on the ENVIRONMENT, and which of its
            // tenants they are acting for is a separate control in the chrome — see
            // {@see \App\Http\Props\Shell\ActingOrganizationProps}.
            organization: null,
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
