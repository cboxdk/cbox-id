<?php

declare(strict_types=1);

return [
    'locale_conflict' => 'Je instellingen of het account waarmee je bent ingelogd zijn gewijzigd. Vernieuw de pagina en kies opnieuw een taal.',
    'layout' => [
        'default_title' => 'Fout',
        'default_heading' => 'Er is iets misgegaan',
        'default_message' => 'Er is een onverwachte fout opgetreden. Probeer het opnieuw.',
        'home' => 'Terug naar het dashboard',
        'reload' => 'Opnieuw laden',
        'share_trace' => 'Deel deze ID met support, zodat we kunnen achterhalen wat er is gebeurd.',
        'copy_trace' => 'Trace-ID kopiëren',
        'trace_id' => 'Trace-ID',
        'copied' => 'Gekopieerd',
    ],
    403 => [
        'title' => 'Toegang geweigerd',
        'message' => 'Je hebt geen toestemming om deze pagina te bekijken. Neem contact op met je beheerder als je denkt dat dit een fout is.',
    ],
    404 => [
        'title' => 'Pagina niet gevonden',
        'message' => 'We kunnen de gezochte pagina niet vinden. Deze is mogelijk verplaatst of bestaat niet meer.',
    ],
    419 => [
        'title' => 'Je sessie is verlopen',
        'message' => 'Voor je veiligheid ben je uitgelogd omdat je een tijd niet actief was. Laad de pagina opnieuw om verder te gaan.',
    ],
    429 => [
        'title' => 'Te veel verzoeken',
        'message' => 'Je hebt in korte tijd veel verzoeken verstuurd. Wacht even en probeer het opnieuw.',
    ],
    500 => [
        'title' => 'Er is iets misgegaan',
        'message' => 'Er is een onverwachte fout in ons systeem opgetreden. Het team is op de hoogte gebracht. De pagina opnieuw laden helpt meestal.',
    ],
    503 => [
        'title' => 'Onderhoud',
        'message' => ':brand is tijdelijk niet beschikbaar vanwege onderhoud. Probeer het over enkele ogenblikken opnieuw.',
    ],
];
