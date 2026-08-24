<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * The catalog of permissions ("scopes") an app can request — the plain-language
 * answer to "what should the scopes be?". Two families:
 *
 *  - Sign-in (OIDC): what an app learns about the person signing in. These are the
 *    OpenID Connect standard scopes the platform's /authorize + token endpoints
 *    honour (see laravel-id config `oauth.dynamic_registration.allowed_scopes`).
 *  - Platform API: what a machine-to-machine app may DO against the Cbox ID API.
 *    Each maps to a real `scope:` middleware on an API route (see routes/api.php).
 *
 * The form offers these as a described picker instead of a blank text box, and the
 * app still allows a custom scope for anything a host adds beyond this catalog.
 */
final class ScopeCatalog
{
    public const SIGN_IN = 'Sign-in';

    public const PLATFORM_API = 'Platform API';

    /**
     * @return list<array{key: string, label: string, description: string, category: string, recommended: bool, consent?: string}>
     */
    public function all(): array
    {
        return [
            ['key' => 'openid', 'label' => 'Sign in', 'description' => 'Confirm who the person is. Required for single sign-on.', 'category' => self::SIGN_IN, 'recommended' => true, 'consent' => 'Verify your identity'],
            ['key' => 'profile', 'label' => 'Basic profile', 'description' => "The person's name and profile details.", 'category' => self::SIGN_IN, 'recommended' => true, 'consent' => 'Your name'],
            ['key' => 'email', 'label' => 'Email address', 'description' => "The person's email address.", 'category' => self::SIGN_IN, 'recommended' => true, 'consent' => 'Your email address'],
            ['key' => 'offline_access', 'label' => 'Stay signed in', 'description' => 'Keep them signed in with refresh tokens, so they need not log in again.', 'category' => self::SIGN_IN, 'recommended' => false, 'consent' => 'Stay signed in'],
            // Advertised in discovery and emitted as a claim, but absent from this picker
            // AND from the dynamic-registration allow-list — so it was reachable only
            // through the undiscoverable custom-scope box, and never at all for a
            // self-registering client.
            ['key' => 'organizations', 'label' => 'Their organizations', 'description' => 'The organizations this person belongs to, so the app can offer a workspace switcher.', 'category' => self::SIGN_IN, 'recommended' => false, 'consent' => 'Which organizations you belong to'],
            // Same story as `organizations` above, one release later: `groups` was added to
            // discovery and to the token issuer and to neither list that can grant it. It
            // puts this app's RBAC roles on the ID TOKEN, which is what a relying party
            // that authenticates the id_token rather than the access token — Kubernetes,
            // Grafana, Vault — needs in order to bind any policy to the person at all.
            //
            // AND THE NAME IS THE PROBLEM WITH IT. The key has to be `groups`, because
            // that is the claim Kubernetes, Grafana and Vault read and the whole reason
            // this scope exists — but nothing anywhere else in this console is called a
            // group, so somebody ticking it goes looking for a Groups page, finds only
            // directory groups (which are inbound SCIM, the opposite direction, and
            // irrelevant when Cbox ID is the identity provider), and concludes a feature
            // is missing. The description carries the whole answer rather than half of it.
            ['key' => 'groups', 'label' => 'Their roles, as a “groups” claim', 'description' => 'Puts the person’s ROLES on the ID token under the name `groups` — what Kubernetes, Grafana, Vault and most older SaaS look for. There is nothing called a group in this console: create a Role named as the app expects, and it arrives here.', 'category' => self::SIGN_IN, 'recommended' => false, 'consent' => 'Your roles'],
            ['key' => 'vault.manage', 'label' => 'Manage stored secrets', 'description' => 'Create, rotate and revoke downstream credentials in the Token Vault.', 'category' => self::PLATFORM_API, 'recommended' => false],
            ['key' => 'vault.lease', 'label' => 'Use stored secrets', 'description' => 'Fetch a stored credential to call a downstream service.', 'category' => self::PLATFORM_API, 'recommended' => false],
            ['key' => 'apps.manifest', 'label' => 'Publish its own manifest', 'description' => 'Let this app push its own roles &amp; permissions manifest to Cbox ID.', 'category' => self::PLATFORM_API, 'recommended' => false],
            // The scope `/oauth/decisions` requires once an operator turns
            // `oauth.decisions.require_scope` on. It was named by the controller and by
            // the config and offered NOWHERE, so switching that flag on made the endpoint
            // unusable by every client at once — the same defect `organizations` and
            // `groups` each had, a third time.
            //
            // Here rather than in the dynamic-registration allow-list, deliberately: it
            // hands a resource server the subject's whole permission and entitlement set
            // in an organization, which is strictly more than UserInfo gives out. That is
            // an operator's decision, like the vault and manifest scopes beside it, not
            // something a client grants itself by asking.
            ['key' => 'decisions:read', 'label' => 'Ask for authorization decisions', 'description' => "Let this app call the decision endpoint for a person's permissions and entitlements in an organization.", 'category' => self::PLATFORM_API, 'recommended' => false],
        ];
    }

    /**
     * The catalog grouped by category, in display order.
     *
     * @return array<string, list<array{key: string, label: string, description: string, category: string, recommended: bool}>>
     */
    public function grouped(): array
    {
        $grouped = [];

        foreach ($this->all() as $scope) {
            $grouped[$scope['category']][] = $scope;
        }

        return $grouped;
    }

    /**
     * @return list<string>
     */
    /**
     * What a PERSON is shown on the consent screen, keyed by scope.
     *
     * A second voice, not a second list. The consent screen kept its own map of four
     * strings and fell back to the raw scope key for everything else — so somebody
     * approving an app was shown the literal word "groups", on the most end-user-facing
     * page in the product, with nothing to say what it meant. Two lists that must agree
     * is one list; the labels above address an administrator registering an app, these
     * address the person deciding whether to allow it.
     *
     * A scope with no entry is a CUSTOM scope, and rendering its key verbatim is right:
     * it is the app's own word and we have nothing truer to say about it.
     *
     * @return array<string, string>
     */
    public function consentLabels(): array
    {
        $labels = [];

        foreach ($this->all() as $scope) {
            if (isset($scope['consent'])) {
                $labels[$scope['key']] = $scope['consent'];
            }
        }

        return $labels;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(static fn (array $s): string => $s['key'], $this->all());
    }

    /**
     * Sensible defaults for a browser login / SSO app: the identity basics.
     *
     * @return list<string>
     */
    public function signInDefaults(): array
    {
        return ['openid', 'profile', 'email'];
    }
}
