<?php

declare(strict_types=1);

/*
 * What an approval push says on the phone's lock screen. Never an email, an address or a
 * comparison code: see Cbox\Id\Devices\Support\ApprovalPushText. Every language carries
 * exactly these keys and placeholders (DevicesLangParityTest).
 */
return [
    'sign_in_to' => 'Sign in to :app?',
    'sign_in' => 'Confirm sign-in',
    'step_up' => 'Confirm security access',
    'action' => 'Approve: :action',
    'action_generic' => 'Approve an action',
    'authentication' => ':app is asking for your approval',
    'authentication_generic' => 'Approval request',
    'device' => ':browser on :platform',
    'expires_in' => 'expires in :minutes min',
    'review' => 'Tap to review',
];
