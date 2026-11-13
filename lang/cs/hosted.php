<?php

declare(strict_types=1);

return [
    'layout' => [
        'secured_by' => 'Zabezpečení: :name',
        'theme' => 'Motiv',
        'toggle_theme' => 'Přepnout mezi světlým a tmavým motivem',
        'about' => 'O tomto produktu',
        'hero_body' => 'Podnikové SSO, synchronizace adresářů SCIM, MFA a přístupové klíče, RBAC a auditní protokol umožňující odhalit změny — provozovatelný na vlastní infrastruktuře, pod vaší kontrolou.',
        'features' => [
            'sso' => 'Jednotné přihlášení přes SAML a OIDC',
            'scim' => 'Správa účtů v adresáři přes SCIM 2.0',
            'mfa' => 'Přístupové klíče, TOTP a jednorázové přihlašovací odkazy',
            'audit' => 'Auditní protokol s řetězcem hashů pro odhalení změn',
        ],
        'hero_title' => 'Jedna vrstva identity pro všechny vaše aplikace.',
    ],
    'language' => [
        'label' => 'Jazyk',
    ],

    'theme' => [
        'system' => 'Systém',
        'light' => 'Světlý',
        'dark' => 'Tmavý',
    ],
    'skip_to_content' => 'Přeskočit na obsah',

    // The page an Authenticator link shows when the app did not open.
    'authenticator_link' => [
        'title' => 'Otevřít aplikaci Cbox ID',
        'lead' => 'Aplikace Cbox ID se na tomto zařízení neotevřela.',
        'other_phone' => 'Máte aplikaci v jiném telefonu? Schvalte žádost tam a pak se vraťte na přihlašovací stránku. Bude pokračovat sama.',
        'open' => 'Otevřít v aplikaci',
        'install' => 'Stáhnout aplikaci',
        'back' => 'Nebo se vraťte a zvolte jiný způsob potvrzení.',
    ],
];
