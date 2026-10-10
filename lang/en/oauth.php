<?php

declare(strict_types=1);

/*
 * THE OAUTH AUTHORIZATION PAGES — consent, the hosted organization picker and the hosted
 * "create an organization" step, and the failure page `/oauth/authorize` draws when a
 * request cannot be answered by redirecting anywhere. Shipped to `pages/oauth/*` with the
 * `hosted.*` chrome ({@see \App\Platform\Locale\HostedTranslations}).
 *
 * NOT HERE: the `error_description` returned to the CLIENT on a redirect. That sentence is
 * for the developer of the relying party, read in its logs, and stays in English whatever
 * language the person's browser asked for.
 */
return [
    // Shared by the organization picker and the create step.
    'signed_in_as' => 'Signed in as :account',
    'cancel_and_return' => 'Cancel and return to :client',

    'consent' => [
        'title' => 'Authorize',
        'heading' => 'Authorize :client',
        'wants_access' => ':client wants to access your :account account.',
        'registered_by' => 'Registered by :owner — an app’s name is chosen by whoever registered it.',
        // Stands in for :owner above when the registering organization has been deleted.
        'unknown_owner' => 'an organization that no longer exists',
        'in_organization' => 'In :organization',
        'will_allow' => 'This will allow :client to',
        'cancel' => 'Cancel',
        'authorize' => 'Authorize',
        'redirect_notice' => 'You’ll be redirected to :host after authorizing.',
        // A client that registered itself (RFC 7591, or a client ID metadata document).
        'self_registered_owner' => 'the app itself',
        'self_registered' => 'This app registered itself. Nobody at :account has reviewed it — only continue if you started this sign-in yourself.',
        'published_by' => 'Described by :host — the one thing about this app that has been checked.',
        'about_app' => 'About this app',
        // Management-plane scopes an agent acts with as the person.
        'critical' => 'Critical',
        'acts_as_you' => 'Anything it does as you is limited to what you may do yourself, and recorded in the audit log.',
        'critical_notice' => 'Critical actions still wait for your approval on your device, every time.',

        /*
         * What the person is shown for each BUILT-IN scope ({@see \App\Platform\ScopeCatalog}
         * keeps the English for the console's picker). A custom scope has no line here and
         * is shown as its own key — it is the app's word, not ours.
         */
        'scopes' => [
            'openid' => 'Verify your identity',
            'profile' => 'Your name',
            'email' => 'Your email address',
            'offline_access' => 'Stay signed in',
            'organizations' => 'Which organizations you belong to',
            'groups' => 'Your roles',
            'feature_flags' => 'Which features are turned on for you',
        ],
    ],

    'failure' => [
        'title' => 'Authorization failed',
        'heading' => 'Authorization failed',
        'back' => 'Back to :name',
        'generic' => 'This authorization request could not be completed.',
        'expired' => 'This authorization request has expired or was already used. Please start again.',
        'par_required' => 'This server requires pushed authorization requests. Send the request to /oauth/par first.',
        'unknown_client' => 'Unknown client. This application is not registered with Cbox ID.',
        'client_document' => 'This application’s description could not be read. It is published at the address the application gave as its ID, and that document is missing, unreachable or invalid.',
        'redirect_mismatch' => 'The redirect URI does not match any registered for this application.',
        'stale' => 'This authorization request can no longer be completed. Please start again.',
        'account_attention' => 'Your account needs attention before you can continue. Please sign in again.',
        'step_up' => 'This application requires a more recent or stronger sign-in. Please start again.',
        // At the platform root, an MCP client signing in someone on no workspace's team.
        'no_workspace' => 'This sign-in connects an agent to a workspace on Cbox ID, and your account is not on a workspace’s team. Ask a workspace owner to invite you, or connect the agent at your environment’s own address instead.',
    ],

    'organization' => [
        'title' => 'Choose an organization',
        'heading' => 'Choose an organization',
        'lead' => ':client will use the organization you pick, with your role in it.',
        'none_create' => 'You are not in any organization here yet. Create one to continue.',
        'none_invite' => 'You are not in any organization here yet. Ask someone to invite you to theirs, then try again.',
        'list_label' => 'Your organizations',
        'suggested' => 'Suggested',
        'continuing_with' => 'Continuing with :name',
        'create' => 'Create an organization',
        'required' => 'Choose an organization to continue.',
        'not_member' => 'You are not an active member of that organization.',
        'roles' => [
            'owner' => 'Owner',
            'admin' => 'Admin',
            'developer' => 'Developer',
            'member' => 'Member',
            'viewer' => 'Viewer',
        ],
    ],

    'create_organization' => [
        'title' => 'Create an organization',
        'heading' => 'Create an organization',
        'lead' => 'Your team or company in :client. You will be its owner, and can invite people once you are in.',
        'name_label' => 'Organization name',
        'submit' => 'Create and continue',
        'choose_existing' => 'Choose an existing organization',
        'name_required' => 'Give your organization a name.',
        'not_offered' => 'Creating an organization is not available here. Ask an administrator to invite you to one.',
        // Both forms read "minutes" in English, as the page always has; the pair is there
        // so a language with a real singular can use it.
        'too_many' => 'You have created several organizations in a short time. Try again in :count minutes.|You have created several organizations in a short time. Try again in :count minutes.',
    ],
    /*
     * The RFC 8628 verification page — where a person approves the code their TV, console
     * or terminal shows, usually on a phone after scanning a QR code. The scope list reuses
     * `consent.scopes`, and the "this will allow" and critical lines `consent.*`.
     */
    'device' => [
        'title' => 'Connect a device',
        'heading' => 'Connect a device',
        'lead' => 'Enter the code shown on your TV, console or terminal.',
        'code_label' => 'Code from your device',
        'code_hint' => 'Eight letters, like BCDF-GHJK. Capitals and the dash are optional.',
        'continue' => 'Continue',
        'next_note' => 'You’ll see which app is asking before anything is connected.',
        'code_required' => 'Enter the code shown on your device.',
        'invalid' => 'That code is invalid or has expired. Check the code on your device and try again.',
        'too_many' => 'Too many attempts. Try again in :seconds seconds.',
        'link_expired' => 'That sign-in request has expired or already finished. Enter the code shown on your device.',
        'consent_heading' => 'Sign in to :client?',
        'consent_lead' => ':client on another device is asking to sign in to your account.',
        'signing_in_as' => 'Signing in as',
        'code_check' => 'Check that this code matches the one on your device',
        'deny' => 'Deny',
        'approve' => 'Approve',
        'warning' => 'Only approve if you started signing in on your own device just now. If you didn’t, deny it — someone may be trying to get into your account.',
        'approved_heading' => 'You’re all set',
        'approved_body' => 'You can return to your TV or device — it is signing in now.',
        'denied_heading' => 'Request denied',
        'denied_body' => 'Nothing was connected, and the device has been told no.',
        'enter_another' => 'Connect another device',
        'blocked' => 'This request could not be approved from here. Try again on a network you usually use, or contact your administrator.',
        'step_up' => 'Confirm it is you before connecting a new device — this sign-in looks different from your usual ones.',
        'step_up_heading' => 'Confirm it’s you',
        'step_up_sent' => 'We sent a code to :email. Enter it to connect the device.',
        'step_up_label' => 'Code from the email',
        'step_up_submit' => 'Confirm and approve',
        'step_up_resend' => 'Send a new code',
        'step_up_invalid' => 'That code is not right, or it has expired. Check the newest email and try again.',
        'step_up_wait' => 'A code was sent a moment ago — use that one, or wait a minute before asking for another.',
    ],
];
