<?php

declare(strict_types=1);

return [
    'locale_conflict' => 'Asetuksesi tai kirjautumistilisi on muuttunut. Lataa sivu uudelleen ja valitse kieli uudestaan.',
    'layout' => [
        'default_title' => 'Virhe',
        'default_heading' => 'Jokin meni pieleen',
        'default_message' => 'Tapahtui odottamaton virhe. Yritä uudelleen.',
        'home' => 'Takaisin yleiskatsaukseen',
        'reload' => 'Lataa uudelleen',
        'share_trace' => 'Lähetä tämä tunnus tukeen, jotta voimme selvittää, mitä tapahtui.',
        'copy_trace' => 'Kopioi seurantatunnus',
        'trace_id' => 'Seurantatunnus',
        'copied' => 'Kopioitu',
    ],
    403 => [
        'title' => 'Pääsy estetty',
        'message' => 'Sinulla ei ole oikeutta nähdä tätä sivua. Jos kyseessä on mielestäsi virhe, ota yhteyttä ylläpitäjään.',
    ],
    404 => [
        'title' => 'Sivua ei löytynyt',
        'message' => 'Etsimääsi sivua ei löytynyt. Se on saatettu siirtää tai poistaa.',
    ],
    419 => [
        'title' => 'Istuntosi on päättynyt',
        'message' => 'Sinut kirjattiin turvallisuussyistä ulos, koska et ollut hetkeen aktiivinen. Jatka lataamalla sivu uudelleen.',
    ],
    429 => [
        'title' => 'Liian monta pyyntöä',
        'message' => 'Lähetit monta pyyntöä lyhyessä ajassa. Odota hetki ja yritä sitten uudelleen.',
    ],
    500 => [
        'title' => 'Jokin meni pieleen',
        'message' => 'Palvelussamme tapahtui odottamaton virhe. Tiimille on ilmoitettu asiasta. Sivun lataaminen uudelleen yleensä auttaa.',
    ],
    503 => [
        'title' => 'Huoltotyöt käynnissä',
        'message' => ':brand on tilapäisesti poissa käytöstä huollon vuoksi. Yritä hetken kuluttua uudelleen.',
    ],
];
