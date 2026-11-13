<?php

declare(strict_types=1);

return [
    'layout' => [
        'hero_title' => 'Ett identitetslager för alla dina appar.',
        'secured_by' => 'Skyddas av :name',
        'theme' => 'Tema',
        'toggle_theme' => 'Växla mellan ljust och mörkt tema',
        'about' => 'Om den här produkten',
        'hero_body' => 'SSO för företag, katalogsynkronisering med SCIM, MFA och lösennycklar, RBAC och en granskningslogg som avslöjar manipulering. Kan driftas i din egen infrastruktur, under din kontroll.',
        'features' => [
            'sso' => 'Single sign-on med SAML och OIDC',
            'scim' => 'Katalogprovisionering med SCIM 2.0',
            'mfa' => 'Lösennycklar, TOTP och inloggningslänkar',
            'audit' => 'Hashkedjad granskningslogg som avslöjar manipulering',
        ],
    ],
    'language' => [
        'label' => 'Språk',
    ],

    'theme' => [
        'system' => 'System',
        'light' => 'Ljust',
        'dark' => 'Mörkt',
    ],
    'skip_to_content' => 'Hoppa till innehållet',

    // The page an Authenticator link shows when the app did not open.
    'authenticator_link' => [
        'title' => 'Öppna Cbox ID-appen',
        'lead' => 'Cbox ID-appen öppnades inte på den här enheten.',
        'other_phone' => 'Finns appen på en annan telefon? Godkänn förfrågan där och gå sedan tillbaka till inloggningssidan. Den fortsätter av sig själv.',
        'open' => 'Öppna i appen',
        'install' => 'Hämta appen',
        'back' => 'Eller gå tillbaka och välj ett annat sätt att bekräfta.',
    ],
];
