<?php

declare(strict_types=1);

/*
 * THE MAIL THE HOSTED FLOWS SEND — rendered by PHP alone, never shipped to the browser.
 *
 * `:brand` is the deployment's configured product name (`cbox-id.branding.name`), never a
 * literal: a self-hosted install that renamed itself must not mail its users somebody
 * else's name. Every send site states the recipient's language with `->locale()`
 * ({@see \App\Platform\Locale\MailLocale}).
 *
 * Lines that carry `<b>` around a name take the name already escaped and wrapped by the
 * template, so the sentence stays whole for translators and no HTML lives in this file.
 */
return [
    'layout' => [
        'footer' => '© :year :brand · This is an automated message from your identity platform.',
    ],

    'common' => [
        'paste_link' => 'Or paste this link into your browser:',
    ],

    // Role names an invitation may carry, by the role's value. A role the platform does not
    // know by this name is shown as the inviter's console labelled it.
    'roles' => [
        'owner' => 'Owner',
        'admin' => 'Admin',
        'developer' => 'Developer',
        'member' => 'Member',
        'viewer' => 'Viewer',
    ],

    'magic_link' => [
        'subject' => 'Your :brand sign-in link',
        'heading' => 'Sign in to :brand',
        'body' => 'Click the button below to sign in. This link is single-use and expires in 15 minutes. If you didn\'t request it, you can safely ignore this email.',
        'button' => 'Sign in to :brand',
    ],

    'password_reset' => [
        'subject' => 'Reset your :brand password',
        'heading' => 'Reset your password',
        'body' => 'We received a request to reset your :brand password. Click the button below to choose a new one. This link is single-use and expires in 60 minutes. If you didn\'t request it, you can safely ignore this email — your password won\'t change.',
        'button' => 'Reset password',
    ],

    'email_verification' => [
        'subject' => 'Confirm your :brand email address',
        'heading' => 'Confirm your email',
        'body' => 'Welcome to :brand. Confirm this is your email address to finish securing your account. This link is single-use and expires in 24 hours.',
        'button' => 'Confirm email address',
    ],

    'admin_assigned_password' => [
        'subject' => 'Your :brand password has been reset',
        'heading' => 'Your password has been reset',
        'body' => 'An administrator set a new password on your account. Sign in with it below.',
        'temporary' => 'You\'ll be asked to choose your own password straight away.',
        'expires' => 'This password stops working on :date, so please sign in before then.',
        'not_expected' => 'If you weren\'t expecting this, contact your administrator — someone with access to your organisation\'s console made this change, and it is recorded on the audit trail.',
    ],

    'invitation' => [
        'subject' => ':inviter invited you to join :organization',
        'heading' => 'Join :organization',
        'invited' => ':inviter invited you to join :organization.',
        'invited_as' => ':inviter invited you to join :organization as :role.',
        'accept_app' => 'Accept to sign in to :app with your :organization account.',
        'accept' => 'Accept to set up your account and sign in.',
        'button' => 'Review invitation',
        'note' => 'The link opens a page where you confirm — nothing happens until you do. It expires in 7 days. If you weren\'t expecting this, you can ignore it.',
    ],

    'organization_invite' => [
        'subject' => ':inviter invited you to administer :organization on :brand',
        'heading' => 'Help run :organization on :brand',
        'invited' => ':inviter invited you to administer :organization on :brand — the console for its identity providers: environments, members and billing. Accept to set a password and sign in.',
        'invited_as' => ':inviter invited you to administer :organization as :role on :brand — the console for its identity providers: environments, members and billing. Accept to set a password and sign in.',
        'button' => 'Accept invitation',
    ],
];
