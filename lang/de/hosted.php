<?php

declare(strict_types=1);

return [
    'layout' => [
        'hero_title' => 'Eine Identitätsebene für alle Ihre Apps.',
        'secured_by' => 'Geschützt durch :name',
        'theme' => 'Design',
        'toggle_theme' => 'Zwischen hellem und dunklem Design wechseln',
        'about' => 'Über dieses Produkt',
        'hero_body' => 'Enterprise-SSO, SCIM-Verzeichnissynchronisierung, MFA und Passkeys, RBAC sowie ein Audit-Trail mit Manipulationserkennung – selbst hostbar und ganz in Ihrer Hand.',
        'features' => [
            'sso' => 'Single Sign-On (SSO) mit SAML und OIDC',
            'scim' => 'Verzeichnisbereitstellung mit SCIM 2.0',
            'mfa' => 'Passkeys, TOTP und Anmeldelinks',
            'audit' => 'Hash-verkettetes Audit-Log mit Manipulationserkennung',
        ],
    ],
    'language' => [
        'label' => 'Sprache',
    ],

    'theme' => [
        'system' => 'System',
        'light' => 'Hell',
        'dark' => 'Dunkel',
    ],
    'skip_to_content' => 'Zum Inhalt springen',

    // The page an Authenticator link shows when the app did not open.
    'authenticator_link' => [
        'title' => 'Cbox ID-App öffnen',
        'lead' => 'Die Cbox ID-App wurde auf diesem Gerät nicht geöffnet.',
        'other_phone' => 'Ist die App auf einem anderen Telefon? Bestätigen Sie die Anfrage dort und kehren Sie dann zur Anmeldeseite zurück. Sie fährt von selbst fort.',
        'open' => 'In der App öffnen',
        'install' => 'App herunterladen',
        'back' => 'Oder gehen Sie zurück und wählen Sie eine andere Bestätigungsmethode.',
    ],
];
