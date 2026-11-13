<?php

declare(strict_types=1);

return [
    'layout' => [
        'hero_title' => 'Une couche d’identité pour toutes vos applications.',
        'secured_by' => 'Sécurisé par :name',
        'theme' => 'Thème',
        'toggle_theme' => 'Basculer entre thème clair et sombre',
        'about' => 'À propos de ce produit',
        'hero_body' => 'SSO d’entreprise, synchronisation d’annuaire SCIM, MFA et clés d’accès, RBAC et journal d’audit permettant de détecter toute altération. Auto-hébergeable, sous votre contrôle.',
        'features' => [
            'sso' => 'Authentification unique avec SAML et OIDC',
            'scim' => 'Provisionnement d’annuaire SCIM 2.0',
            'mfa' => 'Clés d’accès, TOTP et liens de connexion',
            'audit' => 'Journal d’audit chaîné par hachage, avec détection des altérations',
        ],
    ],
    'language' => [
        'label' => 'Langue',
    ],

    'theme' => [
        'system' => 'Système',
        'light' => 'Clair',
        'dark' => 'Sombre',
    ],
    'skip_to_content' => 'Aller au contenu',

    // The page an Authenticator link shows when the app did not open.
    'authenticator_link' => [
        'title' => 'Ouvrir l’app Cbox ID',
        'lead' => 'L’app Cbox ID ne s’est pas ouverte sur cet appareil.',
        'other_phone' => 'L’app est sur un autre téléphone ? Approuvez la demande là-bas, puis revenez à la page de connexion. Elle continue toute seule.',
        'open' => 'Ouvrir dans l’app',
        'install' => 'Obtenir l’app',
        'back' => 'Ou revenez en arrière et choisissez une autre façon de confirmer.',
    ],
];
