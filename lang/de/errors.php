<?php

declare(strict_types=1);

// German: the Blade error pages. :brand is the deployment's configured product name.
return [
    'layout' => [
        'default_title' => 'Fehler',
        'default_heading' => 'Etwas ist schiefgelaufen',
        'default_message' => 'Ein unerwarteter Fehler ist aufgetreten. Bitte versuchen Sie es erneut.',
        'home' => 'Zurück zum Dashboard',
        'reload' => 'Neu laden',
        'share_trace' => 'Teilen Sie diese ID mit dem Support, damit wir nachvollziehen können, was passiert ist.',
        'copy_trace' => 'Trace-ID kopieren',
        'trace_id' => 'Trace-ID',
        'copied' => 'Kopiert',
    ],

    '403' => [
        'title' => 'Zugriff verweigert',
        'message' => 'Sie haben keine Berechtigung, diese Seite anzuzeigen. Wenn Sie glauben, dass es sich um einen Fehler handelt, wenden Sie sich an Ihren Administrator.',
    ],

    '404' => [
        'title' => 'Seite nicht gefunden',
        'message' => 'Die gesuchte Seite wurde nicht gefunden. Möglicherweise wurde sie verschoben oder existiert nicht mehr.',
    ],

    '419' => [
        'title' => 'Ihre Sitzung ist abgelaufen',
        'message' => 'Zu Ihrer Sicherheit wurden Sie nach einer Zeit der Inaktivität abgemeldet. Laden Sie die Seite neu, um fortzufahren.',
    ],

    '429' => [
        'title' => 'Zu viele Anfragen',
        'message' => 'Sie haben in kurzer Zeit sehr viele Anfragen gesendet. Warten Sie einen Moment, und versuchen Sie es dann erneut.',
    ],

    '500' => [
        'title' => 'Etwas ist schiefgelaufen',
        'message' => 'Auf unserer Seite ist ein unerwarteter Fehler aufgetreten. Das Team wurde benachrichtigt – ein erneutes Laden der Seite hilft meist.',
    ],

    '503' => [
        'title' => 'Wartungsarbeiten',
        'message' => ':brand ist wegen Wartungsarbeiten kurzzeitig nicht verfügbar. Bitte versuchen Sie es gleich noch einmal.',
    ],
];
