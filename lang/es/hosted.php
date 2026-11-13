<?php

declare(strict_types=1);

return [
    'layout' => [
        'secured_by' => 'Protegido por :name',
        'theme' => 'Tema',
        'toggle_theme' => 'Cambiar entre tema claro y oscuro',
        'about' => 'Sobre este producto',
        'hero_body' => 'SSO empresarial, sincronización de directorios SCIM, MFA y claves de acceso, RBAC y un registro de auditoría que permite detectar alteraciones — puedes alojarlo en tu propia infraestructura y mantener el control.',
        'features' => [
            'sso' => 'Inicio de sesión único con SAML y OIDC',
            'scim' => 'Aprovisionamiento de directorios con SCIM 2.0',
            'mfa' => 'Claves de acceso, TOTP y enlaces de acceso de un solo uso',
            'audit' => 'Registro de auditoría con hashes encadenados que permite detectar alteraciones',
        ],
        'hero_title' => 'Una capa de identidad para todas tus aplicaciones.',
    ],
    'language' => [
        'label' => 'Idioma',
    ],

    'theme' => [
        'system' => 'Sistema',
        'light' => 'Claro',
        'dark' => 'Oscuro',
    ],
    'skip_to_content' => 'Saltar al contenido',

    // The page an Authenticator link shows when the app did not open.
    'authenticator_link' => [
        'title' => 'Abrir la app Cbox ID',
        'lead' => 'La app Cbox ID no se abrió en este dispositivo.',
        'other_phone' => '¿Tienes la app en otro teléfono? Aprueba la solicitud allí y vuelve a la página de inicio de sesión. Continuará sola.',
        'open' => 'Abrir en la app',
        'install' => 'Obtener la app',
        'back' => 'O vuelve atrás y elige otra forma de confirmar.',
    ],
];
