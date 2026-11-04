<?php

declare(strict_types=1);

// Translation provenance and review scope: docs/guides/languages.md.
return [
    'layout' => [
        'default_title' => 'Error',
        'default_heading' => 'Algo salió mal.',
        'default_message' => 'Ocurrió un error inesperado. Inténtelo de nuevo.',
        'home' => 'Volver al panel de mando',
        'reload' => 'Recargar',
        'share_trace' => 'Comparta esto con apoyo para ayudarnos a rastrear lo que pasó.',
        'copy_trace' => 'Copiar el identificador de traza',
        'trace_id' => 'Identificación de la traza',
        'copied' => 'Copiado',
    ],
    '403' => [
        'title' => 'Acceso denegado',
        'message' => 'Usted no tiene permiso para ver esta página. Si usted piensa que esto es un error, póngase en contacto con su administrador.',
    ],
    '404' => [
        'title' => 'Página no encontrada',
        'message' => 'No pudimos encontrar la página que estabas buscando. Puede que se haya movido o ya no exista.',
    ],
    '419' => [
        'title' => 'Su sesión expiró.',
        'message' => 'Por su seguridad fue retirado después de un período de inactividad. Recargar la página para continuar.',
    ],
    '429' => [
        'title' => 'Demasiadas peticiones',
        'message' => 'Has hecho muchas peticiones en poco tiempo. Espera un momento, luego inténtalo de nuevo.',
    ],
    '500' => [
        'title' => 'Algo salió mal.',
        'message' => 'Un error inesperado ocurrió de nuestro lado. El equipo ha sido notificado — la recarga por lo general ayuda.',
    ],
    '503' => [
        'title' => 'Abajo para mantenimiento',
        'message' => ':brand no está disponible brevemente mientras realizamos el mantenimiento. Pruebe de nuevo en un momento.',
    ],
];
