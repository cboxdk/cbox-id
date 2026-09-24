<?php

declare(strict_types=1);

namespace App\Platform\OAuth;

use App\Platform\OAuth\Enums\AuthorizationPrompt;
use App\Platform\SignupPolicy;

/**
 * `prompt_values_supported` — what `/oauth/authorize` does with `prompt`, stated in the
 * discovery document (OpenID Connect Prompt Create 1.0 §4 defines the key).
 *
 * The framework builds the document and knows nothing of prompts: `/authorize` is this
 * application's endpoint, so the claim about it is added here, from the same enum the
 * endpoint parses and the same {@see SignupPolicy} it asks. The two values that create
 * something are listed only where this environment offers them, because a client reads
 * the list to decide whether to show a "Sign up" button, and a button that leads to
 * `invalid_request` is worse than none.
 */
final readonly class SupportedPrompts
{
    public function __construct(private SignupPolicy $signup) {}

    /** @return list<string> */
    public function values(): array
    {
        $values = [];

        foreach (AuthorizationPrompt::cases() as $prompt) {
            $offered = match ($prompt) {
                AuthorizationPrompt::Create => $this->signup->isOpen(),
                AuthorizationPrompt::CreateOrganization => $this->signup->allowsCreatingOrganizations(),
                default => true,
            };

            if ($offered) {
                $values[] = $prompt->value;
            }
        }

        return $values;
    }

    /**
     * The framework's document with the prompt values added — only where it advertises an
     * authorization endpoint. A document with no `/authorize` has nothing to prompt.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function describe(array $document): array
    {
        if (! isset($document['authorization_endpoint'])) {
            return $document;
        }

        return [...$document, 'prompt_values_supported' => $this->values()];
    }
}
