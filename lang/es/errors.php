<?php

declare(strict_types=1);

return [
    'locale_conflict' => 'Tus ajustes o la cuenta con la que has iniciado sesión han cambiado. Vuelve a cargar la página y selecciona el idioma de nuevo.',
    'layout' => [
        'default_title' => 'Error',
        'default_heading' => 'Algo salió mal',
        'default_message' => 'Se ha producido un error inesperado. Inténtalo de nuevo.',
        'home' => 'Volver al panel principal',
        'reload' => 'Recargar',
        'share_trace' => 'Comparte este identificador con el equipo de soporte para ayudarnos a averiguar qué ocurrió.',
        'copy_trace' => 'Copiar ID de seguimiento',
        'trace_id' => 'ID de seguimiento',
        'copied' => 'Copiado',
    ],
    403 => [
        'title' => 'Acceso denegado',
        'message' => 'No tienes permiso para ver esta página. Si crees que se trata de un error, contacta con tu administrador.',
    ],
    404 => [
        'title' => 'Página no encontrada',
        'message' => 'No encontramos la página que buscas. Puede que se haya movido o que ya no exista.',
    ],
    419 => [
        'title' => 'Tu sesión ha expirado',
        'message' => 'Por tu seguridad, se ha cerrado la sesión tras un período de inactividad. Recarga la página para continuar.',
    ],
    429 => [
        'title' => 'Demasiadas solicitudes',
        'message' => 'Has enviado muchas solicitudes en poco tiempo. Espera un momento e inténtalo de nuevo.',
    ],
    500 => [
        'title' => 'Algo salió mal',
        'message' => 'Se ha producido un error inesperado en nuestro sistema. Hemos avisado al equipo. Recargar la página suele ayudar.',
    ],
    503 => [
        'title' => 'En mantenimiento',
        'message' => ':brand no está disponible temporalmente por mantenimiento. Inténtalo de nuevo en unos momentos.',
    ],
];
