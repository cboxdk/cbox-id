<?php

declare(strict_types=1);

return [
    'layout' => [
        'hero_title' => 'Jedan sloj identiteta za sve vaše aplikacije.',
        'secured_by' => 'Zaštitu pruža :name',
        'theme' => 'Tema',
        'toggle_theme' => 'Prebaci između svijetle i tamne teme',
        'about' => 'O ovom proizvodu',
        'hero_body' => 'Poslovni SSO, sinkronizacija imenika SCIM, MFA i pristupni ključevi, RBAC te zapisnik revizije koji omogućuje otkrivanje izmjena — za smještaj na vlastitoj infrastrukturi, pod vašim nadzorom.',
        'features' => [
            'sso' => 'Jedinstvena prijava putem SAML-a i OIDC-a',
            'scim' => 'Upravljanje računima u imeniku putem SCIM 2.0',
            'mfa' => 'Pristupni ključevi, TOTP i jednokratne poveznice za prijavu',
            'audit' => 'Zapisnik revizije s povezanim sažecima za otkrivanje izmjena',
        ],
    ],
    'language' => [
        'label' => 'Jezik',
    ],

    'theme' => [
        'system' => 'Sustav',
        'light' => 'Svijetlo',
        'dark' => 'Tamno',
    ],
    'skip_to_content' => 'Preskoči na sadržaj',

    // The page an Authenticator link shows when the app did not open.
    'authenticator_link' => [
        'title' => 'Otvori aplikaciju Cbox ID',
        'lead' => 'Aplikacija Cbox ID nije se otvorila na ovom uređaju.',
        'other_phone' => 'Je li aplikacija na drugom telefonu? Ondje odobrite zahtjev, a zatim se vratite na stranicu za prijavu. Nastavit će sama.',
        'open' => 'Otvori u aplikaciji',
        'install' => 'Preuzmi aplikaciju',
        'back' => 'Ili se vratite i odaberite drugi način potvrde.',
    ],
];
