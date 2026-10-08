<?php

declare(strict_types=1);

namespace App\Http\Props\Shared;

use App\Http\Props\Prop;

/**
 * "For which organization?" — the field a create form on the environment console carries
 * when what it creates belongs to one organization.
 *
 * It replaced a sentence. These forms used to send somebody to the console header to pick an
 * organization first — away from a form they had half filled in, to change a setting that
 * then silently retargeted every other page they had open. The organization is a field of
 * the thing being created, so it is asked for on the form.
 *
 * PREFILLED AND LOCKED when the form was opened from an organization's own page
 * (`?organization=` on the link the hub draws): the person already said which one, and a
 * field that let them change it there would be a second place to get it wrong. The server
 * does not trust the lock — the posted id is checked against this environment either way.
 */
final readonly class OrganizationPickerProps implements Prop
{
    /**
     * @param  array{id: string, name: string}|null  $selected
     */
    public function __construct(
        public string $lookupHref,
        public ?array $selected,
        public bool $locked,
        /** Whether "the whole environment" is a valid answer for what this form creates. */
        public bool $allowsEnvironment,
    ) {}

    /**
     * @return array{lookupHref: string, selected: array{id: string, name: string}|null, locked: bool, allowsEnvironment: bool}
     */
    public function toArray(): array
    {
        return [
            'lookupHref' => $this->lookupHref,
            'selected' => $this->selected,
            'locked' => $this->locked,
            'allowsEnvironment' => $this->allowsEnvironment,
        ];
    }
}
