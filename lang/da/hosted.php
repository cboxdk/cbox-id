<?php

declare(strict_types=1);

// Danish: the chrome every hosted page shares (see lang/en/hosted.php).
return [
    'layout' => [
        'hero_title' => 'Ét identitetslag til alle dine apps.',
        'secured_by' => 'Sikret af :name',
        'theme' => 'Tema',
        'toggle_theme' => 'Skift mellem lyst og mørkt tema',
        'about' => 'Om dette produkt',
        'hero_body' => 'Enterprise-SSO, katalogsynkronisering med SCIM, MFA og adgangsnøgler, RBAC og en revisionslog der ikke kan ændres ubemærket. Kan hostes på egne servere – og er jeres.',
        'features' => [
            'sso' => 'Single sign-on med SAML og OIDC',
            'scim' => 'Katalogsynkronisering med SCIM 2.0',
            'mfa' => 'Adgangsnøgler, TOTP og loginlinks',
            'audit' => 'Hashkædet revisionslog der ikke kan ændres ubemærket',
        ],
    ],

    'language' => [
        'label' => 'Sprog',
    ],

    'theme' => [
        'system' => 'System',
        'light' => 'Lyst',
        'dark' => 'Mørkt',
    ],

    'skip_to_content' => 'Gå til indhold',

    // The page an Authenticator link shows when the app did not open.
    'authenticator_link' => [
        'title' => 'Åbn Cbox ID-appen',
        'lead' => 'Cbox ID-appen åbnede ikke på denne enhed.',
        'other_phone' => 'Har du appen på en anden telefon? Godkend anmodningen der, og gå så tilbage til login-siden. Den fortsætter af sig selv.',
        'open' => 'Åbn i appen',
        'install' => 'Hent appen',
        'back' => 'Eller gå tilbage, og vælg en anden måde at bekræfte på.',
    ],
];
