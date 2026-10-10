<?php

declare(strict_types=1);

/*
 * THE SIGN-IN GROUP — every page under resources/js/pages/auth/ that is drawn in the
 * sign-in layout, and the PHP that speaks on them: page titles, validation and flash
 * messages. One top-level section per page; shared wording lives in `common` and
 * `password_field`.
 *
 * English is the source of truth for which keys exist (`php artisan i18n:types` types
 * them for React; LangParityTest holds the other languages to the same set). This file
 * merges over the framework's own lang/en/auth.php, so `failed`, `password` and
 * `throttle` are reserved names here.
 */
return [
    'common' => [
        'email' => 'Email',
        'password' => 'Password',
        'new_password' => 'New password',
        'confirm_new_password' => 'Confirm new password',
        'sign_in' => 'Sign in',
        'back_to_sign_in' => 'Back to sign in',
        'check_your_inbox' => 'Check your inbox',
        'verify' => 'Verify',
        'cancel_and_sign_out' => 'Cancel and sign out',
        // Two forms that are the same in English, which has always said "1 seconds" here;
        // a language that inflects the noun gets to.
        'too_many_attempts' => 'Too many attempts. Try again in :count seconds.|Too many attempts. Try again in :count seconds.',
        'too_many_requests' => 'Too many requests. Try again in :count seconds.|Too many requests. Try again in :count seconds.',
        'could_not_process' => 'We could not process this request. Please try again later.',
        'code_incorrect' => 'That code is incorrect or has expired.',
    ],

    'password_field' => [
        'show' => 'Show password',
        'hide' => 'Hide password',
        // The live length check under a new password, and the placeholder that says the
        // same thing before anything is typed. The floor is always well above one.
        'policy' => 'At least :count characters',
    ],

    'login' => [
        'title' => 'Sign in',
        'purpose' => [
            'default' => "Welcome back. Access your organization's identity console.",
            'device' => 'Sign in to approve the device that is waiting.',
        ],
        // Two sentences: the first is drawn bold.
        'pending_link' => [
            'lead' => 'Someone signed in with :provider using this email.',
            'body' => "That email already has an account here. Sign in below and we'll ask whether you want to connect :provider to it.",
        ],
        'magic' => [
            'sent_to' => 'We sent a one-time sign-in link to :email.',
            'dev_note' => "Shown because email isn't configured in this environment.",
        ],
        'mandate' => [
            'heading' => ':organization requires single sign-on',
            'continue' => 'Continue to :organization',
            'no_provider' => 'No identity provider is connected for :organization yet, so there is nowhere to send you. Ask an administrator to finish setting up single sign-on.',
            // Stands in for the organization's name in the lines above, when the lookup
            // that names it comes back empty.
            'your_organization' => 'Your organization',
            /*
             * What the person had just proved when the mandate refused them — one per door
             * ({@see \App\Platform\Enums\RefusedFactor}). Each opens by confirming that
             * what they did worked, because it did.
             */
            'reasons' => [
                'password' => "Your password is correct — it is just not a way in here any more. Sign in through your organization's identity provider instead.",
                'magic_link' => "That sign-in link worked, and it has now been used up. Emailed links are not a way in here any more — sign in through your organization's identity provider instead.",
                'passkey' => "Your passkey worked. It is just not a way in here any more — sign in through your organization's identity provider instead.",
                'social' => 'That sign-in worked, but it is not the identity provider your organization has chosen. Sign in through theirs instead.',
                'invitation' => "Your invitation is accepted and you are a member now. Sign in through your organization's identity provider to get started.",
                'password_reset' => "Your new password is saved, but a password is not a way in here any more. Sign in through your organization's identity provider instead.",
            ],
        ],
        'use_different_email' => 'Use a different email',
        'continue' => 'Continue',
        'sso' => [
            'continue' => 'Continue with single sign-on',
            'or_password' => 'or use your password',
            'instead' => 'Continue with single sign-on instead',
        ],
        'forgot_password' => 'Forgot password?',
        'or' => 'OR',
        'continue_with' => 'Continue with :provider',
        // The badge on the sign-in method this device used last, and what it means said in full
        // for a screen reader.
        'last_used' => 'Last used',
        'last_used_hint' => 'The way you signed in last time on this device.',
        'magic_link' => 'Email me a magic link',
        'passkey' => 'Sign in with a passkey',
        'passkey_failed' => 'Passkey sign-in failed.',
        'new_organization' => 'New organization?',
        'create_one' => 'Create one',
        'invalid_credentials' => 'Those credentials do not match our records.',
        // Flashed back here by the social sign-in door.
        'social' => [
            'failed' => 'Sign-in with :provider was cancelled or failed.',
            'unavailable' => 'Sign-in with :provider is unavailable right now.',
        ],
        // Answered by the passkey sign-in endpoint and shown under its button.
        'passkey_errors' => [
            'challenge_expired' => 'Sign-in challenge expired. Try again.',
            'not_registered' => 'That passkey is not registered.',
            'cloned' => 'This passkey may have been cloned and was rejected.',
            'unverified' => 'That passkey could not be verified.',
            'failed' => 'Something went wrong signing in.',
        ],
    ],

    'signup' => [
        'title' => 'Get started',
        'heading' => [
            'creates_idp' => 'Create your workspace',
            'for_app' => 'Create your account',
            'default' => 'Create your organization',
        ],
        'lead' => [
            'creates_idp' => 'A workspace for your company, and your own hosted identity provider — SSO, users and sign-in you fully control, live in a minute.',
            // `:name` is the app waiting for the sign-up, or the customer's own brand.
            'join' => 'Sign up for :name. You will be the owner of your team, and can invite people once you are in.',
            'default' => 'Set up Cbox ID for your team in under a minute.',
        ],
        'organization_label' => [
            'creates_idp' => 'Workspace name',
            'for_app' => 'Team or company name',
            'default' => 'Organization name',
        ],
        'organization_placeholder' => 'Acme Inc.',
        'name_label' => 'Your name',
        'name_placeholder' => 'Dana Reeves',
        'email_label' => 'Work email',
        'breach_note' => 'Checked against known breaches.',
        'submit' => [
            'creates_idp' => 'Create workspace',
            'for_app' => 'Create account and continue',
            'default' => 'Create organization',
        ],
        'have_account' => 'Already have an account?',
        'complete_verification' => 'Please complete the verification below, then submit again.',
        'sso_required' => 'Your organization requires signing in through SSO.',
        'account_exists' => 'An account with this email already exists.',
        // Shown at the sign-in door to somebody who reached a closed sign-up.
        'closed' => [
            'tenant' => 'You need an invitation to join. Ask the person who runs your team for one.',
            'invite_only' => 'Signups are invite-only. Ask an administrator for an invitation.',
            'closed' => 'Signups are currently closed.',
        ],
    ],

    'forgot_password' => [
        'title' => 'Reset password',
        'heading' => 'Reset your password',
        'lead' => "Enter your email and we'll send a reset link.",
        'sent_to' => 'If an account exists for :email, a reset link is on its way.',
        'submit' => 'Send reset link',
        'remembered' => 'Remembered it?',
        'throttled' => 'Too many attempts. Please wait a few minutes and try again.',
    ],

    'reset_password' => [
        'title' => 'Choose a new password',
        'lead' => 'Pick a strong password of at least :count characters.',
        'confirm_placeholder' => 'Re-enter your new password',
        'submit' => 'Reset password',
        'invalid_link' => 'This reset link is invalid or has expired. Request a new one.',
        'done' => 'Your password has been reset — sign in with your new password.',
    ],

    'change_password' => [
        'title' => 'Choose a new password',
        'lead' => 'The password you signed in with was issued by an administrator. Choose one only you know before continuing.',
        'submit' => 'Update password',
        'mismatch' => 'The passwords do not match.',
    ],

    'mfa' => [
        'title' => 'Two-factor verification',
        'code' => [
            'lead' => 'Enter the 6-digit code from your authenticator app.',
            'label' => 'Authentication code',
            'switch' => 'Use a recovery code instead',
            'sms_switch' => 'Text me a code instead',
        ],
        'recovery' => [
            'lead' => 'Enter one of the recovery codes you saved when enabling two-factor.',
            'label' => 'Recovery code',
            'submit' => 'Verify recovery code',
            'switch' => 'Use your authenticator app instead',
            'invalid' => 'That recovery code is invalid or already used.',
            'back' => 'Use another method',
        ],
        'sms' => [
            'lead' => 'We\'ll text a code to the phone number on your account.',
            'send' => 'Text me a code',
            'sent' => 'We sent a code to :number. It expires in a few minutes.',
            'resend' => 'Send a new code',
            'label' => 'Code from the text message',
            'switch' => 'Use your authenticator app instead',
            'wait' => 'A code was sent recently. Wait a moment before asking for another.',
            'failed' => 'We couldn\'t send the text message. Try again in a moment, or use a recovery code.',
            'unavailable' => 'Text-message codes aren\'t available for this account.',
        ],
    ],

    'otp_step_up' => [
        'title' => 'Additional verification',
        'lead' => 'This sign-in looked unusual, so we emailed a one-time code to :email. Enter it to continue.',
        'signup_title' => 'Confirm your email address',
        'signup_lead' => 'To finish creating your account, enter the one-time code we emailed to :email.',
        'code_label' => 'Verification code',
        'resend' => "Didn't get it? Resend code",
        'resent' => 'We sent a new code to :email.',
        'too_many_codes' => 'Too many codes requested. Please wait a moment and try again.',
    ],

    'accounts' => [
        'title' => 'Switch user',
        'lead' => 'Everyone signed in on this device. Pick one, or sign in as someone else.',
        'active' => 'Active',
        'add' => 'Sign in as someone else',
    ],

    'accept_invite' => [
        'title' => 'Accept invitation',
        'heading' => 'Accept your invitation',
        'lead_from' => ':inviter invited you to help run :organization as :role. You will sign in as :email.',
        'lead' => 'Set a password to help run :organization as :role. You will sign in as :email.',
        'organization_fallback' => 'the organization',
        'password_label' => 'Choose a password',
        'breach_note' => 'Checked against known breaches.',
        'submit' => 'Accept & sign in',
        'no_longer_valid' => 'This invitation is no longer valid. Try signing in.',
        'invalid' => 'That invitation is invalid or has expired.',
        'not_completed' => 'That invitation could not be completed.',
    ],

    'confirm_email' => [
        'title' => 'Confirm your email',
        'heading' => 'Confirm your email address',
        'lead' => 'You opened the confirmation link we sent. Confirm to finish verifying this address.',
        'action' => 'Confirm email address',
        'invalid' => 'That verification link is invalid or has expired.',
        'verified_sign_in' => 'Email verified — sign in to open your environment.',
        'verified' => 'Your email is verified — you can sign in.',
    ],

    'confirm_sign_in' => [
        'title' => 'Sign in',
        'heading' => 'Finish signing in',
        'lead' => 'You opened a sign-in link. Continue to sign in on this device.',
        'action' => 'Sign in',
        'note' => 'The link works once. If you did not ask to sign in, close this page — nothing happens until you press the button.',
        'invalid' => 'That sign-in link is invalid or has expired.',
    ],

    'first_run' => [
        'title' => 'Set up Cbox ID',
        'lead' => 'This deployment is empty. Claim it once, from the machine that runs it.',
        'unmigrated' => [
            'title' => "This deployment's database has no schema yet.",
            'body' => 'Run :migrate on the server (or :install, which migrates and installs in one step), then reload this page.',
        ],
        'misconfigured' => [
            'title' => 'This deployment is configured as multi-tenant but has no account host.',
            'body' => 'Set :console_host (where the console lives), or set :single_host for a single-host install — then reload this page. You can also run :install, which asks for both and writes them for you.',
        ],
        'token_notice' => [
            'title' => 'Where is the setup token?',
            'body' => 'Run :command on the server — on any instance of this deployment — and paste what it prints. Each run prints a fresh token, valid for an hour. It is never shown on this page.',
        ],
        'cli_hint' => 'Prefer the command line? :install does the same thing, and is the only path that can also choose and record the deployment shape.',
        'token_label' => 'Setup token',
        'token_placeholder' => 'Paste the token from the server',
        'name_label' => 'Your name',
        'name_placeholder' => 'Root Operator',
        'email_label' => 'Your email',
        'environment_label' => 'Name your first environment',
        'environment_hint' => 'An environment is the hard isolation boundary — its own users, keys and issuer.',
        // Both the field's placeholder and the name it starts out holding.
        'environment_default' => 'Production',
        'organization_label' => 'Organization name',
        'organization_hint' => 'This deployment is configured as multi-tenant, so the install also creates the first workspace — the organization that owns environments and billing.',
        'organization_placeholder' => 'Your company',
        'submit' => 'Install this deployment',
        'token_mismatch' => 'That setup token does not match this deployment’s, or it has expired. Print a fresh one with php artisan cbox-id:setup-token.',
    ],

    'join_organization' => [
        'title' => 'Join :organization',
        'heading' => 'Join :organization?',
        'lead' => 'You have been invited to join this organization. Accept to become a member and sign in.',
        'lead_app' => 'You have been invited to join this organization. Accept to become a member, and we will take you to :app.',
        'action' => 'Accept invitation',
        'note' => 'Not expecting this? Close this page — nothing happens unless you accept.',
        'facts' => [
            'organization' => 'Organization',
            'invited_by' => 'Invited by',
            'built_in_role' => 'Built-in role',
            'custom_roles' => 'Custom roles',
            'app_roles' => 'Roles in :app',
            'email' => 'Your email',
            'app' => 'App',
        ],
        'invalid' => 'That invitation is invalid or has expired.',
    ],

    'link_confirm' => [
        'title' => 'Connect your account',
        'heading' => 'Connect :provider?',
        'lead' => 'Someone just signed in with :provider — an address that already belongs to your account.',
        'lead_email' => 'Someone just signed in with :provider as :email — an address that already belongs to your account.',
        // `:emphasis` is the bold opening phrase, its own line below.
        'was_you' => ":emphasis, connect it and you'll be able to sign in with :provider or with your password from now on.",
        'was_you_emphasis' => 'If that was you',
        'was_not_you' => ':emphasis, decline. Someone else tried to sign in using your email address. Nothing will be added to your account, and your password still works as before.',
        'was_not_you_emphasis' => "If it wasn't",
        'decline' => "No, that wasn't me",
        'connect' => 'Yes, connect :provider',
        'disconnect_hint' => "You can disconnect :provider at any time from your account's security settings.",
    ],

    'open_portal_setup' => [
        'title' => 'Admin setup',
        'heading' => 'Set up sign-in for your organization',
        'lead' => 'You were sent a setup link for your organization — single sign-on, directory sync, domains, log streams or a certificate renewal. Continue to open the setup screen.',
        'action' => 'Open setup',
        'note' => 'The link works once, and the setup session it opens expires. Open it when you are ready to finish.',
    ],

    'connected_services' => [
        'title' => 'Connected services',
        'heading' => 'Connected services',
        'lead' => 'Accounts at other services that you connected, so apps here can work with them on your behalf. You can disconnect one at any time.',
        'empty' => 'There are no services to connect yet.',
        'connected_as' => 'Connected as :account',
        'connected_label' => 'Connected',
        'not_connected' => 'Not connected',
        'needs_reauth' => 'Reconnect needed',
        'needs_reauth_hint' => ':provider no longer accepts this connection. Connect again to keep using it.',
        'access' => 'Access: :scopes',
        'connect' => 'Connect',
        'reconnect' => 'Reconnect',
        'disconnect' => 'Disconnect',
        'disconnect_title' => 'Disconnect :provider?',
        'disconnect_body' => 'Apps here will no longer be able to use your :provider account. Where :provider supports it, the access is withdrawn there too.',
        'cancel' => 'Cancel',
        'back' => 'Back to my account',
        'connected' => ':provider connected.',
        'cancelled' => 'You did not connect :provider.',
        'failed' => ':provider could not be connected. Please try again.',
        'disconnected' => ':provider disconnected.',
    ],

    'connect_service' => [
        'title' => 'Connect :provider',
        'heading' => 'Connect your :provider account',
        'lead' => 'You will be sent to :provider to sign in and approve access. Your :provider password is never shared with us.',
        'lead_app' => ':app wants to use your :provider account on your behalf. You will be sent to :provider to sign in and approve access.',
        'asks_for' => ':provider will ask you to allow:',
        'no_scopes' => ':provider will show you exactly what is shared before you approve.',
        'continue' => 'Continue to :provider',
        'cancel' => 'Cancel',
        'later' => 'You can disconnect at any time under Connected services in your account.',
    ],
];
