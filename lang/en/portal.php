<?php

declare(strict_types=1);

/*
 * THE ADMIN SETUP PORTAL — the single-use link a customer's external IT administrator
 * opens to configure SSO and SCIM for one organization. Shipped to `pages/portal/*` with
 * the `hosted.*` chrome ({@see \App\Platform\Locale\HostedTranslations}).
 *
 * The protocol's own words stay as they are in every language — SAML, OIDC, SCIM, entity
 * ID, ACS URL, TXT record — because they are what the person will find in their identity
 * provider's admin screen. Only the sentences around them are translated.
 */
return [
    'layout' => [
        'badge' => 'Admin setup portal',
        'toggle_theme' => 'Toggle theme',
    ],

    // The clipboard button's three states, wherever the portal offers one.
    'copy' => [
        'copy' => 'Copy',
        'copied' => 'Copied',
        'failed' => 'Copy failed — select and copy manually',
    ],

    // The type-to-confirm dialog, in the portal's language.
    'confirm' => [
        'cancel' => 'Cancel',
        'type_to_confirm' => 'Type :name to confirm',
        'hint' => 'Exactly as shown — :action stays disabled until it matches.',
    ],

    'setup' => [
        'title' => 'Set up SSO & SCIM',
        'heading' => 'Set up enterprise sign-in',
        'heading_for' => 'Set up enterprise sign-in · :organization',
        'description' => 'You were invited to configure single sign-on for this organization. Nothing else in the organization is accessible from here.',
        'step' => 'Step :number',
        'finish' => 'Finish setup',

        'domain' => [
            'heading' => 'Verify your domain',
            'lead' => 'Add a DNS record to prove you own the domain your team signs in with. This is what sends those users to SSO.',
            'label' => 'Domain',
            'add' => 'Add domain',
            'record' => 'Add this TXT record for :domain, then click Check.',
            'record_type' => 'Type',
            'record_host' => 'Host',
            'record_value' => 'Value',
            'empty' => 'No domains added yet.',
            'verified' => 'Verified',
            'pending' => 'Pending DNS',
            'check' => 'Check',
            'check_label' => 'Check DNS for :domain',
            'remove' => 'Remove',
            'remove_label' => 'Remove :domain',
            'remove_title' => 'Remove :domain?',
            'remove_consequence' => 'Anyone signing in with an address at this domain stops being routed here.',
            'invalid' => 'Enter a valid domain, e.g. acme.com.',
            'claimed' => 'That domain is already claimed by another organization.',
            'verified_status' => 'Domain verified — users on this domain can now sign in with SSO.',
            'not_found' => 'We couldn\'t find the TXT record yet — DNS can take a few minutes to propagate.',
            'removed' => 'Domain removed.',
        ],

        'connection' => [
            'heading' => 'SSO connection',
            'new' => 'New connection',
            'empty' => 'No SSO connections yet.',
            'active' => 'Active',
            // A connection that is not active shows its status; these are the ones it can have.
            'statuses' => [
                'draft' => 'draft',
                'inactive' => 'inactive',
            ],
            'activate' => 'Activate',
            'activate_label' => 'Activate :name',
            'name_label' => 'Connection name',
            'protocol_label' => 'Protocol',
            'idp_entity_id' => 'IdP entity ID',
            'idp_sso_url' => 'IdP SSO URL',
            'sp_entity_id' => 'SP entity ID',
            'sp_acs_url' => 'SP ACS URL',
            'idp_certificate' => 'IdP X.509 certificate',
            'issuer' => 'Issuer',
            'client_id' => 'Client ID',
            'client_secret' => 'Client secret',
            'signing_key' => 'Signing key',
            'create' => 'Create connection',
            'cancel' => 'Cancel',
            'created' => 'Connection created as a draft.',
            'activated' => 'Connection activated.',
            // :reason is the discovery client's own message, which stays in English.
            'discovery_failed' => 'Couldn\'t read the provider\'s OpenID configuration — check the issuer URL. (:reason)',
        ],

        'directory' => [
            'step' => 'Directory sync',
            'heading' => 'Directory sync (SCIM)',
            'new' => 'New directory',
            'base_url_help' => 'Point your identity provider (Okta, Microsoft Entra) at this base URL and authenticate with a directory’s bearer token.',
            'copy_base_url' => 'Copy the SCIM base URL',
            'token_heading' => 'Bearer token for “:name”',
            'token_once' => 'Copy this now — it is shown only once and cannot be retrieved again.',
            'copy_token' => 'Copy token',
            'name_label' => 'Directory name',
            'register' => 'Register directory',
            'cancel' => 'Cancel',
            'empty' => 'No directories connected yet.',
            'active' => 'Active',
            'paused' => 'Paused',
        ],
    ],

    'done' => [
        'title' => 'All set',
        'heading' => 'All set',
        'body' => 'Enterprise sign-in for :organization is configured. This setup link has now been used and is closed. You can close this window.',
        // Stands in for :organization above when the organization's name is not known.
        'this_organization' => 'this organization',
    ],

    'expired' => [
        'title' => 'Link unavailable',
        'heading' => 'This setup link is no longer valid',
        'body' => 'The link may have expired or already been used. Setup links are single-use and time-limited for security. Ask the person who invited you to send a new one.',
    ],
];
