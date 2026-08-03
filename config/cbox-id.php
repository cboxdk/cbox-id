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
     * The IdP PROTOCOL surface the framework registers — OIDC discovery, JWKS, the
     * RFC 8414 / RFC 9728 metadata, every `/oauth/*` endpoint, SAML and SCIM — is
     * confined to the SUBJECT plane.
     *
     * The platform-root host (cboxid.com) is the ACCOUNT door: sign up, manage your
     * account and its environments. It is NOT an issuer — the interactive
     * `/oauth/authorize` is `plane:subject` and therefore absent there. Without this
     * gate the root still answered discovery, advertising `issuer:
     * https://cboxid.com` alongside an `authorization_endpoint` that 404s, so a
     * conformant client followed the document straight into a dead end. Half an IdP
     * is worse than none: it is discoverable.
     *
     * `plane:subject` is the SAME gate the interactive surfaces use, so there is one
     * answer to "is this host a tenant IdP", not two that can drift. In the
     * single-tenant / self-hosted shape (no `base_domains`) the gate is a no-op and
     * the one host serves the whole surface, exactly as before.
     *
     * `GET /up` is registered outside this group by the framework — a liveness probe
     * must answer on every host, including a kubelet hitting the pod directly.
     */
    'api' => [
        'middleware' => ['plane:subject'],
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
    /*
     * Passkeys / WebAuthn.
     *
     * Both derive from APP_URL when unset. They used to have NO defaults, so a deploy
     * that did not set them fell back to the refusing verifier and passkeys were
     * silently unavailable — a shipped, documented, real-vector-tested security feature
     * that was simply off. A control that is off by default is worse than one that was
     * never claimed, so the safe default is the one that works for the host it runs on.
     *
     * rp_id is the registrable domain (no scheme, no port); origin is the full origin
     * the browser will report. Set them explicitly when the sign-in host differs from
     * APP_URL (a custom domain, or a reverse proxy terminating elsewhere).
     */
    'webauthn' => [
        'rp_id' => env('CBOX_ID_WEBAUTHN_RP_ID', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),
        'origin' => env('CBOX_ID_WEBAUTHN_ORIGIN', rtrim((string) env('APP_URL', 'http://localhost'), '/')),
    ],

    /*
     * Entitlement keys that gate the enterprise self-serve surfaces (SSO & SCIM).
     * These are NAMESPACED so they never clash with the entitlements a tenant
     * product pushes through the same billing-fed projection.
     *
     * `mode` decides what an UNSET key means:
     *
     *   open     (default) — unset means granted. No billing plane, no limits: a
     *                        self-hosted deployment gets every feature. An explicit
     *                        row still wins, so per-org differentiation by hand
     *                        (including revoking with `enabled: false`) keeps working.
     *   metered            — unset means denied, and the billing projection is the
     *                        only thing that grants. Set this where a billing
     *                        transport is actually wired; it is what the hosted
     *                        plane runs so plan tiers mean something there.
     *
     * See App\Platform\OpenEntitlements for why widening this is not an isolation
     * concern: authorization resolves from the relationship store, not from here.
     */
    'entitlements' => [
        'mode' => env('CBOX_ID_ENTITLEMENTS', 'open'),
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

    /*
     * The adaptive-risk decision trail (`risk_decisions`) — the durable record of
     * every score the risk engine produced, and the evidence a `RISK_MODE=enforce`
     * threshold is set from. See docs/security/adaptive-risk.md.
     *
     * `retention_days` bounds it; the sweep is Laravel's own `model:prune`, scheduled
     * daily in routes/console.php. Set the env var EMPTY to disable pruning entirely
     * and keep the trail indefinitely (a compliance choice, not a default — this table
     * takes a row per pre-auth sign-in attempt, including from unauthenticated
     * traffic, so unbounded growth is driven by strangers).
     */
    'risk_trail' => [
        'retention_days' => env('CBOX_ID_RISK_TRAIL_RETENTION_DAYS', 90),
    ],

    /*
     * Whether this deployment is MULTI-TENANT, stated rather than inferred.
     *
     * The default shape of this product is a single-tenant identity provider: one
     * install, one IdP, one host. Multi-tenancy — an account plane that provisions
     * workspaces and IdPs, tenant subdomains, billing — is a mode you switch ON.
     *
     * It used to be derived from whether `environments.base_domains` happened to be
     * non-empty, and that is a dangerous thing to infer, because the mode decides
     * whether the host bulkheads exist at all: in single-tenant, `onSubjectPlane()` and
     * `onOperatorPlane()` return true UNCONDITIONALLY. A deployment that had not yet
     * listed its base domains was therefore serving the staff console on every host it
     * answered to, and nothing said so — the config that turned the bulkheads off was a
     * domain list nobody thought of as a security control.
     *
     * A NEW top-level key on purpose. Laravel merges published package config only at
     * the top level, so adding `multi_tenant` under the package's existing
     * `environments` array here would replace that whole array — taking `base_domains`,
     * the resolution cache and the rest with it.
     *
     * Unset (null) keeps the old derivation, so an existing deployment does not change
     * shape on upgrade. Set it explicitly and it wins. Once every deployment states it,
     * the fallback goes and the default becomes single-tenant.
     */
    'tenancy' => [
        'multi_tenant' => env('CBOX_ID_MULTI_TENANT'),

        /*
         * The host the ACCOUNT console lives on — where an account member signs in to
         * manage their workspaces, IdPs and billing.
         *
         * Its own setting because it is its own fact. It used to be read as
         * `environments.base_domains[0]`, which conflated two unrelated questions: "under
         * which domains do we resolve a subdomain to an environment" and "where is the
         * account console". Those coincide in the subdomain shape and not otherwise.
         *
         * Multi-tenancy without subdomains is a real deployment: every tenant gets its own
         * domain (id.customer.example) resolved by an exact match, and `base_domains` is
         * empty. Deriving the account host from an empty list produced null there — which
         * silently sent the environment console's sign-in handoff to a local door instead
         * of the account plane, and dropped the account host out of the CSP `form-action`
         * list so cross-plane logout was refused by the browser.
         *
         * Unset falls back to the first base domain, so the subdomain shape needs nothing.
         */
        'account_host' => env('CBOX_ID_ACCOUNT_HOST'),
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
