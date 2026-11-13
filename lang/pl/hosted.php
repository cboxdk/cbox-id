<?php

declare(strict_types=1);

return [
    'layout' => [
        'secured_by' => 'Zabezpieczone przez :name',
        'theme' => 'Motyw',
        'toggle_theme' => 'Włącz jasny lub ciemny motyw',
        'about' => 'O tym produkcie',
        'hero_body' => 'SSO dla firm, synchronizacja katalogów SCIM, MFA i klucze dostępu, RBAC oraz dziennik audytu pozwalający wykryć zmiany — do samodzielnego hostowania, pod Twoją kontrolą.',
        'features' => [
            'sso' => 'Jednokrotne logowanie przez SAML i OIDC',
            'scim' => 'Automatyczne zarządzanie kontami przez SCIM 2.0',
            'mfa' => 'Klucze dostępu, TOTP i jednorazowe linki do logowania',
            'audit' => 'Dziennik audytu z łańcuchem skrótów, umożliwiający wykrycie zmian',
        ],
        'hero_title' => 'Jedna warstwa tożsamości dla wszystkich Twoich aplikacji.',
    ],
    'language' => [
        'label' => 'Język',
    ],

    'theme' => [
        'system' => 'System',
        'light' => 'Jasny',
        'dark' => 'Ciemny',
    ],
    'skip_to_content' => 'Przejdź do treści',

    // The page an Authenticator link shows when the app did not open.
    'authenticator_link' => [
        'title' => 'Otwórz aplikację Cbox ID',
        'lead' => 'Aplikacja Cbox ID nie otworzyła się na tym urządzeniu.',
        'other_phone' => 'Aplikacja jest na innym telefonie? Zatwierdź żądanie tam, a potem wróć do strony logowania. Przejdzie dalej sama.',
        'open' => 'Otwórz w aplikacji',
        'install' => 'Pobierz aplikację',
        'back' => 'Albo wróć i wybierz inny sposób potwierdzenia.',
    ],
];
