<?php

declare(strict_types=1);

namespace App\Platform\ConsoleSignIn;

use App\Platform\Organizations\OrganizationDirectory;
use Illuminate\Http\Request;

/**
 * `?organization=` ON THE WAY INTO THE CONSOLE: the app switcher of every Cbox application
 * links here with the organization the person is working in, and the console opens that
 * organization (`/orgs/{slug}`) instead of the one this browser used last.
 *
 * The value is an organization's id or its slug. It is honoured only for an organization
 * the signed-in person is an active member of; anything else is ignored, and the person
 * lands where they would have without it.
 */
final readonly class OrganizationHint
{
    public const string PARAMETER = 'organization';

    public function __construct(private OrganizationDirectory $directory) {}

    /** The hint a request carries, or null. */
    public static function from(Request $request): ?string
    {
        $value = $request->query(self::PARAMETER);

        return is_string($value) && $value !== '' && strlen($value) <= 128 ? $value : null;
    }

    /** The organization's front door, or null when the hint names none of the person's. */
    public function landing(string $subjectId, string $hint): ?string
    {
        foreach ($this->directory->forSubject($subjectId) as $entry) {
            if ($entry->id === $hint || $entry->slug === $hint) {
                return route('orgs.show', ['org' => $entry->slug]);
            }
        }

        return null;
    }
}
