<?php

declare(strict_types=1);

// Swedish: the error pages (see lang/en/errors.php).
return [
    'layout' => [
        'default_title' => 'Fel',
        'default_heading' => 'Något gick fel',
        'default_message' => 'Ett oväntat fel inträffade. Försök igen.',
        'home' => 'Tillbaka till översikten',
        'reload' => 'Ladda om',
        'share_trace' => 'Dela det här med supporten så att vi kan spåra vad som hände.',
        'copy_trace' => 'Kopiera spårnings-ID',
        'trace_id' => 'Spårnings-ID',
        'copied' => 'Kopierat',
    ],

    '403' => [
        'title' => 'Åtkomst nekad',
        'message' => 'Du har inte behörighet att visa den här sidan. Om du tror att det är fel, kontakta din administratör.',
    ],

    '404' => [
        'title' => 'Sidan hittades inte',
        'message' => 'Vi kunde inte hitta sidan du letade efter. Den kan ha flyttats eller finns inte längre.',
    ],

    '419' => [
        'title' => 'Din session har gått ut',
        'message' => 'Av säkerhetsskäl loggades du ut efter en tids inaktivitet. Ladda om sidan för att fortsätta.',
    ],

    '429' => [
        'title' => 'För många förfrågningar',
        'message' => 'Du har gjort många förfrågningar på kort tid. Vänta en stund och försök sedan igen.',
    ],

    '500' => [
        'title' => 'Något gick fel',
        'message' => 'Ett oväntat fel inträffade hos oss. Teamet har meddelats — det brukar hjälpa att ladda om sidan.',
    ],

    '503' => [
        'title' => 'Underhåll pågår',
        'message' => ':brand är tillfälligt otillgängligt medan vi utför underhåll. Försök igen om en stund.',
    ],
];
