<?php

declare(strict_types=1);

return [
    'layout' => [
        'secured_by' => 'Protegido por :name',
        'theme' => 'Tema',
        'toggle_theme' => 'Alternar entre o tema claro e o escuro',
        'about' => 'Sobre este produto',
        'hero_body' => 'SSO para empresas, sincronização de diretórios SCIM, MFA e chaves de acesso, RBAC e um registo de auditoria que permite detetar alterações — pode alojá-lo na sua própria infraestrutura e mantê-lo sob o seu controlo.',
        'features' => [
            'sso' => 'Início de sessão único com SAML e OIDC',
            'scim' => 'Aprovisionamento de diretórios com SCIM 2.0',
            'mfa' => 'Chaves de acesso, TOTP e ligações de início de sessão de utilização única',
            'audit' => 'Registo de auditoria com hashes encadeados que permite detetar alterações',
        ],
        'hero_title' => 'Uma camada de identidade para todas as suas aplicações.',
    ],
    'language' => [
        'label' => 'Idioma',
    ],

    'theme' => [
        'system' => 'Sistema',
        'light' => 'Claro',
        'dark' => 'Escuro',
    ],
    'skip_to_content' => 'Saltar para o conteúdo',

    // The page an Authenticator link shows when the app did not open.
    'authenticator_link' => [
        'title' => 'Abrir a app Cbox ID',
        'lead' => 'A app Cbox ID não abriu neste dispositivo.',
        'other_phone' => 'A app está noutro telemóvel? Aprove o pedido lá e volte à página de início de sessão. Ela continua sozinha.',
        'open' => 'Abrir na app',
        'install' => 'Obter a app',
        'back' => 'Ou volte atrás e escolha outra forma de confirmar.',
    ],
];
