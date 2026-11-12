<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Prop;
use App\Platform\CurrentUser;
use App\Platform\Surfaces\Surface;
use App\Platform\Surfaces\Surfaces;
use Cbox\Id\Identity\ValueObjects\Subject;

/**
 * WHO IS SIGNED IN — the subject, and nothing about the subject that a page has no
 * business drawing.
 *
 * Deliberately not the {@see Subject} itself. That object
 * carries the identity's whole state — status, credential hashes, MFA enrolment, the
 * organizations it can reach — and Inertia props travel to the browser in a `data-page`
 * attribute anybody can read. What the chrome needs is a name, an initial and an email,
 * so that is what crosses.
 */
final readonly class UserProps implements Prop
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $email,
        public bool $emailVerified,
        // Where "Send confirmation link" posts on this host; null where this host has no
        // account of the person's (an environment administrator on an environment host),
        // and the banner sends them to {@see $accountUrl} instead.
        public ?string $confirmUrl = null,
        public ?string $accountUrl = null,
    ) {}

    public static function from(CurrentUser $user): self
    {
        // In login-host mode the console has no account pages: the link is sent from the
        // person's account on the login host, which is where the banner leads.
        $onConsole = app(Surfaces::class)->is(Surface::Console);

        return new self(
            id: $user->id(),
            name: $user->name(),
            email: $user->email(),
            emailVerified: $user->emailVerified(),
            confirmUrl: $user->emailVerified() || $onConsole ? null : route('account.email.confirmation', absolute: false),
            accountUrl: ! $user->emailVerified() && $onConsole ? route('account') : null,
        );
    }

    /**
     * @return array{id: string, name: string, email: string|null, emailVerified: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'emailVerified' => $this->emailVerified,
            'confirmUrl' => $this->confirmUrl,
            'accountUrl' => $this->accountUrl,
        ];
    }
}
