<?php

declare(strict_types=1);

return [
    'layout' => [
        'hero_title' => 'Jedna vrstva identity pre všetky vaše aplikácie.',
        'secured_by' => 'Zabezpečenie: :name',
        'theme' => 'Motív',
        'toggle_theme' => 'Prepnúť medzi svetlým a tmavým motívom',
        'about' => 'O tomto produkte',
        'hero_body' => 'Podnikové SSO, synchronizácia adresárov SCIM, MFA a prístupové kľúče, RBAC a auditný protokol umožňujúci odhaliť zmeny — prevádzkovateľný na vlastnej infraštruktúre, pod vašou kontrolou.',
        'features' => [
            'sso' => 'Jednotné prihlásenie cez SAML a OIDC',
            'scim' => 'Správa účtov v adresári cez SCIM 2.0',
            'mfa' => 'Prístupové kľúče, TOTP a jednorazové prihlasovacie odkazy',
            'audit' => 'Auditný protokol s reťazcom hashov na odhalenie zmien',
        ],
    ],
    'language' => [
        'label' => 'Jazyk',
    ],

    'theme' => [
        'system' => 'Systém',
        'light' => 'Svetlý',
        'dark' => 'Tmavý',
    ],
    'skip_to_content' => 'Prejsť na obsah',

    // The page an Authenticator link shows when the app did not open.
    'authenticator_link' => [
        'title' => 'Otvoriť aplikáciu Cbox ID',
        'lead' => 'Aplikácia Cbox ID sa na tomto zariadení neotvorila.',
        'other_phone' => 'Máte aplikáciu v inom telefóne? Schváľte žiadosť tam a potom sa vráťte na prihlasovaciu stránku. Bude pokračovať sama.',
        'open' => 'Otvoriť v aplikácii',
        'install' => 'Stiahnuť aplikáciu',
        'back' => 'Alebo sa vráťte a zvoľte iný spôsob potvrdenia.',
    ],
];
