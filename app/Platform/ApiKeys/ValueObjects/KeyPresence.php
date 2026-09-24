<?php

declare(strict_types=1);

namespace App\Platform\ApiKeys\ValueObjects;

/**
 * Whether API keys are a thing in one organization, for one person — what the rail needs
 * to decide whether either key page is worth a link.
 */
final readonly class KeyPresence
{
    public function __construct(
        /** An app offers keys to this organization. */
        public bool $offered = false,
        /** Somebody in the organization holds a key, live or not. */
        public bool $inOrganization = false,
        /** This person holds a key there, live or not. */
        public bool $held = false,
    ) {}

    /**
     * The holder's page: an app offers keys, or they already hold one. The second half
     * matters — an app that stops offering keys leaves the ones already issued working, and
     * their holder still needs a way to find and revoke them.
     */
    public function worthHolderPage(): bool
    {
        return $this->offered || $this->held;
    }

    /** The administrators' page: an app offers keys here, or somebody already holds one. */
    public function worthAdminPage(): bool
    {
        return $this->offered || $this->inOrganization;
    }
}
