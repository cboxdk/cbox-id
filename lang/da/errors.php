<?php

declare(strict_types=1);

// Danish: the error pages (see lang/en/errors.php). `:brand` is the configured product name.
return [
    'layout' => [
        'default_title' => 'Fejl',
        'default_heading' => 'Noget gik galt',
        'default_message' => 'Der opstod en uventet fejl. Prøv igen.',
        'home' => 'Tilbage til oversigten',
        'reload' => 'Genindlæs',
        'share_trace' => 'Send dette til support, så vi kan finde ud af hvad der skete.',
        'copy_trace' => 'Kopier sporings-id',
        'trace_id' => 'Sporings-id',
        'copied' => 'Kopieret',
    ],

    '403' => [
        'title' => 'Adgang nægtet',
        'message' => 'Du har ikke tilladelse til at se denne side. Hvis du mener at det er en fejl, så kontakt din administrator.',
    ],

    '404' => [
        'title' => 'Siden blev ikke fundet',
        'message' => 'Vi kunne ikke finde den side du ledte efter. Den er måske flyttet eller findes ikke længere.',
    ],

    '419' => [
        'title' => 'Din session er udløbet',
        'message' => 'Af sikkerhedshensyn er du blevet logget ud efter en periode uden aktivitet. Genindlæs siden for at fortsætte.',
    ],

    '429' => [
        'title' => 'For mange anmodninger',
        'message' => 'Du har sendt mange anmodninger på kort tid. Vent lidt, og prøv så igen.',
    ],

    '500' => [
        'title' => 'Noget gik galt',
        'message' => 'Der opstod en uventet fejl hos os, og vi har fået besked. Det hjælper som regel at genindlæse siden.',
    ],

    '503' => [
        'title' => 'Lukket for vedligeholdelse',
        'message' => ':brand er kortvarigt utilgængelig på grund af vedligeholdelse. Prøv igen om lidt.',
    ],
];
