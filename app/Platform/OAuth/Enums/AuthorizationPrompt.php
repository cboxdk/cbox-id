<?php

declare(strict_types=1);

namespace App\Platform\OAuth\Enums;

/**
 * The `prompt` values `/oauth/authorize` honours.
 *
 * The first four are OIDC Core §3.1.2.1, `create` is OpenID Connect Prompt Create 1.0
 * (Initiating User Registration), and the two organization steps are this product's own.
 * Discovery advertises exactly this set as `prompt_values_supported` — minus the two that
 * create something, when the environment does not let people sign themselves up — so the
 * document and the endpoint cannot disagree about what is supported.
 *
 * A value not listed here is IGNORED rather than refused, which is what the endpoint has
 * always done: OIDC Core leaves unknown values to the server, and refusing them would break
 * every relying party that sends a vendor value meant for another provider.
 */
enum AuthorizationPrompt: string
{
    /** No interaction at all; anything that would need one is an error to the client. */
    case None = 'none';

    /** Re-authenticate, by adding (or re-proving) an account on this browser. */
    case Login = 'login';

    /** Show the consent screen, even to a first-party app that would otherwise skip it. */
    case Consent = 'consent';

    /** Choose which of the accounts signed in on this browser answers. */
    case SelectAccount = 'select_account';

    /** Always show the hosted organization picker. */
    case SelectOrganization = 'select_organization';

    /** The hosted "create an organization" step; the grant is bound to the new one. */
    case CreateOrganization = 'create_organization';

    /** OIDC Prompt Create: show the sign-up form rather than the sign-in form. */
    case Create = 'create';

    /**
     * The recognised values in a `prompt` parameter, de-duplicated, in the order sent.
     *
     * @return list<self>
     */
    public static function parse(mixed $raw): array
    {
        if (! is_string($raw)) {
            return [];
        }

        $prompts = [];

        foreach (preg_split('/\s+/', trim($raw)) ?: [] as $value) {
            $prompt = self::tryFrom($value);

            if ($prompt !== null && ! in_array($prompt, $prompts, true)) {
                $prompts[] = $prompt;
            }
        }

        return $prompts;
    }

    /**
     * Whether this value starts something new — an account or an organization — and so is
     * only offered where the environment lets people sign themselves up.
     */
    public function createsSomething(): bool
    {
        return $this === self::Create || $this === self::CreateOrganization;
    }
}
