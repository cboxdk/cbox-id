<?php

declare(strict_types=1);
use Cbox\Id\Identity\Models\User;

return [

    /*
     * The token issuer / base URL, published in OIDC discovery and used to build
     * endpoint URLs. Falls back to the app URL when unset.
     */
    'issuer' => env('CBOX_ID_ISSUER'),

    'oauth' => [
        /*
         * This app DOES serve the interactive /authorize endpoint (routes/web.php), so
         * it tells the framework where it lives. A PATH, not an absolute URL: it is
         * joined to the per-environment issuer, so every tenant advertises its own
         * authorize endpoint on its own host.
         *
         * An absolute URL would pin all environments to one host — and since RFC 9207
         * (`iss` on the authorization response) is advertised alongside it, a
         * mix-up-hardened client would then compare the two, find them different, and
         * abort every login outside the platform root.
         *
         * Configured here rather than left to an env var because it is a property of
         * this app's routes, not of a deployment: a docs-following deploy that never set
         * the variable served a discovery document with no authorization_endpoint at
         * all — a field OpenID Connect Discovery marks REQUIRED.
         */
        'authorization_endpoint_path' => '/oauth/authorize',
    ],

    /*
     * Override a package model with your own subclass to add relations, casts or
     * behaviour. Your class must extend the package model; the platform still owns
     * the schema. Extend the pattern to other models as you need them.
     */
    'models' => [
        'user' => User::class,
    ],

    /*
     * Whole-product branding for a self-hosted instance — override without
     * touching Blade. `name` replaces "Cbox ID" in the wordmark and page titles;
     * `tagline` is the sign-in hero headline. `trust_line` is free text under the
     * hero and is EMPTY by default on purpose: a self-hosted deployment must only
     * claim what it can back — never ship an unearned certification badge.
     */
    'branding' => [
        'name' => env('CBOX_ID_BRAND_NAME', 'Cbox ID'),
        'tagline' => env('CBOX_ID_BRAND_TAGLINE', 'One identity layer for every app you ship.'),
        'trust_line' => env('CBOX_ID_BRAND_TRUST_LINE', ''),
    ],

    /*
     * Self-service signup mode — who may create an account + organization at
     * /signup:
     *   - 'open'        anyone may sign up (the default).
     *   - 'invite_only' the public signup is closed; new accounts arrive only
     *                   through admin invitations (which keep working).
     *   - 'closed'      no self-service signup at all.
     * Admin-initiated provisioning (invitations, the operator console) is never
     * gated by this — it is not self-service.
     */
    'signup' => [
        'mode' => env('CBOX_ID_SIGNUP_MODE', 'open'),
    ],

    /*
     * WebAuthn / passkey ceremony parameters. `rp_id` is the Relying Party ID
     * (usually your registrable domain, e.g. "example.com"); `origin` is the
     * exact origin the browser reports (scheme + host + port). Both are asserted
     * during verification — a mismatch is rejected.
     */
    'webauthn' => [
        'rp_id' => env('CBOX_ID_WEBAUTHN_RP_ID'),
        'origin' => env('CBOX_ID_WEBAUTHN_ORIGIN'),
    ],

    /*
     * Entitlement keys that gate the enterprise self-serve surfaces (SSO & SCIM).
     * These are NAMESPACED so they never clash with the entitlements a tenant
     * product pushes through the same billing-fed projection. An org sees the SSO
     * or SCIM screens as usable only when billing has set the matching key's
     * `enabled` flag true — deny-by-default otherwise.
     */
    'entitlements' => [
        'sso' => env('CBOX_ID_ENTITLEMENT_SSO', 'cbox-id-sso'),
        'scim' => env('CBOX_ID_ENTITLEMENT_SCIM', 'cbox-id-scim'),
    ],

    /*
     * Admin Portal setup links — the short-lived, single-use URL an entitled org
     * admin hands to an external IT admin so they can configure that one org's
     * SSO/SCIM without a platform account. `ttl_minutes` bounds how long a
     * generated link stays redeemable.
     */
    'portal' => [
        'ttl_minutes' => (int) env('CBOX_ID_PORTAL_TTL_MINUTES', 30),
    ],

    'crypto' => [

        /*
         * Master key for envelope encryption (SecretBox). A base64-encoded,
         * 32-byte key. Generate one with:
         *
         *     php -r "echo base64_encode(random_bytes(32)).PHP_EOL;"
         *
         * Losing this key makes all sealed secrets (including private signing
         * keys) unrecoverable. Back it up separately from the database.
         */
        'key' => env('CBOX_ID_CRYPTO_KEY'),

    ],

];
