<?php

declare(strict_types=1);

/*
 * THE CHROME EVERY HOSTED PAGE SHARES — the sign-in layout's footer and hero, the
 * language picker. Shipped to the browser with each hosted page group's own namespace
 * ({@see \App\Platform\Locale\HostedTranslations}); keys are typed in TypeScript from this
 * file by `php artisan i18n:types`.
 */
return [
    'layout' => [
        'hero_title' => 'One identity layer for every app you ship.',
        'secured_by' => 'Secured by :name',
        'theme' => 'Theme',
        'toggle_theme' => 'Toggle light or dark theme',
        'about' => 'About this product',
        'hero_body' => 'Enterprise SSO, SCIM directory sync, MFA and passkeys, RBAC, and a tamper-evident audit trail — self-hostable, and yours.',
        'features' => [
            'sso' => 'SAML & OIDC single sign-on',
            'scim' => 'SCIM 2.0 directory provisioning',
            'mfa' => 'Passkeys, TOTP, and magic links',
            'audit' => 'Hash-chained, tamper-evident audit',
        ],
    ],

    'language' => [
        'label' => 'Language',
    ],

    // The sign-in footer's theme choice — the account menu's three, in the same words.
    'theme' => [
        'system' => 'System',
        'light' => 'Light',
        'dark' => 'Dark',
    ],

    'skip_to_content' => 'Skip to content',
];
