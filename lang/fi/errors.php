<?php

declare(strict_types=1);

// Translation provenance and review scope: docs/guides/languages.md.
return [
    'layout' => [
        'default_title' => 'Virhe',
        'default_heading' => 'Jokin meni pieleen',
        'default_message' => 'Tapahtui odottamaton virhe. Yritä uudelleen.',
        'home' => 'Takaisin kojelautaan',
        'reload' => 'Lataa uudelleen',
        'share_trace' => 'Jaa tämä tueksi, jotta voimme seurata tapahtunutta.',
        'copy_trace' => 'Kopioi jäljitystunnus',
        'trace_id' => 'Trace ID',
        'copied' => 'Kopioitu',
    ],
    '403' => [
        'title' => 'Pääsy estetty',
        'message' => 'Sinulla ei ole lupaa katsoa tätä sivua. Jos olet sitä mieltä, että tämä on virhe, ota yhteyttä ylläpitäjääsi.',
    ],
    '404' => [
        'title' => 'Sivua ei löytynyt',
        'message' => 'Emme löytäneet etsimääsi sivua. Se on saattanut liikkua tai olla enää olemassa.',
    ],
    '419' => [
        'title' => 'Istuntosi päättyi',
        'message' => 'Turvallisuutesi vuoksi sinut kirjattiin ulos, kun olet ollut toimeton. Lataa sivu uudelleen, jotta voit jatkaa.',
    ],
    '429' => [
        'title' => 'Liikaa pyyntöjä',
        'message' => 'Olet tehnyt paljon pyyntöjä lyhyessä ajassa. Odota hetki, yritä sitten uudelleen.',
    ],
    '500' => [
        'title' => 'Jokin meni pieleen',
        'message' => 'Meidän puolellamme tapahtui odottamaton virhe. Tiimille on ilmoitettu – uudelleenlataus auttaa yleensä.',
    ],
    '503' => [
        'title' => 'Alas huoltoon',
        'message' => ':brand ei ole käytettävissä, kun hoidamme huollon. Yritä uudelleen hetken kuluttua.',
    ],
];
