<?php

declare(strict_types=1);

return [
    'layout' => [
        'secured_by' => 'Biztonságos belépés: :name',
        'theme' => 'Téma',
        'toggle_theme' => 'Váltás a világos és sötét téma között',
        'about' => 'A termékről',
        'hero_body' => 'Vállalati SSO, SCIM-címtárszinkronizálás, MFA és hozzáférési kulcsok, RBAC, valamint a módosításokat kimutathatóvá tevő auditnapló — saját infrastruktúrán is futtatható, a te irányításoddal.',
        'features' => [
            'sso' => 'Egyszeri bejelentkezés SAML és OIDC használatával',
            'scim' => 'Címtárfiókok automatikus kezelése SCIM 2.0-val',
            'mfa' => 'Hozzáférési kulcsok, TOTP és egyszer használható bejelentkezési hivatkozások',
            'audit' => 'Hashláncra épülő auditnapló, amelyből kimutathatók a módosítások',
        ],
        'hero_title' => 'Egyetlen identitáskezelési réteg minden alkalmazásodhoz.',
    ],
    'language' => [
        'label' => 'Nyelv',
    ],

    'theme' => [
        'system' => 'Rendszer',
        'light' => 'Világos',
        'dark' => 'Sötét',
    ],
    'skip_to_content' => 'Ugrás a tartalomra',

    // The page an Authenticator link shows when the app did not open.
    'authenticator_link' => [
        'title' => 'A Cbox ID alkalmazás megnyitása',
        'lead' => 'A Cbox ID alkalmazás nem nyílt meg ezen az eszközön.',
        'other_phone' => 'Másik telefonon van az alkalmazás? Ott hagyja jóvá a kérést, majd térjen vissza a bejelentkezési oldalra. Magától folytatódik.',
        'open' => 'Megnyitás az alkalmazásban',
        'install' => 'Alkalmazás letöltése',
        'back' => 'Vagy lépjen vissza, és válasszon más megerősítési módot.',
    ],
];
