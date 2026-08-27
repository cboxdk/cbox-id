<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Prop;
use App\Platform\CurrentUser;
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
    ) {}

    public static function from(CurrentUser $user): self
    {
        return new self(
            id: $user->id(),
            name: $user->name(),
            email: $user->email(),
            emailVerified: $user->emailVerified(),
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
        ];
    }
}
