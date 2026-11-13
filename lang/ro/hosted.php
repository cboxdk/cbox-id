<?php

declare(strict_types=1);

return [
    'layout' => [
        'secured_by' => 'Protejat de :name',
        'theme' => 'Temă',
        'toggle_theme' => 'Comută între tema luminoasă și cea întunecată',
        'about' => 'Despre acest produs',
        'hero_body' => 'SSO pentru companii, sincronizarea directoarelor SCIM, MFA și chei de acces, RBAC și un jurnal de audit care permite detectarea modificărilor — găzduit pe propria infrastructură, sub controlul tău.',
        'features' => [
            'sso' => 'Autentificare unică prin SAML și OIDC',
            'scim' => 'Gestionarea automată a conturilor prin SCIM 2.0',
            'mfa' => 'Chei de acces, TOTP și linkuri de autentificare de unică folosință',
            'audit' => 'Jurnal de audit cu hashuri înlănțuite, care permite detectarea modificărilor',
        ],
        'hero_title' => 'Un sistem de identitate pentru toate aplicațiile tale.',
    ],
    'language' => [
        'label' => 'Limbă',
    ],

    'theme' => [
        'system' => 'Sistem',
        'light' => 'Luminos',
        'dark' => 'Întunecat',
    ],
    'skip_to_content' => 'Sari la conținut',

    // The page an Authenticator link shows when the app did not open.
    'authenticator_link' => [
        'title' => 'Deschide aplicația Cbox ID',
        'lead' => 'Aplicația Cbox ID nu s-a deschis pe acest dispozitiv.',
        'other_phone' => 'Aplicația este pe alt telefon? Aprobă solicitarea acolo, apoi revino la pagina de autentificare. Continuă singură.',
        'open' => 'Deschide în aplicație',
        'install' => 'Descarcă aplicația',
        'back' => 'Sau revino și alege altă metodă de confirmare.',
    ],
];
