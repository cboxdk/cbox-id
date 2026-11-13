<?php

declare(strict_types=1);

return [
    'layout' => [
        'hero_title' => 'Ett identitetslag for alle appene dine.',
        'secured_by' => 'Sikret av :name',
        'theme' => 'Tema',
        'toggle_theme' => 'Bytt mellom lyst og mørkt tema',
        'about' => 'Om dette produktet',
        'hero_body' => 'SSO for virksomheter, SCIM-katalogsynkronisering, MFA og passnøkler, RBAC og en revisjonslogg som avslører manipulering. Kan driftes på din egen infrastruktur, under din kontroll.',
        'features' => [
            'sso' => 'Single sign-on (SSO) med SAML og OIDC',
            'scim' => 'Katalogprovisjonering med SCIM 2.0',
            'mfa' => 'Passnøkler, TOTP og innloggingslenker',
            'audit' => 'Hashkjedet revisjonslogg som avslører manipulering',
        ],
    ],
    'language' => [
        'label' => 'Språk',
    ],

    'theme' => [
        'system' => 'System',
        'light' => 'Lys',
        'dark' => 'Mørk',
    ],
    'skip_to_content' => 'Gå til innholdet',

    // The page an Authenticator link shows when the app did not open.
    'authenticator_link' => [
        'title' => 'Åpne Cbox ID-appen',
        'lead' => 'Cbox ID-appen åpnet seg ikke på denne enheten.',
        'other_phone' => 'Har du appen på en annen telefon? Godkjenn forespørselen der, og gå så tilbake til påloggingssiden. Den fortsetter av seg selv.',
        'open' => 'Åpne i appen',
        'install' => 'Last ned appen',
        'back' => 'Eller gå tilbake og velg en annen måte å bekrefte på.',
    ],
];
