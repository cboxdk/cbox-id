<?php

declare(strict_types=1);

// Norwegian Bokmål: the error pages. `:brand` is the configured product name.
return [
    'layout' => [
        'default_title' => 'Feil',
        'default_heading' => 'Noe gikk galt',
        'default_message' => 'Det oppstod en uventet feil. Prøv igjen.',
        'home' => 'Tilbake til oversikten',
        'reload' => 'Last inn på nytt',
        'share_trace' => 'Del dette med kundestøtte, så kan vi spore hva som skjedde.',
        'copy_trace' => 'Kopier sporings-ID',
        'trace_id' => 'Sporings-ID',
        'copied' => 'Kopiert',
    ],

    '403' => [
        'title' => 'Ingen tilgang',
        'message' => 'Du har ikke tillatelse til å se denne siden. Hvis du mener at dette er en feil, tar du kontakt med administratoren din.',
    ],

    '404' => [
        'title' => 'Fant ikke siden',
        'message' => 'Vi fant ikke siden du lette etter. Den kan ha blitt flyttet eller finnes ikke lenger.',
    ],

    '419' => [
        'title' => 'Økten din har utløpt',
        'message' => 'Av sikkerhetshensyn ble du logget ut etter en periode uten aktivitet. Last inn siden på nytt for å fortsette.',
    ],

    '429' => [
        'title' => 'For mange forespørsler',
        'message' => 'Du har sendt mange forespørsler på kort tid. Vent litt, og prøv igjen.',
    ],

    '500' => [
        'title' => 'Noe gikk galt',
        'message' => 'Det oppstod en uventet feil hos oss. Teamet er varslet – det hjelper som regel å laste inn siden på nytt.',
    ],

    '503' => [
        'title' => 'Nede for vedlikehold',
        'message' => ':brand er utilgjengelig en kort stund mens vi utfører vedlikehold. Prøv igjen om litt.',
    ],
];
