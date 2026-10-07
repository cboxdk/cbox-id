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
        'redirect_mismatch' => 'The redirect URI does not match any registered for this application.',
        'stale' => 'This authorization request can no longer be completed. Please start again.',
        'account_attention' => 'Your account needs attention before you can continue. Please sign in again.',
        'step_up' => 'This application requires a more recent or stronger sign-in. Please start again.',
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
];
