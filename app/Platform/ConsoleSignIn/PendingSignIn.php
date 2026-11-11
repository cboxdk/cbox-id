<?php

declare(strict_types=1);

namespace App\Platform\ConsoleSignIn;

use App\Platform\StepUpPlane;

/**
 * One authorization request the console sent a browser away with, kept in the console's
 * own session until the browser comes back with its `state`.
 */
final readonly class PendingSignIn
{
    public function __construct(
        public string $verifier,
        public string $nonce,
        public int $startedAt,
        /** The step-up this request is for, or null for a plain sign-in. */
        public ?StepUpPlane $plane = null,
        /** The `acr` the step-up must reach, or null when freshness is all it asks. */
        public ?string $acr = null,
        /** How old the authentication may be, in seconds, for the step-up to count. */
        public ?int $maxAge = null,
        /** The environment an environment-plane step-up was asked in. */
        public ?string $environment = null,
        /** Opened from the in-place dialog: the answer is a page that closes itself. */
        public bool $popup = false,
        /** The organization the request asked to be signed in to, if it named one. */
        public ?string $organization = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'v' => $this->verifier,
            'n' => $this->nonce,
            'at' => $this->startedAt,
            'plane' => $this->plane?->value,
            'acr' => $this->acr,
            'max_age' => $this->maxAge,
            'environment' => $this->environment,
            'popup' => $this->popup,
            'organization' => $this->organization,
        ];
    }

    public static function fromArray(mixed $held): ?self
    {
        if (! is_array($held) || ! is_string($held['v'] ?? null) || ! is_string($held['n'] ?? null) || ! is_int($held['at'] ?? null)) {
            return null;
        }

        return new self(
            verifier: $held['v'],
            nonce: $held['n'],
            startedAt: $held['at'],
            plane: is_string($held['plane'] ?? null) ? StepUpPlane::tryFrom($held['plane']) : null,
            acr: is_string($held['acr'] ?? null) ? $held['acr'] : null,
            maxAge: is_int($held['max_age'] ?? null) ? $held['max_age'] : null,
            environment: is_string($held['environment'] ?? null) ? $held['environment'] : null,
            popup: ($held['popup'] ?? false) === true,
            organization: is_string($held['organization'] ?? null) ? $held['organization'] : null,
        );
    }
}
