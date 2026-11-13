<?php

declare(strict_types=1);

return [
    'layout' => [
        'secured_by' => 'Beveiligd door :name',
        'theme' => 'Thema',
        'toggle_theme' => 'Wissel tussen het lichte en donkere thema',
        'about' => 'Over dit product',
        'hero_body' => 'SSO voor bedrijven, SCIM-directorysynchronisatie, MFA en passkeys, RBAC en een auditlog waarmee manipulatie kan worden opgespoord. Zelf te hosten, onder jouw beheer.',
        'features' => [
            'sso' => 'Eenmalige aanmelding met SAML en OIDC',
            'scim' => 'Accountbeheer via SCIM 2.0',
            'mfa' => 'Passkeys, TOTP en eenmalige inloglinks',
            'audit' => 'Auditlog met gekoppelde hashes voor het opsporen van manipulatie',
        ],
        'hero_title' => 'Eén identiteitslaag voor al je apps.',
    ],
    'language' => [
        'label' => 'Taal',
    ],

    'theme' => [
        'system' => 'Systeem',
        'light' => 'Licht',
        'dark' => 'Donker',
    ],
    'skip_to_content' => 'Ga naar de inhoud',

    // The page an Authenticator link shows when the app did not open.
    'authenticator_link' => [
        'title' => 'De Cbox ID-app openen',
        'lead' => 'De Cbox ID-app is niet geopend op dit apparaat.',
        'other_phone' => 'Staat de app op een andere telefoon? Keur het verzoek daar goed en ga dan terug naar de aanmeldpagina. Die gaat vanzelf verder.',
        'open' => 'Openen in de app',
        'install' => 'Download de app',
        'back' => 'Of ga terug en kies een andere manier om te bevestigen.',
    ],
];
