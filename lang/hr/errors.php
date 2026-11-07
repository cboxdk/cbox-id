<?php

declare(strict_types=1);

return [
    'locale_conflict' => 'Vaše postavke ili račun na koji ste prijavljeni promijenili su se. Ponovno učitajte stranicu i ponovno odaberite jezik.',
    'layout' => [
        'default_title' => 'Pogreška',
        'default_heading' => 'Dogodila se pogreška',
        'default_message' => 'Došlo je do neočekivane pogreške. Pokušajte ponovno.',
        'home' => 'Povratak na nadzornu ploču',
        'reload' => 'Ponovno učitaj',
        'share_trace' => 'Pošaljite ovaj identifikator podršci kako bismo mogli utvrditi što se dogodilo.',
        'copy_trace' => 'Kopiraj ID praćenja',
        'trace_id' => 'ID praćenja',
        'copied' => 'Kopirano',
    ],
    403 => [
        'title' => 'Pristup zabranjen',
        'message' => 'Nemate dopuštenje za pregled ove stranice. Ako mislite da je riječ o pogrešci, obratite se administratoru.',
    ],
    404 => [
        'title' => 'Stranica nije pronađena',
        'message' => 'Nismo pronašli stranicu koju tražite. Možda je premještena ili više ne postoji.',
    ],
    419 => [
        'title' => 'Vaša sesija je istekla',
        'message' => 'Iz sigurnosnih razloga odjavljeni ste nakon razdoblja neaktivnosti. Ponovno učitajte stranicu za nastavak.',
    ],
    429 => [
        'title' => 'Previše zahtjeva',
        'message' => 'Poslali ste mnogo zahtjeva u kratkom vremenu. Pričekajte trenutak pa pokušajte ponovno.',
    ],
    500 => [
        'title' => 'Dogodila se pogreška',
        'message' => 'Došlo je do neočekivane pogreške u našem sustavu. Tim je obaviješten. Ponovno učitavanje stranice obično pomaže.',
    ],
    503 => [
        'title' => 'Održavanje u tijeku',
        'message' => ':brand je privremeno nedostupan zbog održavanja. Pokušajte ponovno za nekoliko trenutaka.',
    ],
];
